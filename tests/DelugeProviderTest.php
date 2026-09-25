<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\DelugeProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class DelugeProviderTest extends TestCase
{
    public function testLoginConnectsOnlySavedHostAndDiscoversLabelsAndVersion(): void
    {
        $connected = false;
        $transport = new DelugeFixtureTransport(static function (HttpRequest $request) use (&$connected): HttpResponse {
            self::assertSame('POST', $request->method);
            self::assertSame('https://deluge.test/proxy/json', $request->url);
            $call = self::call($request);
            if ($call['method'] !== 'auth.login') {
                self::assertContains('Cookie: _session_id=' . self::cookie(), $request->headers);
            }
            return match ($call['method']) {
                'auth.login' => self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/proxy; HttpOnly']]),
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), '127.0.0.1', 58846, 'localclient']]),
                'web.connected' => self::rpc($connected),
                'web.connect' => (static function () use (&$connected, $call): HttpResponse {
                    self::assertSame([str_repeat('a', 32)], $call['params']);
                    $connected = true;
                    return self::rpc(['core.get_torrents_status']);
                })(),
                'web.get_host_status' => self::rpc([str_repeat('a', 32), 'Connected', '2.2.0']),
                'core.get_enabled_plugins' => self::rpc(['Label']),
                'label.get_labels' => self::rpc(['tv', 'movies']),
                default => self::fail('Unexpected Deluge operation'),
            };
        });
        $result = (new DelugeProvider($transport))->testConnection($this->connection('https://deluge.test/proxy'), ['username' => '', 'secret' => 'password']);
        self::assertSame('2.2.0', $result['version']);
        self::assertSame(['', 'movies', 'tv'], $result['categories']);
        self::assertSame([], $result['tags']);
        self::assertSame(['password'], self::call($transport->requests[0])['params']);
    }

    public function testCollectUsesPayloadRateAndKeepsCheckingAndErrorsCurrent(): void
    {
        $rows = [
            sprintf('%040x', 1) => $this->torrent('Downloading', ['label' => 'tv', 'download_payload_rate' => 42, 'progress' => 40]),
            sprintf('%040x', 2) => $this->torrent('Checking', ['progress' => 100, 'is_finished' => true, 'completed_time' => 600]),
            sprintf('%040x', 3) => $this->torrent('Seeding', ['progress' => 100, 'is_finished' => true, 'completed_time' => 700]),
            sprintf('%040x', 4) => $this->torrent('Paused', ['progress' => 100, 'is_finished' => true, 'completed_time' => 650]),
            sprintf('%040x', 5) => $this->torrent('Error', ['progress' => 20]),
        ];
        $transport = $this->collectionTransport($rows);
        $batch = (new DelugeProvider($transport))->collect($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password']);
        self::assertSame(123.0, $batch->speed);
        self::assertSame(['downloading', 'checking', 'error'], array_column($batch->items, 'state'));
        self::assertSame(['completed', 'completed'], array_column($batch->completions, 'state'));
        self::assertSame([700, 650], array_column($batch->completions, 'completed_at'));
        self::assertSame(42.0, $batch->items[0]['speed']);
        self::assertSame('tv', $batch->items[0]['category']);
        self::assertSame([], $batch->items[0]['tags']);
        self::assertSame(sprintf('%040x', 1), $batch->items[0]['source_id']);
        self::assertSame(self::cookie(), $batch->session['sid']);
        self::assertSame([], $batch->cursor);
    }

    public function testAbsentLabelPluginFailsClosedForSavedLabelFilter(): void
    {
        $transport = new DelugeFixtureTransport(static function (HttpRequest $request): HttpResponse {
            return match (self::call($request)['method']) {
                'auth.login' => self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/']]),
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), '127.0.0.1', 58846, 'localclient']]),
                'web.connected' => self::rpc(true),
                'web.get_host_status' => self::rpc([str_repeat('a', 32), 'Connected', '2.2.0']),
                'core.get_enabled_plugins' => self::rpc([]),
                default => self::fail('Unexpected Deluge operation'),
            };
        });
        $connection = new Connection('id', 'deluge', 'Deluge', 'http://deluge.test', filterMode: 'selected', categories: ['tv']);
        try {
            (new DelugeProvider($transport))->collect($connection, ['username' => '', 'secret' => 'password']);
            self::fail('Expected plugin absence to fail closed');
        } catch (ProviderException $e) {
            self::assertSame('labels_unavailable', $e->reason);
        }
        self::assertNotContains('core.get_torrents_status', array_map(static fn (HttpRequest $r): string => self::call($r)['method'], $transport->requests));
    }

    public function testMultipleSavedHostsAndUnsupportedVersionFailClosed(): void
    {
        $multiple = new DelugeFixtureTransport(static function (HttpRequest $request): HttpResponse {
            return match (self::call($request)['method']) {
                'auth.login' => self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/']]),
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), 'one', 58846, 'x'], [str_repeat('b', 32), 'two', 58846, 'y']]),
                default => self::fail('Should not select a host'),
            };
        });
        try {
            (new DelugeProvider($multiple))->testConnection($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password']);
            self::fail('Expected ambiguous host failure');
        } catch (ProviderException $e) {
            self::assertSame('ambiguous_host', $e->reason);
        }
        $old = new DelugeFixtureTransport(static function (HttpRequest $request): HttpResponse {
            return match (self::call($request)['method']) {
                'auth.login' => self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/']]),
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), 'one', 58846, 'x']]),
                'web.connected' => self::rpc(true),
                'web.get_host_status' => self::rpc([str_repeat('a', 32), 'Connected', '1.3.15']),
                default => self::fail('Unexpected Deluge operation'),
            };
        });
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not supported');
        (new DelugeProvider($old))->testConnection($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password']);
    }

    public function testExpiredCookieReloginsOnceAndMissingNumbersStayNull(): void
    {
        $loginCount = 0;
        $transport = new DelugeFixtureTransport(static function (HttpRequest $request) use (&$loginCount): HttpResponse {
            $method = self::call($request)['method'];
            if ($method === 'auth.login') {
                ++$loginCount;
                return self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/']]);
            }
            if (in_array('Cookie: _session_id=' . str_repeat('b', 64) . '6208', $request->headers, true)) {
                return self::rpc(null, [], 1);
            }
            return match ($method) {
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), 'one', 58846, 'x']]),
                'web.connected' => self::rpc(true),
                'web.get_host_status' => self::rpc([str_repeat('a', 32), 'Connected', '2.2.0']),
                'core.get_enabled_plugins' => self::rpc([]),
                'core.get_session_status' => self::rpc([]),
                'core.get_torrents_status' => self::rpc([sprintf('%040x', 1) => ['name' => 'Sparse', 'state' => 'Downloading']]),
                default => self::fail('Unexpected Deluge operation'),
            };
        });
        $batch = (new DelugeProvider($transport))->collect($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'], [], [
            'sid' => str_repeat('b', 64) . '6208', 'endpoint_hash' => hash('sha256', 'http://deluge.test/json'),
        ]);
        self::assertSame(1, $loginCount);
        self::assertNull($batch->speed);
        foreach (['progress', 'size', 'downloaded', 'speed', 'eta'] as $field) {
            self::assertNull($batch->items[0][$field]);
        }
    }

    public function testSelectedLabelCompletionSurvivesUnrelatedRecentSeeders(): void
    {
        $rows = [];
        for ($i = 1; $i <= 110; ++$i) {
            $rows[sprintf('%040x', $i)] = $this->torrent('Seeding', [
                'label' => 'other', 'progress' => 100, 'is_finished' => true, 'completed_time' => 1000 + $i,
            ]);
        }
        $selectedHash = sprintf('%040x', 111);
        $rows[$selectedHash] = $this->torrent('Paused', [
            'label' => 'tv', 'progress' => 100, 'is_finished' => true, 'completed_time' => 500,
        ]);
        $connection = new Connection('id', 'deluge', 'Deluge', 'http://deluge.test', filterMode: 'selected', categories: ['tv']);
        $batch = (new DelugeProvider($this->collectionTransport($rows)))->collect($connection, ['username' => '', 'secret' => 'password']);
        self::assertTrue($batch->complete);
        self::assertSame([$selectedHash], array_column($batch->completions, 'source_id'));
    }

    public function testUnknownTorrentStateFailsWithoutInventingAnError(): void
    {
        $rows = [sprintf('%040x', 1) => $this->torrent('FutureState')];
        try {
            (new DelugeProvider($this->collectionTransport($rows)))->collect($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password']);
            self::fail('Expected unknown state to fail');
        } catch (ProviderException $e) {
            self::assertSame('invalid_response', $e->reason);
        }
    }

    public function testFinishedDurationDoesNotCreateACompletion(): void
    {
        $row = $this->torrent('Paused', ['progress' => 45, 'finished_time' => 9000, 'completed_time' => 0]);
        $batch = (new DelugeProvider($this->collectionTransport([sprintf('%040x', 1) => $row])))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertSame([], $batch->completions);
        self::assertSame('paused', $batch->items[0]['state']);
    }

    public function testSessionCookieCannotCrossEndpoints(): void
    {
        $transport = $this->collectionTransport([]);
        $batch = (new DelugeProvider($transport))->collect($this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'], [], [
            'sid' => str_repeat('b', 64) . '6208', 'endpoint_hash' => hash('sha256', 'http://another.test/json'),
        ]);
        self::assertSame('auth.login', self::call($transport->requests[0])['method']);
        self::assertSame(self::cookie(), $batch->session['sid']);
    }

    public function testCurrentCapMarksBatchIncompleteWithoutCountingSeedingLibrary(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1050; ++$i) {
            $rows[sprintf('%040x', $i)] = $this->torrent('Seeding', [
                'progress' => 100, 'is_finished' => true, 'completed_time' => $i,
            ]);
        }
        $batch = (new DelugeProvider($this->collectionTransport($rows)))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertTrue($batch->complete);
        self::assertSame([], $batch->items);
        self::assertCount(100, $batch->completions);
        for ($i = 1051; $i <= 2051; ++$i) {
            $rows[sprintf('%040x', $i)] = $this->torrent('Downloading');
        }
        $batch = (new DelugeProvider($this->collectionTransport($rows)))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertFalse($batch->complete);
        self::assertCount(1000, $batch->items);
    }

    public function testMetadataAndKnownZeroRateTransitionToDownloading(): void
    {
        $rows = [
            sprintf('%040x', 1) => $this->torrent('Downloading', ['num_pieces' => 0, 'download_payload_rate' => 0]),
            sprintf('%040x', 2) => $this->torrent('Downloading', ['num_pieces' => 10, 'download_payload_rate' => 0]),
            sprintf('%040x', 3) => $this->torrent('Downloading', ['num_pieces' => 10, 'download_payload_rate' => 256]),
            sprintf('%040x', 4) => $this->torrent('Downloading', ['num_pieces' => 10]),
        ];
        unset($rows[sprintf('%040x', 4)]['download_payload_rate']);
        $batch = (new DelugeProvider($this->collectionTransport($rows)))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertSame(['metadata', 'stalled', 'downloading', 'downloading'], array_column($batch->items, 'state'));
        self::assertSame([0.0, 0.0, 256.0, null], array_column($batch->items, 'speed'));

        $rows[sprintf('%040x', 1)]['num_pieces'] = 10;
        $rows[sprintf('%040x', 1)]['download_payload_rate'] = 128;
        $rows[sprintf('%040x', 2)]['download_payload_rate'] = 64;
        $next = (new DelugeProvider($this->collectionTransport($rows)))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertSame(['downloading', 'downloading'], array_slice(array_column($next->items, 'state'), 0, 2));
    }

    public function testIntegerMeasurementsDoNotWrapAtThePlatformLimit(): void
    {
        $rows = [sprintf('%040x', 1) => $this->torrent('Downloading', ['total_wanted' => PHP_INT_MAX, 'total_remaining' => 1])];
        $batch = (new DelugeProvider($this->collectionTransport($rows)))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertSame(PHP_INT_MAX, $batch->items[0]['size']);
        self::assertSame(PHP_INT_MAX - 1, $batch->items[0]['downloaded']);
    }

    public function testDownloadedBytesUseNativeTotalRemainingWithoutEstimatingUnknownValues(): void
    {
        $rows = [
            sprintf('%040x', 1) => $this->torrent('Downloading', ['total_wanted' => 1000, 'total_remaining' => 250, 'progress' => 75]),
            sprintf('%040x', 2) => $this->torrent('Downloading', ['total_wanted' => 1000, 'total_remaining' => 1001, 'progress' => 75]),
            sprintf('%040x', 3) => $this->torrent('Downloading', ['total_wanted' => 1000, 'progress' => 75]),
        ];
        unset($rows[sprintf('%040x', 3)]['total_remaining']);
        $transport = $this->collectionTransport($rows);
        $batch = (new DelugeProvider($transport))->collect(
            $this->connection('http://deluge.test'), ['username' => '', 'secret' => 'password'],
        );
        self::assertSame([750, null, null], array_column($batch->items, 'downloaded'));
        $calls = array_map(self::call(...), $transport->requests);
        $statusCall = array_values(array_filter($calls, static fn (array $call): bool => $call['method'] === 'core.get_torrents_status'))[0];
        self::assertContains('total_remaining', $statusCall['params'][1]);
        self::assertNotContains('total_wanted_done', $statusCall['params'][1]);
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function collectionTransport(array $rows): DelugeFixtureTransport
    {
        return new DelugeFixtureTransport(static function (HttpRequest $request) use ($rows): HttpResponse {
            return match (self::call($request)['method']) {
                'auth.login' => self::rpc(true, ['set-cookie' => ['_session_id=' . self::cookie() . '; Path=/']]),
                'web.get_hosts' => self::rpc([[str_repeat('a', 32), 'one', 58846, 'x']]),
                'web.connected' => self::rpc(true),
                'web.get_host_status' => self::rpc([str_repeat('a', 32), 'Connected', '2.2.0']),
                'core.get_enabled_plugins' => self::rpc(['Label']),
                'core.get_session_status' => self::rpc(['payload_download_rate' => 123]),
                'core.get_torrents_status' => self::rpc($rows),
                default => self::fail('Unexpected Deluge operation'),
            };
        });
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function torrent(string $state, array $changes = []): array
    {
        return array_replace([
            'name' => 'Fixture', 'state' => $state, 'label' => '', 'progress' => 0,
            'total_wanted' => 100, 'total_remaining' => 100, 'download_payload_rate' => 0,
            'eta' => -1, 'queue' => 0, 'completed_time' => 0, 'is_finished' => false, 'num_pieces' => 10,
        ], $changes);
    }

    private function connection(string $url): Connection
    {
        return new Connection('deluge-id', 'deluge', 'Deluge', $url);
    }

    /** @return array{method:string,params:list<mixed>,id:int} */
    private static function call(HttpRequest $request): array
    {
        return json_decode((string) $request->body, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,list<string>> $headers */
    private static function rpc(mixed $result, array $headers = [], ?int $errorCode = null): HttpResponse
    {
        return new HttpResponse(200, json_encode(['id' => 1, 'result' => $result, 'error' => $errorCode === null ? null : ['code' => $errorCode, 'message' => 'hidden']], JSON_THROW_ON_ERROR), $headers);
    }

    private static function cookie(): string
    {
        return str_repeat('a', 64) . '6208';
    }
}

final class DelugeFixtureTransport implements DownloaderTransport
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
