<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Downloads\OverviewService;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class DownloadScopedSpeedTest extends TestCase
{
    public function testAdditionalTorrentClientsUseFilteredItemSpeeds(): void
    {
        foreach (['transmission', 'deluge'] as $provider) {
            $connection = $this->connections->save(new Connection(
                $provider, $provider, 'Client', 'https://' . $provider . '.test', 'admin',
                filterMode: 'selected', categories: ['movies'],
            ), 'secret');
            $this->collect($connection, [
                $this->item('included', $provider === 'transmission' ? 'other' : 'movies', ['other', 'movies'], 20.0),
                $this->item('excluded', 'other', ['other'], 80.0),
            ], 100.0);
            $snapshot = $this->downloads->state($connection->id)['snapshot'];
            self::assertSame(20.0, (float) $snapshot['speed']);
            self::assertSame(80.0, (float) $snapshot['outside_speed']);
            self::assertSame(['included'], array_column($snapshot['items'], 'source_id'));
        }
    }

    public function testNzbgetMixedCategoriesKeepScopedSpeedUnknown(): void
    {
        $connection = $this->connections->save(new Connection('nzb', 'nzbget', 'Usenet', 'https://nzb.test', 'admin', filterMode: 'selected', categories: ['movies']), 'secret');
        $this->collect($connection, [$this->item('a', 'movies', [], null), $this->item('b', 'other', [], null)], 100.0);
        $snapshot = $this->downloads->state($connection->id)['snapshot'];
        self::assertNull($snapshot['speed']);
        self::assertSame(100.0, (float) $snapshot['client_speed']);
    }

    private string $path;
    private Database $database;
    private ConnectionRepository $connections;
    private DownloadRepository $downloads;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/downloads-scoped-speed-' . bin2hex(random_bytes(8));
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

    public function testQbCategoryAndTagMatchesUseOnlyIncludedItemSpeeds(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [
            $this->item('category', 'movies', [], 20.0),
            $this->item('tag', 'other', ['keep'], 30.0),
            $this->item('excluded', 'other', ['skip'], 70.0),
        ], 150.0);

        $snapshot = $this->downloads->state($connection->id)['snapshot'];
        self::assertSame(['category', 'tag'], array_column($snapshot['items'], 'source_id'));
        self::assertEquals(50.0, $snapshot['speed']);
        self::assertEquals(150.0, $snapshot['client_speed']);
        self::assertEquals(70.0, $snapshot['outside_speed']);
        self::assertTrue($snapshot['speed_complete']);
        $service = new OverviewService($this->connections, $this->downloads);
        $overview = $service->snapshot(1001);
        self::assertSame(50.0, $overview['speed']);
        self::assertSame(70.0, $overview['outside_speed']);
        self::assertTrue($overview['speed_complete']);
        self::assertSame(150.0, $overview['connections'][0]['client_speed']);
    }

    public function testQbAllFilteredOutIsZeroWhileExcludedTransfersRemainVisibleAsOutsideSpeed(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('excluded', 'other', [], 70.0)], 90.0);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertSame(0.0, $overview['speed']);
        self::assertSame(70.0, $overview['outside_speed']);
        self::assertSame([], $overview['items']);
        self::assertTrue($overview['speed_complete']);
    }

    public function testSabSelectedSpeedIsUnknownForMixedDownloadingJobs(): void
    {
        $connection = $this->connection('sabnzbd');
        $this->collect($connection, [
            $this->item('included', 'movies', [], null),
            $this->item('excluded', 'other', [], null),
        ], 500.0);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertNull($overview['speed']);
        self::assertNull($overview['outside_speed']);
        self::assertFalse($overview['speed_complete']);
        self::assertSame(500.0, $overview['connections'][0]['client_speed']);
        self::assertNull($overview['connections'][0]['speed']);
    }

    public function testSabSelectedSpeedCanUseTotalOnlyWhenDownloadingJobsShareOneScope(): void
    {
        $connection = $this->connection('sabnzbd');
        $this->collect($connection, [
            $this->item('included', 'movies', [], null),
            $this->item('queued-outside', 'other', [], null, 'queued'),
        ], 500.0);
        $included = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertSame(500.0, $included['speed']);
        self::assertSame(0.0, $included['outside_speed']);

        $this->collect($connection, [
            $this->item('queued-inside', 'movies', [], null, 'queued'),
            $this->item('excluded', 'other', [], null),
        ], 600.0, 1016);
        $excluded = (new OverviewService($this->connections, $this->downloads))->snapshot(1017);
        self::assertSame(0.0, $excluded['speed']);
        self::assertSame(600.0, $excluded['outside_speed']);
    }

    public function testIncompleteSelectedInventoryCannotClaimAnExactSpeed(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('included', 'movies', [], 20.0)], 100.0, complete: false);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertNull($overview['speed']);
        self::assertNull($overview['outside_speed']);
        self::assertFalse($overview['speed_complete']);
        self::assertSame(100.0, $overview['connections'][0]['client_speed']);
        self::assertTrue($overview['partial']);
    }

    public function testUnknownQbItemSpeedDoesNotBecomeAnExactZero(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('included', 'movies', [], null)], 100.0);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertNull($overview['speed']);
        self::assertFalse($overview['speed_complete']);
        self::assertSame(0.0, $overview['outside_speed']);
    }

    public function testMetadataSpeedMissingRemainsUnknownAndMissingClientTotalStaysNull(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('metadata', 'movies', [], null, 'metadata')], null);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertNull($overview['speed']);
        self::assertFalse($overview['speed_complete']);
        self::assertNull($overview['connections'][0]['client_speed']);
    }

    public function testKnownEmptySelectedScopeDoesNotInventAClientTotal(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [], null);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertSame(0.0, $overview['speed']);
        self::assertNull($overview['connections'][0]['client_speed']);
        self::assertTrue($overview['speed_complete']);
    }

    public function testSabZeroClientSpeedResolvesMixedScopeToZero(): void
    {
        $connection = $this->connection('sabnzbd');
        $this->collect($connection, [
            $this->item('included', 'movies', [], null),
            $this->item('excluded', 'other', [], null),
        ], 0.0);

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertSame(0.0, $overview['speed']);
        self::assertSame(0.0, $overview['outside_speed']);
        self::assertTrue($overview['speed_complete']);
    }

    public function testAllModeKeepsClientTotalAndHasNoOutsideSpeed(): void
    {
        $connection = $this->connections->save(new Connection(
            'client', 'qbittorrent', 'Client', 'https://download.example.test', 'admin',
        ), 'secret');
        $this->collect($connection, [$this->item('one', 'movies', [], 20.0)], 100.0, complete: false);

        $service = new OverviewService($this->connections, $this->downloads);
        $overview = $service->snapshot(1001);
        self::assertSame(100.0, $overview['speed']);
        self::assertSame(0.0, $overview['outside_speed']);
        self::assertTrue($overview['speed_complete']);
        self::assertTrue($overview['partial']);

        $this->database->getDibi()->update('download_connection_state', [
            'snapshot_json' => json_encode(['items' => [], 'speed' => 200.0, 'complete' => true], JSON_THROW_ON_ERROR),
        ])->where('connection_id = %s', $connection->id)->execute();
        $legacy = $service->snapshot(1001);
        self::assertSame(200.0, $legacy['speed']);
        self::assertSame(200.0, $legacy['connections'][0]['client_speed']);
        self::assertTrue($legacy['speed_complete']);
    }

    public function testStaleOfflineAndDisabledClientsDoNotExposeLiveSpeed(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('included', 'movies', [], 20.0)], 100.0);
        $overview = new OverviewService($this->connections, $this->downloads);
        $stale = $overview->snapshot(1050);
        self::assertNull($stale['speed']);
        self::assertNull($stale['connections'][0]['client_speed']);
        self::assertFalse($stale['speed_complete']);
        $offline = $overview->snapshot(1200);
        self::assertNull($offline['connections'][0]['outside_speed']);

        $this->connections->save(new Connection(
            $connection->id, $connection->provider, $connection->name, $connection->url,
            $connection->username, enabled: false, filterMode: $connection->filterMode,
            categories: $connection->categories, tags: $connection->tags,
            revision: $connection->revision, hasSecret: true,
        ), null, $connection->revision);
        $disabled = $overview->snapshot(1001);
        self::assertSame('disabled', $disabled['connections'][0]['status']);
        self::assertNull($disabled['connections'][0]['speed']);
        self::assertNull($disabled['connections'][0]['client_speed']);
    }

    public function testOldSelectedSnapshotDoesNotLeakClientTotalIntoFilteredHeadline(): void
    {
        $connection = $this->connection('qbittorrent');
        $this->collect($connection, [$this->item('included', 'movies', [], 20.0)], 100.0);
        $this->database->getDibi()->update('download_connection_state', [
            'snapshot_json' => json_encode(['items' => [], 'speed' => 100.0, 'complete' => true], JSON_THROW_ON_ERROR),
        ])->where('connection_id = %s', $connection->id)->execute();

        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot(1001);
        self::assertNull($overview['speed']);
        self::assertSame(100.0, $overview['connections'][0]['client_speed']);
        self::assertFalse($overview['speed_complete']);
    }

    private function connection(string $provider): Connection
    {
        return $this->connections->save(new Connection(
            'client', $provider, 'Client', 'https://download.example.test',
            $provider === 'qbittorrent' ? 'admin' : '', filterMode: 'selected',
            categories: ['movies'], tags: $provider === 'qbittorrent' ? ['keep'] : [],
        ), 'secret');
    }

    /** @param list<array<string,mixed>> $items */
    private function collect(Connection $connection, array $items, ?float $speed, int $now = 1000, bool $complete = true): void
    {
        $token = $this->downloads->claim($connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($connection, $token,
            new CollectionBatch(items: $items, speed: $speed, complete: $complete), $now + 1));
    }

    /** @return array<string,mixed> */
    private function item(string $id, string $category, array $tags, ?float $speed, string $state = 'downloading'): array
    {
        return [
            'source_id' => $id, 'title' => 'Title ' . $id, 'category' => $category, 'tags' => $tags,
            'state' => $state, 'progress' => 25.0, 'size' => 1000, 'downloaded' => 250,
            'speed' => $speed, 'eta' => 10, 'position' => 1, 'completed_at' => null,
        ];
    }
}
