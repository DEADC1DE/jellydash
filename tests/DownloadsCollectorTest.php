<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\Collector;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Downloads\OverviewService;
use Mk\Framework\Downloads\Provider;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class DownloadsCollectorTest extends TestCase
{
    private string $path;
    private Database $database;
    private ConnectionRepository $connections;
    private DownloadRepository $downloads;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/downloads-collector-' . bin2hex(random_bytes(8));
        $this->database = Database::sqlite($this->path . '.sqlite');
        $store = new CredentialStore($this->path . '.key');
        $this->connections = new ConnectionRepository($this->database, $store);
        $this->downloads = new DownloadRepository($this->database, $store);
    }

    protected function tearDown(): void
    {
        $this->database->getDibi()->disconnect();
        foreach (['.sqlite', '.sqlite-wal', '.sqlite-shm', '.key', '.key.lock'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testUnconfiguredAndDisabledClientsNeverContactAProvider(): void
    {
        $calls = 0;
        $collector = new Collector($this->connections, $this->downloads, static function () use (&$calls): Provider {
            ++$calls;
            throw new \RuntimeException('Unexpected provider access');
        });
        self::assertSame(['collected' => 0, 'failed' => 0], $collector->run(1000));
        $this->connections->save(new Connection('disabled', 'sabnzbd', 'Disabled', 'https://sab.test', enabled: false), 'secret');
        self::assertSame(['collected' => 0, 'failed' => 0], $collector->run(1000));
        self::assertSame(0, $calls);
    }

    public function testGlobalSwitchHidesSavedActivityAndDiscardsAnInFlightResultUntilReenabled(): void
    {
        $previous = AppSettings::get('downloads_enabled');
        try {
            AppSettings::set('downloads_enabled', null);
            $connection = $this->connections->save(new Connection('global-switch', 'sabnzbd', 'Saved client', 'https://saved.test'), 'secret');
            $token = $this->downloads->claim($connection, 1000);
            self::assertNotNull($token);
            self::assertTrue($this->downloads->succeed($connection, $token, new CollectionBatch(
                items: [['source_id' => 'old', 'title' => 'Saved item', 'state' => 'downloading', 'speed' => 10.0]],
                completions: [$this->completion('done', 'tv', 1000)],
                speed: 10.0,
            ), 1000));
            $overview = new OverviewService($this->connections, $this->downloads);
            self::assertSame('Saved item', $overview->snapshot(1001)['items'][0]['title']);
            AppSettings::set('downloads_enabled', '1');

            $provider = new class () implements Provider {
                public int $calls = 0;
                public function testConnection(Connection $connection, array $credentials): array
                {
                    return ['version' => '1', 'categories' => [], 'tags' => []];
                }
                public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
                {
                    ++$this->calls;
                    if ($this->calls === 1) {
                        // Simulate another Settings request without touching this process's cache.
                        Container::db()->getDibi()->update('app_settings', ['setting_value' => '0'])
                            ->where('setting_key = %s', 'downloads_enabled')->execute();
                    }

                    return new CollectionBatch(
                        items: [['source_id' => 'new', 'title' => 'New item', 'state' => 'downloading', 'speed' => 20.0]],
                        speed: 20.0,
                    );
                }
            };
            $collector = new Collector($this->connections, $this->downloads, static fn (): Provider => $provider);
            self::assertSame(['collected' => 0, 'failed' => 0], $collector->run(1015));
            self::assertSame(1, $provider->calls);
            self::assertSame(1000, $this->downloads->state($connection->id)['last_success_at']);
            self::assertCount(1, $this->downloads->recent([$connection->id]));
            $disabled = $overview->snapshot(1016);
            self::assertFalse($disabled['feature_enabled']);
            self::assertFalse($disabled['configured']);
            self::assertSame([], $disabled['connections']);
            self::assertSame([], $disabled['items']);
            self::assertSame([], $disabled['history']);
            self::assertNull($disabled['speed']);
            self::assertFalse($overview->snapshot(1016, true)['monitoring_enabled']);
            self::assertSame(['collected' => 0, 'failed' => 0], $collector->run(1016));
            self::assertSame(1, $provider->calls);

            AppSettings::set('downloads_enabled', '1');
            self::assertSame(['collected' => 1, 'failed' => 0], $collector->run(1016));
            self::assertSame(2, $provider->calls);
            $resumed = $overview->snapshot(1017);
            self::assertTrue($resumed['feature_enabled']);
            self::assertSame('New item', $resumed['items'][0]['title']);
            self::assertCount(1, $resumed['history']);
        } finally {
            AppSettings::set('downloads_enabled', $previous);
        }
    }

    public function testOneClientFailureDoesNotHideHealthyDownloadsOrLeakPrivateFields(): void
    {
        $now = 1_800_000_000;
        $this->connections->save(new Connection('a', 'sabnzbd', 'Healthy', 'https://a.test'), 'secret-a');
        $this->connections->save(new Connection('b', 'sabnzbd', 'Offline', 'https://b.test'), 'secret-b');
        $provider = new class () implements Provider {
            public int $calls = 0;
            public function testConnection(Connection $connection, array $credentials): array
            {
                return ['version' => '1', 'categories' => [], 'tags' => []];
            }
            public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
            {
                ++$this->calls;
                if ($connection->id === 'b') {
                    throw new ProviderException('timeout');
                }
                return new CollectionBatch(items: [
                    ['source_id' => '1', 'title' => 'A title', 'category' => '', 'tags' => [], 'state' => 'downloading', 'progress' => 50.0,
                        'size' => 1000, 'downloaded' => 500, 'speed' => 50.0, 'eta' => 10, 'position' => 1, 'remote_path' => '/private'],
                ], speed: 50.0);
            }
        };
        $collector = new Collector($this->connections, $this->downloads, static fn (): Provider => $provider);
        self::assertSame(['collected' => 1, 'failed' => 1], $collector->run($now));
        self::assertSame(['collected' => 0, 'failed' => 0], $collector->run($now + 1));
        self::assertSame(2, $provider->calls);
        $overview = new OverviewService($this->connections, $this->downloads);
        $fresh = $overview->snapshot($now + 1);
        self::assertNull($fresh['speed']);
        self::assertFalse($fresh['speed_complete']);
        self::assertSame(50.0, $fresh['connections'][0]['speed']);
        self::assertSame(1, $fresh['active_count']);
        self::assertTrue($fresh['partial']);
        self::assertSame(['connected', 'offline'], array_column($fresh['connections'], 'status'));
        $json = json_encode($fresh, JSON_THROW_ON_ERROR);
        foreach (['secret-a', 'secret-b', 'a.test', 'b.test', '/private'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $stale = $overview->snapshot($now + 50);
        self::assertNull($stale['speed']);
        self::assertSame(0, $stale['active_count']);
        self::assertTrue($stale['items'][0]['stale']);
        self::assertNull($stale['items'][0]['speed']);
        self::assertNull($stale['items'][0]['eta']);
        self::assertArrayNotHasKey('items', $overview->snapshot($now, true));
    }

    public function testExternalCollectionStaysLiveAndAgesNormallyWithBuiltInWorkersDisabled(): void
    {
        $previous = getenv('POLLER_ENABLED');
        putenv('POLLER_ENABLED=false');
        try {
            $connection = $this->connections->save(new Connection('external', 'sabnzbd', 'External', 'https://external.test'), 'secret');
            $overview = new OverviewService($this->connections, $this->downloads);
            self::assertFalse($overview->snapshot(1000)['monitoring_enabled']);
            $token = $this->downloads->claim($connection, 1000);
            self::assertNotNull($token);
            self::assertTrue($this->downloads->succeed($connection, $token, new CollectionBatch(
                items: [['source_id' => 'job', 'title' => 'Job', 'state' => 'downloading', 'speed' => 50.0, 'eta' => 20]],
                speed: 50.0,
            ), 1001));
            $fresh = $overview->snapshot(1002);
            self::assertFalse($fresh['workers_enabled']);
            self::assertTrue($fresh['monitoring_enabled']);
            self::assertTrue($overview->snapshot(1002, true)['monitoring_enabled']);
            self::assertSame(50.0, $fresh['speed']);
            self::assertSame(1, $fresh['active_count']);
            self::assertFalse($fresh['items'][0]['stale']);
            $stale = $overview->snapshot(1050);
            self::assertTrue($stale['monitoring_enabled']);
            self::assertSame('stale', $stale['connections'][0]['status']);
            self::assertNull($stale['speed']);
            self::assertSame(0, $stale['active_count']);
            self::assertNull($stale['items'][0]['eta']);
            self::assertTrue($stale['items'][0]['stale']);
        } finally {
            putenv($previous === false ? 'POLLER_ENABLED' : 'POLLER_ENABLED=' . $previous);
        }
    }

    public function testFailedExternalAttemptEnablesMonitoringUntilConnectionChanges(): void
    {
        $previous = getenv('POLLER_ENABLED');
        putenv('POLLER_ENABLED=false');
        try {
            $connection = $this->connections->save(new Connection('external', 'sabnzbd', 'External', 'https://external.test'), 'secret');
            $overview = new OverviewService($this->connections, $this->downloads);
            $token = $this->downloads->claim($connection, 1000);
            self::assertNotNull($token);
            $this->downloads->fail($connection, $token, 'timeout', 1001);
            $failed = $overview->snapshot(1002);
            self::assertTrue($failed['monitoring_enabled']);
            self::assertSame('offline', $failed['connections'][0]['status']);
            self::assertNull($failed['speed']);
            $this->connections->save(new Connection('external', 'sabnzbd', 'External', 'https://external.test', enabled: false), null, $connection->revision);
            self::assertFalse($overview->snapshot(1003)['monitoring_enabled']);
        } finally {
            putenv($previous === false ? 'POLLER_ENABLED' : 'POLLER_ENABLED=' . $previous);
        }
    }

    public function testDeletionDuringCollectionCannotRecreateStateOrHistory(): void
    {
        $connection = $this->connections->save(new Connection('gone', 'sabnzbd', 'Gone', 'https://gone.test'), 'secret');
        $provider = new class ($this->connections) implements Provider {
            public function __construct(private ConnectionRepository $connections)
            {
            }
            public function testConnection(Connection $connection, array $credentials): array
            {
                return ['version' => '1', 'categories' => [], 'tags' => []];
            }
            public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
            {
                $this->connections->delete($connection->id, $connection->revision);
                return new CollectionBatch();
            }
        };
        self::assertSame(['collected' => 0, 'failed' => 0], (new Collector($this->connections, $this->downloads, static fn (): Provider => $provider))->run(1_800_000_000));
        self::assertNull($this->downloads->state($connection->id));
        self::assertSame([], $this->downloads->recent([$connection->id]));
    }

    public function testOverviewIncludesNewestMatchingHistoryForEachEnabledConnection(): void
    {
        $dominant = $this->connections->save(new Connection(
            'dominant',
            'sabnzbd',
            'Dominant',
            'https://dominant.test',
        ), 'secret');
        $filtered = $this->connections->save(new Connection(
            'filtered',
            'qbittorrent',
            'Filtered',
            'https://filtered.test',
            'admin',
        ), 'secret');
        $disabled = $this->connections->save(new Connection(
            'disabled-history',
            'sabnzbd',
            'Disabled history',
            'https://disabled-history.test',
        ), 'secret');

        $this->storeCompletions($dominant, array_map(
            fn (int $index): array => $this->completion('dominant-' . $index, 'movies', 1_800_002_000 + $index),
            range(0, 24),
        ), 1_800_003_000);
        $filteredCompletions = array_map(
            fn (int $index): array => $this->completion('filtered-' . $index, 'movies', 1_800_000_000 + $index),
            range(0, 24),
        );
        $filteredCompletions[] = $this->completion('filtered-other', 'shows', 1_800_001_000);
        $this->storeCompletions($filtered, $filteredCompletions, 1_800_003_000);
        $this->storeCompletions($disabled, [
            $this->completion('disabled-item', 'movies', 1_800_004_000),
        ], 1_800_004_001);

        $filtered = $this->connections->save(new Connection(
            $filtered->id,
            $filtered->provider,
            $filtered->name,
            $filtered->url,
            $filtered->username,
            filterMode: 'selected',
            categories: ['movies'],
            revision: $filtered->revision,
            hasSecret: true,
        ), null, $filtered->revision);
        $disabled = $this->connections->save(new Connection(
            $disabled->id,
            $disabled->provider,
            $disabled->name,
            $disabled->url,
            enabled: false,
            revision: $disabled->revision,
            hasSecret: true,
        ), null, $disabled->revision);

        $overview = new OverviewService($this->connections, $this->downloads);
        $snapshot = $overview->snapshot(1_800_004_010);
        self::assertCount(20, $snapshot['history']);
        self::assertSame(['dominant'], array_values(array_unique(array_column($snapshot['history'], 'connection_id'))));
        self::assertSame(['dominant', 'filtered'], array_keys($snapshot['history_by_connection']));
        self::assertCount(20, $snapshot['history_by_connection']['dominant']);
        self::assertCount(20, $snapshot['history_by_connection']['filtered']);
        self::assertSame(
            array_map(static fn (int $index): string => 'filtered-' . $index, range(24, 5)),
            array_column($snapshot['history_by_connection']['filtered'], 'source_id'),
        );
        self::assertSame(['movies'], array_values(array_unique(array_column(
            $snapshot['history_by_connection']['filtered'],
            'category',
        ))));
        self::assertArrayNotHasKey($disabled->id, $snapshot['history_by_connection']);

        $summary = $overview->snapshot(1_800_004_010, true);
        self::assertArrayNotHasKey('history', $summary);
        self::assertArrayNotHasKey('history_by_connection', $summary);
    }

    /** @param list<array<string,mixed>> $completions */
    private function storeCompletions(Connection $connection, array $completions, int $now): void
    {
        $token = $this->downloads->claim($connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed(
            $connection,
            $token,
            new CollectionBatch(completions: $completions),
            $now,
        ));
    }

    /** @return array<string,mixed> */
    private function completion(string $id, string $category, int $completedAt): array
    {
        return [
            'source_id' => $id,
            'title' => 'Completed ' . $id,
            'category' => $category,
            'tags' => [],
            'state' => 'completed',
            'progress' => 100.0,
            'size' => 1024,
            'downloaded' => 1024,
            'speed' => 0.0,
            'eta' => 0,
            'position' => null,
            'completed_at' => $completedAt,
        ];
    }
}
