<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\MovieReplacementService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class MovieReplacementServiceTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_movie_service_';
    private Database $database;
    private \Dibi\Connection $connection;
    private ?\Dibi\Connection $admin = null;
    private string $databaseName = '';
    private string $sqlitePath = '';
    private MovieReplacements $replacements;

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'jellydash-movie-service-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
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
        $this->replacements = new MovieReplacements($this->database, 'test-server');
        new PlayHistoryRepository($this->database);
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

    public function testPreviewShowsRecordedEvidenceWithoutChangingHistoryOrMappings(): void
    {
        $this->seed('old-movie', 'Original title');
        $this->seed('old-movie', 'Earlier title');
        $before = $this->rows();
        $preview = $this->service()->preview('old-movie', 'current-movie');

        self::assertSame(2, $preview['sourcePlays']);
        self::assertEqualsCanonicalizing(['Original title', 'Earlier title'], $preview['sourceTitles']);
        self::assertSame('Current title', $preview['targetTitle']);
        self::assertSame(['Tmdb' => '12345', 'Imdb' => 'tt1234567'], $preview['targetProviderIds']);
        self::assertNull($preview['existingTarget']);
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
        self::assertSame($before, $this->rows());
    }

    public function testApplyRequiresExplicitSameMovieConfirmation(): void
    {
        $this->seed('old-movie');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--confirm-same-movie');
        $this->service()->apply('old-movie', 'current-movie');
    }

    public function testExactRecordedGuidSpellingIsPreservedInTheEffectiveMapping(): void
    {
        $storedId = 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA';
        $this->seed($storedId);
        $before = $this->rows();
        $preview = $this->service()->apply($storedId, 'current-movie', true);

        self::assertSame(1, $preview['sourcePlays']);
        self::assertSame($storedId, $preview['sourceId']);
        self::assertSame('current-movie', $this->replacements->resolve($storedId));
        self::assertContains($storedId, $this->replacements->members('current-movie'));
        self::assertSame($before, $this->rows());
        self::assertSame($storedId, $this->rows()[0]['item_id']);
    }

    public function testDifferentGuidSpellingCannotSelectRecordedSourceRows(): void
    {
        $this->seed('AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No recorded movie plays');
        $this->service()->apply('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'current-movie', true);
    }

    public function testAlternateTargetGuidUsesNativeJellyfinIdToUnifyRecordedPlays(): void
    {
        $nativeId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $targetArgument = 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB';
        $this->seed('old-movie');
        $this->seed($nativeId, 'Current title');
        $service = $this->service(static fn (string $id): array => ['Items' => $id === $targetArgument
            ? [['Id' => $nativeId, 'Type' => 'Movie', 'Name' => 'Current title']] : []]);
        $preview = $service->apply('old-movie', $targetArgument, true);

        self::assertSame($nativeId, $preview['targetId']);
        self::assertSame($nativeId, $this->replacements->resolve('old-movie'));
        self::assertEqualsCanonicalizing(['old-movie', $nativeId], $this->replacements->members($nativeId));
        self::assertSame(2, (int) $this->connection->select('COUNT(*)')->from('play_history')
            ->where('item_id IN %in', $this->replacements->members($nativeId))->fetchSingle());
    }

    public function testReplacementWithIncomingAliasesAndNoOwnPlaysCanBeReplacedAgain(): void
    {
        $this->seed('old-movie', 'First old title');
        $this->seed('another-old-movie', 'Second old title');
        $this->replacements->link('old-movie', 'unplayed-movie');
        $this->replacements->link('another-old-movie', 'unplayed-movie');
        $before = $this->rows();
        $preview = $this->service()->apply('unplayed-movie', 'current-movie', true);

        self::assertSame(2, $preview['sourcePlays']);
        self::assertEqualsCanonicalizing(['First old title', 'Second old title'], $preview['sourceTitles']);
        foreach (['old-movie', 'another-old-movie', 'unplayed-movie'] as $id) {
            self::assertSame('current-movie', $this->replacements->resolve($id));
            self::assertContains($id, $this->replacements->members('current-movie'));
        }
        self::assertSame($before, $this->rows());
    }

    public function testApplyAndRepeatedApplyPreserveEveryOriginalRowIncludingAlertState(): void
    {
        $this->seed('old-movie');
        $this->seed('current-movie');
        $before = $this->rows();
        $service = $this->service();
        $service->apply('old-movie', 'current-movie', true);
        $again = $service->apply('old-movie', 'current-movie', true);

        self::assertSame('current-movie', $this->replacements->resolve('old-movie'));
        self::assertSame('current-movie', $again['existingTarget']);
        self::assertSame($before, $this->rows());
    }

    public function testApplyRevalidatesAfterPreviewWhenTargetDisappears(): void
    {
        $this->seed('old-movie');
        $present = true;
        $service = $this->service(static function (string $id) use (&$present): array {
            return ['Items' => $present && $id === 'current-movie'
                ? [['Id' => $id, 'Type' => 'Movie', 'Name' => 'Current title']] : []];
        });
        $service->preview('old-movie', 'current-movie');
        $present = false;
        try {
            $service->apply('old-movie', 'current-movie', true);
            self::fail('Missing target should stop the repair.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('existing Jellyfin movie', $error->getMessage());
        }
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
    }

    public function testMissingOrNonMovieTargetIsRejected(): void
    {
        $this->seed('old-movie');
        foreach ([['Items' => []], ['Items' => [['Id' => 'current-movie', 'Type' => 'Episode']]]] as $payload) {
            try {
                $this->service(static fn (string $id): array => $payload)->apply('old-movie', 'current-movie', true);
                self::fail('Invalid target should stop the repair.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('existing Jellyfin movie', $error->getMessage());
            }
        }
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
    }

    public function testStillExistingSourceCannotBeMerged(): void
    {
        $this->seed('old-movie');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still exists');
        $this->service(static fn (string $id): array => ['Items' => [['Id' => $id, 'Type' => 'Movie']]])
            ->apply('old-movie', 'current-movie', true);
    }

    public function testMissingSourceHistoryIsRejectedBeforeCallingJellyfin(): void
    {
        $calls = 0;
        try {
            $this->service(static function (string $id) use (&$calls): array {
                ++$calls;
                return ['Items' => []];
            })->apply('old-movie', 'current-movie', true);
            self::fail('Missing source history should stop the repair.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('No recorded movie plays', $error->getMessage());
        }
        self::assertSame(0, $calls);
    }

    public function testSourceWithNonMovieHistoryIsRejected(): void
    {
        $this->seed('old-movie');
        $this->seed('old-movie', 'Episode title', 'Episode');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-movie history');
        $this->service()->apply('old-movie', 'current-movie', true);
    }

    public function testTargetWithNonMovieHistoryIsRejected(): void
    {
        $this->seed('old-movie');
        $this->seed('current-movie', 'Conflicting episode', 'Episode');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current ID also has non-movie history');
        $this->service()->apply('old-movie', 'current-movie', true);
    }

    public function testFailedSourceLookupIsNotTreatedAsDeleted(): void
    {
        $this->seed('old-movie');
        try {
            $this->service(static function (string $id): array {
                if ($id === 'old-movie') {
                    throw new \RuntimeException('private transport details');
                }
                return ['Items' => [['Id' => $id, 'Type' => 'Movie']]];
            })->apply('old-movie', 'current-movie', true);
            self::fail('An outage must stop the repair.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Could not verify', $error->getMessage());
            self::assertStringNotContainsString('private transport details', $error->getMessage());
        }
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
    }

    public function testMalformedOrAmbiguousLookupsCannotAuthorizeReplacement(): void
    {
        $this->seed('old-movie');
        foreach ([null, [], ['Items' => 'invalid'], ['Items' => [['Id' => 'wrong', 'Type' => 'Movie']]],
            ['Items' => [['Id' => 'current-movie', 'Type' => 'Movie'], ['Id' => 'current-movie', 'Type' => 'Movie']]],
            ['Items' => [], 'TotalRecordCount' => 1]] as $payload) {
            try {
                $client = new JellyfinClient(requester: static fn () => $payload);
                (new MovieReplacementService($this->database, $client, $this->replacements))
                    ->apply('old-movie', 'current-movie', true);
                self::fail('Malformed metadata must stop the repair.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('metadata', $error->getMessage());
            }
        }
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
    }

    public function testMalformedSourceLookupIsNotTreatedAsDeleted(): void
    {
        $this->seed('old-movie');
        try {
            $this->service(static fn (string $id): array => $id === 'current-movie'
                ? ['Items' => [['Id' => $id, 'Type' => 'Movie']]] : [])
                ->apply('old-movie', 'current-movie', true);
            self::fail('Malformed source metadata must stop the repair.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('invalid or ambiguous', $error->getMessage());
        }
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
    }

    public function testExistingConflictingMappingCannotBeOverwritten(): void
    {
        $this->seed('old-movie');
        $this->replacements->link('old-movie', 'other-movie');
        try {
            $this->service()->apply('old-movie', 'current-movie', true);
            self::fail('An existing mapping must not be overwritten.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Unlink it', $error->getMessage());
        }
        self::assertSame('other-movie', $this->replacements->resolve('old-movie'));
    }

    public function testUnlinkPreviewAndApplyLeavePlaybackUntouched(): void
    {
        $this->seed('old-movie');
        $service = $this->service();
        $service->apply('old-movie', 'current-movie', true);
        $before = $this->rows();
        self::assertSame(['sourceId' => 'old-movie', 'targetId' => 'current-movie', 'applied' => false], $service->unlink('old-movie'));
        self::assertSame('current-movie', $this->replacements->resolve('old-movie'));
        $service->unlink('old-movie', true);
        self::assertSame('old-movie', $this->replacements->resolve('old-movie'));
        self::assertSame($before, $this->rows());
    }

    /** @param (\Closure(string): array<string, mixed>)|null $loader */
    private function service(?\Closure $loader = null): MovieReplacementService
    {
        $loader ??= static fn (string $id): array => ['Items' => $id === 'current-movie'
            ? [['Id' => $id, 'Type' => 'Movie', 'Name' => 'Current title',
                'ProviderIds' => ['Tmdb' => '12345', 'Imdb' => 'tt1234567']]] : []];
        $client = new JellyfinClient(requester: static function (string $path, string $method) use ($loader): array {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('/Items?', $path);
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            return $loader((string) $query['Ids']);
        });

        return new MovieReplacementService($this->database, $client, $this->replacements);
    }

    private function seed(string $id, string $title = 'Original title', string $type = 'Movie'): void
    {
        (new PlayHistoryRepository($this->database))->logActiveStreams([[
            'id' => 'session-' . bin2hex(random_bytes(4)), 'itemId' => $id, 'itemType' => $type,
            'itemName' => $title, 'library' => 'Movies', 'libraryResolved' => true,
            'playMethod' => 'DirectPlay', 'watchedSec' => 300, 'runtimeSec' => 3600,
        ]], new \DateTimeImmutable('2026-10-11 12:00:00'));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(
            static function (\Dibi\Row $row): array {
                $values = $row->toArray();
                foreach ($values as $key => $value) {
                    if ($value instanceof \DateTimeInterface) {
                        $values[$key] = $value->format('Y-m-d H:i:s.uP');
                    }
                }

                return $values;
            },
            $this->connection->select('*')->from('play_history')->orderBy('id')->fetchAll()
        );
    }
}
