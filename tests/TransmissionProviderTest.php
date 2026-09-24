<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\TransmissionProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class TransmissionProviderTest extends TestCase
{
    public function testConnectionHandlesChallengeAndDiscoversAllLabels(): void
    {
        $longLabel = str_repeat('ä', 200);
        $transport = new TransmissionFixtureTransport(static function (HttpRequest $request) use ($longLabel): HttpResponse {
            self::assertSame('POST', $request->method);
            self::assertSame('https://transmission.test/proxy/transmission/rpc', $request->url);
            self::assertContains('Authorization: Basic ' . base64_encode('admin:secret'), $request->headers);
            $body = json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
            self::assertContains($body['method'], ['session-get', 'torrent-get']);
            if (!in_array('X-Transmission-Session-Id: csrf-123456789', $request->headers, true)) {
                return new HttpResponse(409, '', ['x-transmission-session-id' => ['csrf-123456789']]);
            }
            return self::rpc($body['method'] === 'session-get'
                ? ['version' => '4.1.3 (abc123)', 'rpc-version' => 19]
                : ['torrents' => [['labels' => ['tv', $longLabel]], ['labels' => ['tv', 'movies']]]]);
        });
        $provider = new TransmissionProvider($transport);
        $result = $provider->testConnection($this->connection('https://transmission.test/proxy'), ['username' => 'admin', 'secret' => 'secret']);
        self::assertSame('4.1.3 (abc123)', $result['version']);
        self::assertSame(['', 'movies', 'tv', $longLabel], $result['categories']);
        self::assertSame([], $result['tags']);
        self::assertCount(3, $transport->requests);
    }

    public function testCollectKeepsCheckingCurrentAndSeparatesSeedingFromActive(): void
    {
        $rows = [
            $this->torrent(1, ['status' => 4, 'percentDone' => 0.5, 'leftUntilDone' => 50, 'rateDownload' => 20, 'labels' => ['tv', 'linux']]),
            $this->torrent(2, ['status' => 2, 'percentDone' => 1, 'leftUntilDone' => 0, 'doneDate' => 500]),
            $this->torrent(3, ['status' => 6, 'percentDone' => 1, 'leftUntilDone' => 0, 'doneDate' => 600]),
            $this->torrent(4, ['status' => 4, 'percentDone' => 0.2, 'leftUntilDone' => 80, 'error' => 2, 'errorString' => 'Tracker unavailable']),
            $this->torrent(5, ['status' => 0, 'percentDone' => 0.1, 'leftUntilDone' => 90, 'error' => 3, 'errorString' => 'No space left on device']),
        ];
        $transport = new TransmissionFixtureTransport(static function (HttpRequest $request) use ($rows): HttpResponse {
            $body = json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
            self::assertContains('X-Transmission-Session-Id: saved-session', $request->headers);
            return self::rpc($body['method'] === 'session-stats'
                ? ['downloadSpeed' => 75]
                : ['torrents' => $rows]);
        });
        $provider = new TransmissionProvider($transport);
        $batch = $provider->collect($this->connection('http://transmission.test'), ['username' => '', 'secret' => ''], [], [
            'sid' => 'saved-session', 'endpoint_hash' => hash('sha256', 'http://transmission.test/transmission/rpc'),
        ]);
        self::assertSame(75.0, $batch->speed);
        self::assertTrue($batch->complete);
        self::assertSame([], $batch->cursor);
        self::assertCount(4, $batch->items);
        self::assertCount(1, $batch->completions);
        self::assertSame(['downloading', 'checking', 'downloading', 'error'], array_column($batch->items, 'state'));
        self::assertSame(str_repeat('0', 39) . '1', $batch->items[0]['source_id']);
        self::assertSame('tv', $batch->items[0]['category']);
        self::assertSame(['tv', 'linux'], $batch->items[0]['tags']);
        self::assertSame(20.0, $batch->items[0]['speed']);
        self::assertSame('completed', $batch->completions[0]['state']);
        self::assertSame(600, $batch->completions[0]['completed_at']);
        self::assertSame('saved-session', $batch->session['sid']);
    }

    public function testCurrentLimitMarksInventoryIncompleteDespiteLargeSeedingLibrary(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1050; ++$i) {
            $rows[] = $this->torrent($i, ['status' => 6, 'percentDone' => 1, 'leftUntilDone' => 0, 'doneDate' => $i]);
        }
        $rows[] = $this->torrent(1051, ['status' => 4]);
        $complete = $this->batch($rows);
        self::assertTrue($complete->complete);
        self::assertCount(1, $complete->items);
        self::assertCount(100, $complete->completions);
        for ($i = 1052; $i <= 2052; ++$i) {
            $rows[] = $this->torrent($i, ['status' => 4]);
        }
        $incomplete = $this->batch($rows);
        self::assertFalse($incomplete->complete);
        self::assertCount(1000, $incomplete->items);
    }

    public function testRejectsUnsupportedVersionAndBadSessionChallenge(): void
    {
        $transport = new TransmissionFixtureTransport(static fn (): HttpResponse => self::rpc(['version' => '3.00', 'rpc-version' => 16]));
        try {
            (new TransmissionProvider($transport))->testConnection($this->connection('http://transmission.test'), ['username' => '', 'secret' => '']);
            self::fail('Expected unsupported version');
        } catch (ProviderException $e) {
            self::assertSame('unsupported_version', $e->reason);
        }
        $transport = new TransmissionFixtureTransport(static fn (): HttpResponse => new HttpResponse(409, '', ['x-transmission-session-id' => ['bad\r\nheader']]));
        $this->expectException(ProviderException::class);
        (new TransmissionProvider($transport))->testConnection($this->connection('http://transmission.test'), ['username' => '', 'secret' => '']);
    }

    public function testMissingMeasurementsStayNullAndChallengeRetriesAreBounded(): void
    {
        $row = $this->torrent(1, ['status' => 4]);
        unset($row['percentDone'], $row['sizeWhenDone'], $row['leftUntilDone'], $row['rateDownload'], $row['eta']);
        $transport = new TransmissionFixtureTransport(static function (HttpRequest $request) use ($row): HttpResponse {
            $body = json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
            return self::rpc($body['method'] === 'session-stats' ? [] : ['torrents' => [$row]]);
        });
        $batch = (new TransmissionProvider($transport))->collect($this->connection('http://transmission.test'), ['username' => '', 'secret' => '']);
        self::assertNull($batch->speed);
        foreach (['progress', 'size', 'downloaded', 'speed', 'eta'] as $field) {
            self::assertNull($batch->items[0][$field]);
        }

        $counter = 0;
        $transport = new TransmissionFixtureTransport(static function () use (&$counter): HttpResponse {
            ++$counter;
            return new HttpResponse(409, '', ['x-transmission-session-id' => ['session-' . $counter]]);
        });
        try {
            (new TransmissionProvider($transport))->collect($this->connection('http://transmission.test'), ['username' => '', 'secret' => '']);
            self::fail('Expected bounded retry failure');
        } catch (ProviderException $e) {
            self::assertSame('invalid_response', $e->reason);
            self::assertSame(3, $counter);
        }
    }

    public function testSeedingWithoutMeasurementsIsCompleteButStorageErrorsStayCurrent(): void
    {
        $seeding = $this->torrent(1, ['status' => 6]);
        unset($seeding['percentDone'], $seeding['leftUntilDone'], $seeding['doneDate']);
        $failed = $this->torrent(2, ['status' => 0, 'percentDone' => 1, 'leftUntilDone' => 0, 'doneDate' => 600, 'error' => 3]);
        $batch = $this->batch([$seeding, $failed]);
        self::assertSame(['error'], array_column($batch->items, 'state'));
        self::assertSame(['completed'], array_column($batch->completions, 'state'));
        self::assertNull($batch->completions[0]['completed_at']);
    }

    public function testRecentLimitKeepsCompletionsMatchingSelectedLabels(): void
    {
        $rows = [$this->torrent(1, ['status' => 6, 'labels' => ['other', 'tv'], 'doneDate' => 1])];
        for ($i = 2; $i <= 105; ++$i) {
            $rows[] = $this->torrent($i, ['status' => 6, 'labels' => ['other'], 'doneDate' => $i]);
        }
        $transport = new TransmissionFixtureTransport(static function (HttpRequest $request) use ($rows): HttpResponse {
            $body = json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
            return self::rpc($body['method'] === 'session-stats' ? ['downloadSpeed' => 0] : ['torrents' => $rows]);
        });
        $connection = new Connection('selected', 'transmission', 'Selected', 'http://transmission.test', filterMode: 'selected', categories: ['tv']);
        $batch = (new TransmissionProvider($transport))->collect($connection, ['username' => '', 'secret' => '']);
        self::assertCount(1, $batch->completions);
        self::assertSame(['other', 'tv'], $batch->completions[0]['tags']);
    }

    public function testIntegerMeasurementsDoNotWrapAtThePlatformLimit(): void
    {
        $batch = $this->batch([
            $this->torrent(1, ['sizeWhenDone' => PHP_INT_MAX, 'leftUntilDone' => PHP_INT_MAX - 1]),
            $this->torrent(2, ['sizeWhenDone' => (float) PHP_INT_MAX, 'leftUntilDone' => 0]),
        ]);
        self::assertSame(PHP_INT_MAX, $batch->items[0]['size']);
        self::assertSame(1, $batch->items[0]['downloaded']);
        self::assertNull($batch->items[1]['size']);
    }

    /** @param list<array<string,mixed>> $rows */
    private function batch(array $rows): \Mk\Framework\Downloads\CollectionBatch
    {
        $transport = new TransmissionFixtureTransport(static function (HttpRequest $request) use ($rows): HttpResponse {
            $body = json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
            return self::rpc($body['method'] === 'session-stats' ? ['downloadSpeed' => 0] : ['torrents' => $rows]);
        });
        return (new TransmissionProvider($transport))->collect($this->connection('http://transmission.test'), ['username' => '', 'secret' => '']);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function torrent(int $id, array $changes = []): array
    {
        return array_replace([
            'hashString' => sprintf('%040x', $id), 'name' => 'Torrent ' . $id, 'status' => 3,
            'percentDone' => 0.0, 'sizeWhenDone' => 100, 'leftUntilDone' => 100,
            'rateDownload' => 0, 'eta' => -1, 'queuePosition' => 0, 'doneDate' => 0,
            'labels' => [], 'error' => 0, 'errorString' => '', 'isStalled' => false,
        ], $changes);
    }

    private function connection(string $url): Connection
    {
        return new Connection('transmission-id', 'transmission', 'Transmission', $url);
    }

    /** @param array<string,mixed> $arguments */
    private static function rpc(array $arguments): HttpResponse
    {
        return new HttpResponse(200, json_encode(['result' => 'success', 'arguments' => $arguments], JSON_THROW_ON_ERROR));
    }
}

final class TransmissionFixtureTransport implements DownloaderTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    private Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        return ($this->handler)($request);
    }

    public function requestMany(array $requests): array
    {
        return array_map(fn (HttpRequest $request): HttpResponse => $this->request($request), $requests);
    }
}
