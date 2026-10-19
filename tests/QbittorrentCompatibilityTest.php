<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\QbittorrentProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QbittorrentCompatibilityTest extends TestCase
{
    #[DataProvider('supportedVersions')]
    public function testSupportedVersionsAndLongSessionPassUsefulReads(string $app, string $api, string $expected, bool $probe): void
    {
        $transport = $this->transport($app, $api, inventory: [self::row(1, 0, 'tv', 0.25)]);
        $result = (new QbittorrentProvider($transport))->testConnection($this->connection(), $this->credentials());
        self::assertSame($expected, $result['version']);
        self::assertSame(['', 'tv'], $result['categories']);
        self::assertSame(['sonarr'], $result['tags']);
        $probes = array_values(array_filter($transport->requests, static fn (HttpRequest $request): bool => str_contains($request->url, '/torrents/info')));
        self::assertCount($probe ? 1 : 0, $probes);
        if ($probe) {
            parse_str((string) parse_url($probes[0]->url, PHP_URL_QUERY), $query);
            self::assertSame('1', $query['limit']);
        }
    }

    /** @return iterable<string,array{string,string,string,bool}> */
    public static function supportedVersions(): iterable
    {
        yield 'quoted older client' => ['"v4.3.2"', '"2.7"', '4.3.2 (Web API 2.7)', true];
        yield 'older upper boundary' => ['4.5.5', '2.8.3', '4.5.5 (Web API 2.8.3)', true];
        yield 'current quoted API' => ['v5.1.2', '"2.11.4"', '5.1.2 (Web API 2.11.4)', false];
        yield 'current lower API retained' => ['v4.6.0', '2.6', '4.6.0 (Web API 2.6)', false];
    }

    #[DataProvider('unsupportedVersions')]
    public function testMalformedOrUnsupportedVersionsAreRejected(string $app, string $api, string $reason): void
    {
        $this->expectException(ProviderException::class);
        try {
            (new QbittorrentProvider($this->transport($app, $api)))->testConnection($this->connection(), $this->credentials());
        } catch (ProviderException $exception) {
            self::assertSame($reason, $exception->reason);
            throw $exception;
        }
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function unsupportedVersions(): iterable
    {
        yield 'too old app' => ['4.3.1', '2.7', 'unsupported_version'];
        yield 'too old fallback API' => ['4.3.2', '2.6.9', 'unsupported_version'];
        yield 'future fallback API' => ['4.3.2', '3.0', 'unsupported_version'];
        yield 'future current API' => ['5.1.2', '3.0', 'unsupported_version'];
        yield 'wrong API family' => ['5.1.2', '1.7', 'unsupported_version'];
        yield 'future app precedence' => ['6.0.0', '[]', 'unsupported_version'];
        yield 'malformed app' => ['5.1.2garbage', '2.7', 'invalid_response'];
        yield 'malformed API' => ['5.1.2', '2.7garbage', 'invalid_response'];
        yield 'API v prefix' => ['5.1.2', 'v2.7', 'invalid_response'];
        yield 'JSON number' => ['5.1.2', '2', 'invalid_response'];
        yield 'JSON object' => ['5.1.2', '{"version":"2.7"}', 'invalid_response'];
    }

    public function testOlderVersionProbeAcceptsAnEmptyTorrentList(): void
    {
        $result = (new QbittorrentProvider($this->transport('4.3.2', '2.7')))->testConnection($this->connection(), $this->credentials());
        self::assertSame('4.3.2 (Web API 2.7)', $result['version']);
    }

    #[DataProvider('invalidProbeData')]
    public function testOlderVersionProbeRejectsUnusablePayloads(mixed $transfer, mixed $inventory): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The client returned an unreadable response.');
        (new QbittorrentProvider($this->transport('4.3.2', '2.7', transfer: $transfer, inventory: $inventory)))
            ->testConnection($this->connection(), $this->credentials());
    }

    /** @return iterable<string,array{mixed,mixed}> */
    public static function invalidProbeData(): iterable
    {
        yield 'missing speed' => [[], []];
        yield 'negative speed' => [['dl_info_speed' => -1], []];
        yield 'object inventory' => [['dl_info_speed' => 0], ['hash' => 'abcd']];
        yield 'missing record shape' => [['dl_info_speed' => 0], [['anything' => 'else']]];
        yield 'missing progress' => [['dl_info_speed' => 0], [['hash' => 'abcd', 'name' => 'Torrent', 'state' => 'downloading']]];
    }

    public function testCollectionKeepsActiveRowsAndAuthoritativeCompletions(): void
    {
        $transport = $this->transport('4.3.2', '2.7', inventory: [self::row(1, 0, 'tv', 0.25), self::row(2, 900)]);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(), $this->credentials());
        self::assertSame([sprintf('%040x', 1)], array_column($batch->items, 'source_id'));
        self::assertSame('downloading', $batch->items[0]['state']);
        self::assertSame([sprintf('%040x', 2)], array_column($batch->completions, 'source_id'));
        self::assertSame(624, strlen($batch->session['sid']));
    }

    public function testUnpagedUnsortedHistoryFindsNewestMatchingRowsBeyondTheFirstTwenty(): void
    {
        $rows = [];
        for ($index = 1; $index <= 150; ++$index) {
            $rows[] = self::row($index, $index, $index >= 125 ? 'tv' : 'movies');
        }
        $rows[] = self::row(150, 150);
        $transport = $this->transport(history: static fn (): array => $rows);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(true), $this->credentials());
        self::assertSame(range(150, 131), array_column($batch->completions, 'completed_at'));
        self::assertCount(20, array_unique(array_column($batch->completions, 'source_id')));
        self::assertCount(1, $this->historyRequests($transport));
    }

    public function testUnpagedHistoryExaminesOnlyTheNewestHundredSourceRows(): void
    {
        $rows = [];
        for ($index = 1; $index <= 150; ++$index) {
            $rows[] = self::row($index, $index, $index <= 50 ? 'tv' : 'movies');
        }
        $transport = $this->transport(history: static fn (): array => $rows);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(true), $this->credentials());
        self::assertSame([], $batch->completions);
        self::assertCount(1, $this->historyRequests($transport));
    }

    public function testOversizedLaterPageUsesOnlyRemainingSourceBudget(): void
    {
        $first = array_map(static fn (int $index): array => self::row($index, 1000 + $index, 'movies'), range(1, 20));
        $later = array_map(static fn (int $index): array => self::row($index, $index, $index <= 20 ? 'tv' : 'movies'), range(1, 100));
        $transport = $this->transport(history: static fn (array $query): array => $query['offset'] === '0' ? $first : $later);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(true), $this->credentials());
        self::assertSame([], $batch->completions);
        self::assertCount(2, $this->historyRequests($transport));
    }

    public function testOrdinaryPaginationStillFindsCategoryMatchesOnTheSecondPage(): void
    {
        $transport = $this->transport(history: static fn (array $query): array => $query['offset'] === '0'
            ? array_map(static fn (int $index): array => self::row($index, 1000 + $index, 'movies'), range(1, 20))
            : [self::row(30, 100), self::row(31, 110)]);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(true), $this->credentials());
        self::assertSame([110, 100], array_column($batch->completions, 'completed_at'));
        self::assertCount(2, $this->historyRequests($transport));
    }

    public function testRepeatedFullPageStopsInsteadOfRepeatingFiveReads(): void
    {
        $rows = array_map(static fn (int $index): array => self::row($index, $index, 'movies'), range(1, 20));
        $transport = $this->transport(history: static fn (): array => $rows);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(true), $this->credentials());
        self::assertSame([], $batch->completions);
        self::assertCount(2, $this->historyRequests($transport));
    }

    public function testMalformedCompletionEvidenceDoesNotFabricateHistory(): void
    {
        $rows = [self::row(1, 0), self::row(2, 100, progress: 0.5), ['hash' => 'not-a-hash', 'progress' => 1, 'completion_on' => 100]];
        $transport = $this->transport(history: static fn (): array => $rows);
        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect($this->connection(), $this->credentials());
        self::assertSame([], $batch->completions);
        self::assertNull($batch->history['error']);
    }

    /** @return array<string,mixed> */
    private static function row(int $id, int $completed, string $category = 'tv', float $progress = 1.0): array
    {
        return ['hash' => sprintf('%040x', $id), 'name' => 'Torrent ' . $id, 'category' => $category, 'tags' => 'sonarr',
            'state' => $progress === 1.0 ? 'stoppedUP' : 'downloading', 'progress' => $progress, 'completion_on' => $completed];
    }

    private function connection(bool $filtered = false): Connection
    {
        return new Connection('test-id', 'qbittorrent', 'Test', 'http://qb.test', filterMode: $filtered ? 'selected' : 'all', categories: ['tv']);
    }

    /** @return array{username:string,secret:string} */
    private function credentials(): array
    {
        return ['username' => 'admin', 'secret' => 'test-password'];
    }

    /** @return list<HttpRequest> */
    private function historyRequests(QbittorrentCompatibilityTransport $transport): array
    {
        return array_values(array_filter($transport->requests, static fn (HttpRequest $request): bool => str_contains($request->url, 'filter=completed')));
    }

    private function transport(string $app = '5.1.2', string $api = '2.11.4', mixed $transfer = ['dl_info_speed' => 0], mixed $inventory = [], ?callable $history = null): QbittorrentCompatibilityTransport
    {
        return new QbittorrentCompatibilityTransport(static function (HttpRequest $request) use ($app, $api, $transfer, $inventory, $history): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=' . str_repeat('Ab+c/def', 78) . '; Path=/']]);
            }
            self::assertContains('Cookie: SID=' . str_repeat('Ab+c/def', 78), $request->headers);
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $data = match ($path) {
                '/api/v2/app/version' => null,
                '/api/v2/app/webapiVersion' => null,
                '/api/v2/torrents/categories' => ['tv' => ['name' => 'tv']],
                '/api/v2/torrents/tags' => ['sonarr'],
                '/api/v2/transfer/info' => $transfer,
                '/api/v2/torrents/info' => ($query['filter'] ?? '') === 'completed' ? ($history === null ? [] : $history($query)) : $inventory,
                default => self::fail('Unexpected qBittorrent request.'),
            };
            return new HttpResponse(200, match ($path) {
                '/api/v2/app/version' => $app,
                '/api/v2/app/webapiVersion' => $api,
                default => json_encode($data, JSON_THROW_ON_ERROR),
            });
        });
    }
}

final class QbittorrentCompatibilityTransport implements DownloaderTransport
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
