<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\NzbgetProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class NzbgetProviderTest extends TestCase
{
    public function testHistoryFailureDoesNotDiscardLiveQueueOrSpeed(): void
    {
        $queue = [$this->queue(1, 'DOWNLOADING')];
        $transport = new NzbgetFixtureTransport(static function (HttpRequest $request) use ($queue): HttpResponse {
            $call = self::call($request);
            if ($call['method'] === 'history') {
                throw new ProviderException('response_too_large');
            }
            return self::rpc($call['method'] === 'status' ? ['DownloadRate' => 2048] : $queue, 1);
        });
        $batch = (new NzbgetProvider($transport))->collect($this->connection('http://nzb.test'), $this->credentials());
        self::assertSame(2048.0, $batch->speed);
        self::assertSame('downloading', $batch->items[0]['state']);
        self::assertTrue($batch->complete);
        self::assertSame([], $batch->completions);
        self::assertSame('response_too_large', $batch->history['error']);
        self::assertGreaterThan(time(), $batch->history['next_due_at']);
    }

    public function testDeferredHistoryDoesNotInventCompletionWhenQueueItemDisappears(): void
    {
        $transport = $this->transport([], [], ['DownloadRate' => 0]);
        $history = ['last_attempt_at' => time(), 'last_success_at' => time(), 'error' => null,
            'next_due_at' => time() + 60, 'failure_count' => 0];
        $batch = (new NzbgetProvider($transport))->collect($this->connection('http://nzb.test'), $this->credentials(), ['history' => $history]);
        self::assertSame(['status', 'listgroups'], array_map(static fn (HttpRequest $r): string => self::call($r)['method'], $transport->requests));
        self::assertSame([], $batch->items);
        self::assertSame([], $batch->completions);
        self::assertSame($history, $batch->history);
    }

    public function testInvalidHistoryTailDiscardsAllTentativeOutcomesButKeepsLiveSnapshot(): void
    {
        foreach (['truncated', 'wrong-id', 'rpc-error'] as $kind) {
            $history = [$this->history(8, 'SUCCESS/ALL')];
            $transport = new NzbgetFixtureTransport(static function (HttpRequest $request) use ($kind, $history): HttpResponse {
                $method = self::call($request)['method'];
                if ($method !== 'history') {
                    return self::rpc($method === 'status' ? ['DownloadRate' => 128] : [], 1);
                }
                $body = json_encode(['result' => $history, 'id' => $kind === 'wrong-id' ? 99 : 1,
                    'error' => $kind === 'rpc-error' ? ['message' => 'private-upstream-text'] : null], JSON_THROW_ON_ERROR);
                return new HttpResponse(200, $kind === 'truncated' ? substr($body, 0, -2) : $body);
            });
            $batch = (new NzbgetProvider($transport))->collect($this->connection('http://nzb.test'), $this->credentials());
            self::assertSame([], $batch->completions);
            self::assertSame(128.0, $batch->speed);
            self::assertSame('invalid_response', $batch->history['error']);
            self::assertStringNotContainsString('private-upstream-text', json_encode($batch, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnorderedLargeHistoryKeepsNewestMatchingOutcomesAfterFiltering(): void
    {
        $history = [];
        for ($i = 250; $i >= 1; --$i) {
            $history[] = $this->history($i, 'SUCCESS/ALL', ['Category' => $i % 2 ? 'tv' : 'other',
                'HistoryTime' => $i, 'unused' => str_repeat('x', 10000)]);
        }
        $history[] = $this->history(999, 'FAILURE/UNPACK', ['Category' => 'tv', 'HistoryTime' => 1000]);
        $connection = new Connection('id', 'nzbget', 'NZBGet', 'http://nzb.test', filterMode: 'selected', categories: ['tv']);
        $batch = (new NzbgetProvider($this->transport([], $history, ['DownloadRate' => 0])))->collect($connection, $this->credentials());
        self::assertCount(100, $batch->completions);
        self::assertSame('999', $batch->completions[0]['source_id']);
        self::assertSame('failed', $batch->completions[0]['state']);
        self::assertSame(['tv'], array_values(array_unique(array_column($batch->completions, 'category'))));
        self::assertNull($batch->history['error']);
    }

    public function testSetupReadsRuntimeCategoriesWithoutReturningConfigSecrets(): void
    {
        $transport = new NzbgetFixtureTransport(static function (HttpRequest $request): HttpResponse {
            self::assertSame('POST', $request->method);
            self::assertSame('https://nzb.test/proxy/jsonrpc', $request->url);
            self::assertContains('Authorization: Basic ' . base64_encode(':private-password'), $request->headers);
            $call = self::call($request);
            return match ($call['method']) {
                'version' => self::rpc('26.3', $call['id']),
                'config' => self::rpc([
                    ['Name' => 'ControlPassword', 'Value' => 'sensitive-config-value'],
                    ['Name' => 'Category1.Name', 'Value' => 'tv'],
                    ['Name' => 'Category2.Name', 'Value' => 'movies'],
                    ['Name' => 'Category2.DestDir', 'Value' => 'private-path'],
                ], $call['id']),
                default => self::fail('Unexpected NZBGet operation'),
            };
        });
        $result = (new NzbgetProvider($transport))->testConnection($this->connection('https://nzb.test/proxy'), $this->credentials());
        self::assertSame(['version' => '26.3', 'categories' => ['', 'movies', 'tv'], 'tags' => []], $result);
        self::assertStringNotContainsString('sensitive-config-value', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertCount(2, $transport->requests);
    }

    public function testQueueStatesPayloadBytesSpeedAndTerminalHistory(): void
    {
        $queue = [
            $this->queue(1, 'DOWNLOADING', ['FileSizeHi' => 1, 'FileSizeLo' => 1024, 'RemainingSizeHi' => 0, 'RemainingSizeLo' => 1024, 'DownloadedSizeHi' => 9, 'DownloadedSizeLo' => 0]),
            $this->queue(2, 'FETCHING'),
            $this->queue(3, 'PAUSED'),
            $this->queue(4, 'PP_QUEUED'),
            $this->queue(5, 'UNPACKING', ['PostStageProgress' => 725]),
            $this->queue(6, 'PP_FINISHED', ['PostStageProgress' => 1000]),
            $this->queue(12, 'QS_QUEUED'),
            $this->queue(13, 'QS_EXECUTING'),
        ];
        $history = [
            $this->history(7, 'SUCCESS/ALL', ['HistoryTime' => 700]),
            $this->history(8, 'FAILURE/UNPACK', ['HistoryTime' => 800]),
            $this->history(9, 'WARNING/SKIPPED', ['Kind' => 'URL', 'HistoryTime' => 900]),
            $this->history(10, 'DELETED/MANUAL', ['HistoryTime' => 1000]),
            $this->history(11, 'FAILURE/HIDDEN', ['Kind' => 'DUP', 'HistoryTime' => 1100]),
        ];
        $transport = $this->transport($queue, $history, ['DownloadRateHi' => 1, 'DownloadRateLo' => 10, 'DownloadRate' => 5]);
        $batch = (new NzbgetProvider($transport))->collect($this->connection('http://nzb.test'), $this->credentials());
        self::assertSame(4294967306.0, $batch->speed);
        self::assertSame(['downloading', 'metadata', 'paused', 'queued', 'processing', 'processing', 'queued', 'processing'], array_column($batch->items, 'state'));
        self::assertSame(4294967296, $batch->items[0]['downloaded']);
        self::assertSame(4294968320, $batch->items[0]['size']);
        self::assertSame(72.5, $batch->items[4]['progress']);
        self::assertSame(['warning', 'failed', 'completed'], array_column($batch->completions, 'state'));
        self::assertSame(['9', '8', '7'], array_column($batch->completions, 'source_id'));
        self::assertSame($batch->history, $batch->cursor['history']);
        self::assertNull($batch->history['error']);
        self::assertSame([], $batch->session);
        self::assertTrue($batch->complete);
        self::assertSame(['status', 'listgroups', 'history'], array_map(static fn (HttpRequest $r): string => self::call($r)['method'], $transport->requests));
        self::assertSame([0], self::call($transport->requests[1])['params']);
        self::assertSame([false], self::call($transport->requests[2])['params']);
    }

    public function testReprocessingQueueIdDoesNotBecomePrematureHistoryAndSelectedHistoryIsPreserved(): void
    {
        $queue = [$this->queue(1, 'UNPACKING', ['Category' => 'tv'])];
        $history = [$this->history(1, 'FAILURE/UNPACK', ['Category' => 'tv', 'HistoryTime' => 2000])];
        for ($i = 2; $i <= 111; ++$i) {
            $history[] = $this->history($i, 'SUCCESS/ALL', ['Category' => 'other', 'HistoryTime' => 2000 + $i]);
        }
        $history[] = $this->history(112, 'SUCCESS/ALL', ['Category' => 'tv', 'HistoryTime' => 100]);
        $connection = new Connection('id', 'nzbget', 'NZBGet', 'http://nzb.test', filterMode: 'selected', categories: ['tv']);
        $batch = (new NzbgetProvider($this->transport($queue, $history, ['DownloadRate' => 12])))->collect($connection, $this->credentials());
        self::assertSame(12.0, $batch->speed);
        self::assertSame(['112'], array_column($batch->completions, 'source_id'));
        self::assertSame(['processing'], array_column($batch->items, 'state'));
    }

    public function testMissingAndInvalidMeasurementsStayUnknownWithoutMbFallbackOnPartialPair(): void
    {
        $one = $this->queue(1, 'DOWNLOADING', ['FileSizeHi' => 0, 'FileSizeLo' => 1024, 'RemainingSizeHi' => 0, 'RemainingSizeLo' => 512]);
        unset($one['FileSizeHi']);
        $two = $this->queue(2, 'DOWNLOADING', ['FileSizeMB' => 2, 'RemainingSizeMB' => 1]);
        unset($two['FileSizeHi'], $two['FileSizeLo'], $two['RemainingSizeHi'], $two['RemainingSizeLo']);
        $three = $this->queue(3, 'DOWNLOADING');
        unset($three['FileSizeHi'], $three['FileSizeLo'], $three['FileSizeMB'], $three['RemainingSizeHi'], $three['RemainingSizeLo'], $three['RemainingSizeMB']);
        $batch = (new NzbgetProvider($this->transport([$one, $two, $three], [], [])))->collect($this->connection('http://nzb.test'), $this->credentials());
        self::assertNull($batch->speed);
        self::assertNull($batch->items[0]['size']);
        self::assertNull($batch->items[0]['downloaded']);
        self::assertSame(2097152, $batch->items[1]['size']);
        self::assertSame(1048576, $batch->items[1]['downloaded']);
        self::assertNull($batch->items[2]['size']);
        self::assertNull($batch->items[2]['progress']);
    }

    public function testUnsupportedVersionAndAuthenticationFailureAreSanitized(): void
    {
        foreach (['20.0', '27.0'] as $version) {
            $transport = new NzbgetFixtureTransport(static fn (HttpRequest $r): HttpResponse => self::rpc($version, self::call($r)['id']));
            try {
                (new NzbgetProvider($transport))->testConnection($this->connection('http://nzb.test'), $this->credentials());
                self::fail('Expected version rejection');
            } catch (ProviderException $e) {
                self::assertSame('unsupported_version', $e->reason);
            }
        }
        $transport = new NzbgetFixtureTransport(static fn (): HttpResponse => new HttpResponse(401, 'private upstream error'));
        try {
            (new NzbgetProvider($transport))->testConnection($this->connection('http://nzb.test'), $this->credentials());
            self::fail('Expected authentication failure');
        } catch (ProviderException $e) {
            self::assertSame('authentication_failed', $e->reason);
            self::assertStringNotContainsString('private upstream error', $e->getMessage());
        }
    }

    public function testMalformedRpcIdAndErrorDoNotExposeRemoteText(): void
    {
        $badId = new NzbgetFixtureTransport(static fn (): HttpResponse => self::rpc([], 99));
        $this->assertInvalid($badId);
        $badError = new NzbgetFixtureTransport(static fn (): HttpResponse => new HttpResponse(200, json_encode([
            'id' => 1, 'result' => null, 'error' => ['code' => 99, 'message' => 'private remote message'],
        ], JSON_THROW_ON_ERROR)));
        $this->assertInvalid($badError);
    }

    public function testCurrentAndRecentCapsUseCurrentQueueCount(): void
    {
        $history = [];
        for ($i = 1; $i <= 110; ++$i) {
            $history[] = $this->history($i, 'SUCCESS/ALL', ['HistoryTime' => $i]);
        }
        $batch = (new NzbgetProvider($this->transport([], $history, ['DownloadRate' => 0])))->collect(
            $this->connection('http://nzb.test'), $this->credentials(),
        );
        self::assertTrue($batch->complete);
        self::assertCount(100, $batch->completions);
        self::assertSame('110', $batch->completions[0]['source_id']);

        $queue = [];
        for ($i = 111; $i <= 1111; ++$i) {
            $queue[] = $this->queue($i, 'QUEUED');
        }
        $batch = (new NzbgetProvider($this->transport($queue, $history, ['DownloadRate' => 0])))->collect(
            $this->connection('http://nzb.test'), $this->credentials(),
        );
        self::assertFalse($batch->complete);
        self::assertCount(1000, $batch->items);
    }

    public function testNewestVisibleOutcomeWinsForRepeatedHistoryId(): void
    {
        $history = [
            $this->history(15, 'FAILURE/UNPACK', ['HistoryTime' => 100]),
            $this->history(15, 'SUCCESS/ALL', ['HistoryTime' => 200]),
        ];
        $batch = (new NzbgetProvider($this->transport([], $history, ['DownloadRate' => 0])))->collect(
            $this->connection('http://nzb.test'), $this->credentials(),
        );
        self::assertSame(['15'], array_column($batch->completions, 'source_id'));
        self::assertSame('completed', $batch->completions[0]['state']);
    }

    public function testHiLoAcceptsPhpIntegerMaximumAndRejectsOverflow(): void
    {
        $queue = [
            $this->queue(1, 'DOWNLOADING', [
                'FileSizeHi' => 2147483647, 'FileSizeLo' => 4294967295,
                'RemainingSizeHi' => 0, 'RemainingSizeLo' => 0,
            ]),
            $this->queue(2, 'DOWNLOADING', [
                'FileSizeHi' => 2147483648, 'FileSizeLo' => 0,
            ]),
        ];
        $batch = (new NzbgetProvider($this->transport($queue, [], ['DownloadRate' => 0])))->collect(
            $this->connection('http://nzb.test'), $this->credentials(),
        );
        self::assertSame(PHP_INT_MAX, $batch->items[0]['size']);
        self::assertSame(PHP_INT_MAX, $batch->items[0]['downloaded']);
        self::assertNull($batch->items[1]['size']);
    }

    private function assertInvalid(NzbgetFixtureTransport $transport): void
    {
        try {
            (new NzbgetProvider($transport))->testConnection($this->connection('http://nzb.test'), $this->credentials());
            self::fail('Expected invalid response');
        } catch (ProviderException $e) {
            self::assertSame('invalid_response', $e->reason);
            self::assertStringNotContainsString('private remote message', $e->getMessage());
        }
    }

    /** @param list<array<string,mixed>> $queue @param list<array<string,mixed>> $history @param array<string,mixed> $status */
    private function transport(array $queue, array $history, array $status): NzbgetFixtureTransport
    {
        return new NzbgetFixtureTransport(static function (HttpRequest $request) use ($queue, $history, $status): HttpResponse {
            $call = self::call($request);
            return self::rpc(match ($call['method']) {
                'status' => $status,
                'listgroups' => $queue,
                'history' => $history,
                default => self::fail('Unexpected NZBGet operation'),
            }, $call['id']);
        });
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function queue(int $id, string $status, array $changes = []): array
    {
        return array_replace([
            'NZBID' => $id, 'Kind' => 'NZB', 'NZBName' => 'Fixture ' . $id,
            'Category' => '', 'Status' => $status,
            'FileSizeHi' => 0, 'FileSizeLo' => 1000, 'FileSizeMB' => 0,
            'RemainingSizeHi' => 0, 'RemainingSizeLo' => 500, 'RemainingSizeMB' => 0,
        ], $changes);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function history(int $id, string $status, array $changes = []): array
    {
        return array_replace([
            'NZBID' => $id, 'Kind' => 'NZB', 'Name' => 'History ' . $id,
            'Category' => '', 'Status' => $status, 'HistoryTime' => 1000,
            'FileSizeHi' => 0, 'FileSizeLo' => 1000, 'FileSizeMB' => 0,
        ], $changes);
    }

    private function connection(string $url): Connection
    {
        return new Connection('nzbget-id', 'nzbget', 'NZBGet', $url);
    }

    /** @return array{username:string,secret:string} */
    private function credentials(): array
    {
        return ['username' => '', 'secret' => 'private-password'];
    }

    /** @return array{method:string,params:list<mixed>,id:int} */
    private static function call(HttpRequest $request): array
    {
        return json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
    }

    private static function rpc(mixed $result, int $id): HttpResponse
    {
        return new HttpResponse(200, json_encode(['id' => $id, 'result' => $result, 'error' => null], JSON_THROW_ON_ERROR));
    }
}

final class NzbgetFixtureTransport implements DownloaderTransport
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
