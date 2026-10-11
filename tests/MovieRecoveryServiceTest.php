<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieRecoveryService;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class MovieRecoveryServiceTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_movie_recovery_';
    private Database $database;
    private \Dibi\Connection $connection;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';
    private string $sqlitePath = '';
    private MovieIdentityRepository $identities;
    private MovieReplacements $replacements;
    private MovieRecoveryService $service;
    private int $now = 1000000;
    private string $server = 'server-one';
    /** @var array<string, array<string, mixed>> */
    private array $live = [];
    /** @var list<string> */
    private array $requests = [];
    private ?\Closure $override = null;

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'jellydash-movie-recovery-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
            $this->database = Database::sqlite($this->sqlitePath);
            $this->connection = $this->database->getDibi();
        } else {
            $config = ['driver' => DATABASE_DRIVER_DIBI, 'host' => DATABASE_HOST,
                'username' => DATABASE_USERNAME, 'password' => DATABASE_PASSWORD];
            if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
                $config['port'] = (int) DATABASE_PORT;
            }
            $this->admin = new \Dibi\Connection($config);
            $this->databaseName = self::DATABASE_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
            $this->admin->query('CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->databaseName);
            $config['database'] = $this->databaseName;
            $this->connection = new \Dibi\Connection($config);
            $this->database = new Database($this->connection);
        }
        new PlayHistoryRepository($this->database);
        $this->identities = new MovieIdentityRepository($this->database, 'test-server');
        $this->replacements = new MovieReplacements($this->database, 'test-server');
        $client = new JellyfinClient(requester: function (string $path, string $method): mixed {
            self::assertSame('GET', $method);
            $this->requests[] = $path;
            if ($this->override !== null) {
                $result = ($this->override)($path);
                if ($result !== null) {
                    return $result;
                }
            }
            if ($path === '/System/Info/Public') {
                return ['Id' => $this->server];
            }
            if ($path === '/Library/VirtualFolders') {
                return [['Name' => 'Movies', 'Locations' => ['/media/movies']],
                    ['Name' => 'Other Movies', 'Locations' => ['/media/other']]];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            if (isset($query['Ids'])) {
                $items = isset($this->live[$query['Ids']]) ? [$this->live[$query['Ids']]] : [];
                return ['Items' => $items, 'TotalRecordCount' => count($items)];
            }
            self::assertSame('SortName', $query['SortBy']);
            self::assertStringNotContainsString('ProductionYear', $query['Fields']);
            $items = array_values($this->live);
            return ['Items' => array_slice($items, (int) $query['StartIndex'], (int) $query['Limit']),
                'TotalRecordCount' => count($items)];
        });
        $this->service = new MovieRecoveryService($this->database, $client, $this->identities,
            $this->replacements, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }
        if ($this->admin !== null && $this->databaseName !== '') {
            if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/D', $this->databaseName) !== 1) {
                throw new \RuntimeException('Refusing to drop an unsafe temporary database name.');
            }
            $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
            $this->admin->disconnect();
        }
        foreach ([$this->sqlitePath, $this->sqlitePath . '-shm', $this->sqlitePath . '-wal'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCapturedMovieAutomaticallyRecoversBeforeReplacementIsPlayed(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $before = $this->rows();
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();

        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertTrue($this->service->status()['repairs'][0]['automatic']);
        self::assertSame($before, $this->rows());
        self::assertSame(1, (int) $this->connection->select('COUNT(*)')->from('play_history')->fetchSingle());
    }

    public function testFirstCompleteInventoryCanSuggestButCannotAutomaticallyLink(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123'],
            'year' => 2020, 'library' => 'Movies']);
        $this->live = ['new' => $this->movie('new')];
        $this->service->runBatch();
        $this->service->runBatch();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testLegacyHistoryOffersCandidateAndConfirmsWithoutRawIdCommands(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $status = $this->service->status();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame('new', $status['suggestions'][0]['candidates'][0]['id']);
        self::assertSame(1, $status['suggestions'][0]['plays']);
        $before = $this->rows();
        $this->service->confirm('old', 'new');
        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertFalse($this->service->status()['repairs'][0]['automatic']);
        self::assertSame($before, $this->rows());
    }

    public function testDifferentlyCasedRecordedIdsRemainSeparateRecoverySources(): void
    {
        $this->seed('old-A');
        $this->seed('OLD-A');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame(2, $this->service->status()['suggestionCount']);
        self::assertEqualsCanonicalizing(['old-A', 'OLD-A'], array_column($this->service->status()['suggestions'], 'sourceId'));
    }

    public function testExistingManualAliasesAreVisibleAndCanBeUndoneWithoutIdentityCapture(): void
    {
        $this->seed('old');
        $this->replacements->link('old', 'new');
        self::assertSame('old', $this->service->status()['repairs'][0]['sourceId']);
        $this->service->undo('old');
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['dismissedCount']);
    }

    public function testMultipleCopiesAreSuggestionsAndProviderConflictIsRejected(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie',
            'providers' => ['tmdb' => '123', 'imdb' => 'tt123'], 'year' => 2020, 'library' => 'Movies']);
        $this->live = ['copy-one' => $this->movie('copy-one'), 'copy-two' => $this->movie('copy-two'),
            'conflict' => $this->movie('conflict', ['Tmdb' => '123', 'Imdb' => 'tt999'])];
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertEqualsCanonicalizing(['copy-one', 'copy-two'],
            array_column($this->service->status()['suggestions'][0]['candidates'], 'id'));
    }

    public function testRejectingAContaminatedCandidateDoesNotCreateFalseUniqueness(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => 'conflicting-session', 'itemId' => 'unsafe', 'itemType' => 'Episode',
            'itemName' => 'Episode', 'library' => 'Movies', 'libraryResolved' => true,
            'playMethod' => 'DirectPlay', 'watchedSec' => 300, 'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-10-11 12:00:00'));
        $this->live = ['safe' => $this->movie('safe'), 'unsafe' => $this->movie('unsafe')];
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(['safe'], array_column($this->service->status()['suggestions'][0]['candidates'], 'id'));
    }

    public function testMetadataOutageDoesNotProveDeletionAndRetriesWithoutDroppingEvidence(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->live = ['new' => $this->movie('new')];
        $this->override = static function (string $path): mixed {
            if (str_contains($path, 'Ids=old')) {
                throw new \RuntimeException('private outage detail');
            }
            return null;
        };
        $this->service->runBatch();
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(['tmdb' => '123'], $this->identities->get('old')['providers']);
        self::assertStringNotContainsString('private outage', $this->service->status()['message']);
        $count = count($this->requests);
        $this->service->runBatch();
        self::assertCount($count, $this->requests);
    }

    public function testPartialOrOverlappingInventoryCannotAuthorizeRecovery(): void
    {
        $this->seed('old');
        for ($i = 0; $i < 101; ++$i) {
            $id = 'movie-' . $i;
            $this->live[$id] = $this->movie($id);
        }
        $this->service->runBatch();
        self::assertSame(0, $this->service->status()['suggestionCount']);
        $this->override = fn (string $path): mixed => str_contains($path, 'StartIndex=100')
            ? ['Items' => [$this->movie('movie-0')], 'TotalRecordCount' => 101] : null;
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testLateProviderMetadataCanEnableRecovery(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old', [])];
        $this->settle();
        self::assertSame([], $this->identities->get('old')['providers']);
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        self::assertSame(['imdb' => 'tt123', 'tmdb' => '123'], $this->identities->get('old')['providers']);
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testRawPartialPayloadPreservesIdentityAndWrongServerIsIgnored(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $before = $this->identities->get('old');
        $calls = count($this->requests);
        $this->service->captureSessions([['NowPlayingItem' => ['Id' => 'old', 'Type' => 'Movie', 'ServerId' => $this->server]],
            ['NowPlayingItem' => $this->movie('wrong') + ['ServerId' => 'another-server']]]);
        self::assertSame($before['providers'], $this->identities->get('old')['providers']);
        self::assertSame($before['year'], $this->identities->get('old')['year']);
        self::assertNull($this->identities->get('wrong'));
        self::assertCount($calls, $this->requests);
    }

    public function testFirstPlaybackCaptureSurvivesDeletionBeforeInitialServerProbe(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        self::assertSame([], $this->requests);
        self::assertSame($this->server, $this->identities->get('old')['serverId']);
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testProvisionalCaptureFromDifferentServerCannotAnchorOldEvidenceToNewServer(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => 'old-server']]]);
        $this->replacements->link('old', 'new');
        self::assertSame('blocked', $this->service->runBatch()['state']);
        self::assertTrue($this->identities->state()['serverMismatch']);
        self::assertSame('old', $this->replacements->resolve('old'));
    }

    public function testAuthoritativeRemovedProviderDoesNotLeaveStaleIdentity(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live['old']['ProviderIds'] = [];
        $this->now += 301;
        $this->settle();
        self::assertSame([], $this->identities->get('old')['providers']);
        self::assertTrue($this->identities->get('old')['conflicted']);
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testProviderCorrectionSupersedesOldRevisionAndBlocksAutomaticRepair(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live['old']['ProviderIds'] = ['Tmdb' => '999'];
        $this->now += 301;
        $this->settle();
        self::assertSame(['tmdb' => '999'], $this->identities->get('old')['providers']);
        self::assertTrue($this->identities->get('old')['conflicted']);
        $this->live = ['new' => $this->movie('new', ['Tmdb' => '999'])];
        $this->now += 301;
        $this->settle();
        self::assertSame('old', $this->replacements->resolve('old'));
    }

    public function testIncomingAliasIdentityConflictPreventsAutomaticChainAdvance(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '999']]);
        $this->put('middle', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->replacements->link('old', 'middle');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
    }

    public function testUpstreamProviderConflictCannotBeHiddenByMissingIntermediateProvider(): void
    {
        $this->seed('old');
        $this->put('old', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['imdb' => 'tt111']]);
        $this->put('middle', ['serverId' => $this->server, 'title' => 'Example Movie', 'providers' => ['tmdb' => '123']]);
        $this->replacements->link('old', 'middle');
        $this->live = ['new' => $this->movie('new', ['Tmdb' => '123', 'Imdb' => 'tt222'])];
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
        self::assertSame(0, $this->service->status()['suggestionCount']);
    }

    public function testLeaseExpiryDuringNetworkCannotWriteAlias(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        $this->live = ['new' => $this->movie('new')];
        $this->service->runBatch();
        $this->service->runBatch();
        $this->service->runBatch();
        $this->override = function (string $path): mixed {
            if (str_contains($path, 'Ids=new')) {
                $this->now += 121;
            }
            return null;
        };
        self::assertSame('unavailable', $this->service->runBatch()['state']);
        self::assertSame('old', $this->replacements->resolve('old'));
        self::assertArrayNotHasKey('repairAfter', $this->identities->get('old'));
    }

    public function testServerUuidChangeIsPermanentlyBlocked(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->server = 'server-two';
        $this->now += 301;
        self::assertSame('blocked', $this->service->runBatch()['state']);
        self::assertTrue($this->identities->state()['serverMismatch']);
        $this->server = 'server-one';
        self::assertSame('blocked', $this->service->runBatch()['state']);
        $this->expectException(\RuntimeException::class);
        $this->service->confirm('old', 'new');
    }

    public function testDismissAndResumeAreReversibleWithoutAutomaticRecreation(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        $this->service->dismiss('old');
        $this->now += 301;
        $this->settle();
        self::assertSame(0, $this->service->status()['suggestionCount']);
        self::assertSame(1, $this->service->status()['dismissedCount']);
        $this->service->resume('old');
        $this->settle();
        self::assertSame(1, $this->service->status()['suggestionCount']);
    }

    public function testUnplayedIntermediateRecoversAndUndoRestoresIncomingAliases(): void
    {
        $this->seed('old');
        $this->live = ['old' => $this->movie('old')];
        $this->settle();
        $this->live = ['middle' => $this->movie('middle')];
        $this->now += 301;
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
        self::assertSame('new', $this->replacements->resolve('middle'));
        $this->service->undo('middle');
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertSame('middle', $this->replacements->resolve('middle'));
        $this->now += 301;
        $this->settle();
        self::assertSame('middle', $this->replacements->resolve('old'));
        self::assertTrue($this->identities->get('old')['suppressed']);
        self::assertTrue($this->identities->get('middle')['suppressed']);
        $this->service->resume('old');
        $this->settle();
        self::assertSame('new', $this->replacements->resolve('old'));
    }

    public function testLeaseCollisionDoesNotCallJellyfinOrMutateData(): void
    {
        $other = new MovieIdentityRepository($this->database, 'test-server');
        $lease = $other->acquireLease($this->now);
        self::assertNotNull($lease);
        self::assertSame('busy', $this->service->runBatch()['state']);
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old')]]);
        self::assertSame([], $this->requests);
        $other->releaseLease($lease);
    }

    public function testConfirmRevalidatesTargetAndRejectsUnOfferedChoice(): void
    {
        $this->seed('old');
        $this->live = ['new' => $this->movie('new')];
        $this->settle();
        unset($this->live['new']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer available');
        $this->service->confirm('old', 'new');
    }

    public function testUndoEarlierRepairRejectsChangedGroupWithoutTouchingLatestRepair(): void
    {
        $this->seed('old');
        $this->service->captureSessions([['NowPlayingItem' => $this->movie('old') + ['ServerId' => $this->server]]]);
        $this->live = ['middle' => $this->movie('middle')];
        $this->settle();
        $this->live = ['new' => $this->movie('new')];
        $this->now += 301;
        $this->settle();
        self::assertSame(['middle'], array_column($this->service->status()['repairs'], 'sourceId'));
        try {
            $this->service->undo('old');
            self::fail('Undoing an obsolete group must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('later repair changed', $error->getMessage());
        }
        self::assertSame('new', $this->replacements->resolve('old'));
        $this->service->undo('middle');
        self::assertSame('middle', $this->replacements->resolve('old'));
    }

    /** @return array<string, mixed> */
    private function movie(string $id, array $providers = ['Tmdb' => '123', 'Imdb' => 'tt123']): array
    {
        return ['Id' => $id, 'Type' => 'Movie', 'Name' => 'Example Movie', 'ProductionYear' => 2020,
            'ProviderIds' => $providers, 'Path' => '/media/movies/' . $id . '/movie.mkv'];
    }

    private function seed(string $id): void
    {
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => 'session-' . bin2hex(random_bytes(4)), 'itemId' => $id, 'itemType' => 'Movie', 'itemName' => 'Example Movie',
            'library' => 'Movies', 'libraryResolved' => true, 'playMethod' => 'DirectPlay',
            'watchedSec' => 300, 'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-10-11 12:00:00'));
    }

    private function settle(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $result = $this->service->runBatch();
            if ($result['state'] === 'unavailable' || $result['state'] === 'blocked') {
                self::fail('Unexpected recovery state: ' . json_encode($this->identities->state()));
            }
            if ($result['state'] === 'ready' && $this->identities->state()['inventoryStable']) {
                return;
            }
        }
        self::fail('Recovery did not finish its bounded cycle.');
    }

    /** @param array<string, mixed> $data */
    private function put(string $id, array $data): void
    {
        $lease = $this->identities->acquireLease($this->now);
        self::assertNotNull($lease);
        $this->identities->put($id, $data);
        $this->identities->releaseLease($lease);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(static function (\Dibi\Row $row): array {
            $values = $row->toArray();
            foreach ($values as $key => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $values[$key] = $value->format('Y-m-d H:i:s.uP');
                }
            }
            return $values;
        }, $this->connection->select('*')->from('play_history')->orderBy('id')->fetchAll());
    }
}
