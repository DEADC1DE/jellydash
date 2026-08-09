<?php

declare(strict_types=1);

use Mk\Framework\AppSettings;
use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use Mk\Framework\Push\PushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class SchemaCompatibilityTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_schema_';

    private string $databaseName = '';
    private \Dibi\Connection $admin;
    private \Dibi\Connection $dibi;
    private Database $database;

    protected function setUp(): void
    {
        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
        ];

        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }

        try {
            $this->admin = new \Dibi\Connection($config);
            $this->databaseName = self::DATABASE_PREFIX . getmypid() . '_' . bin2hex(random_bytes(4));
            $this->assertSafeDatabaseName($this->databaseName);
            $this->admin->query(
                'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->databaseName
            );

            $config['database'] = $this->databaseName;
            $this->dibi = new \Dibi\Connection($config);
            $this->database = $this->databaseUsing($this->dibi);
        } catch (\Throwable $e) {
            $this->dropTemporaryDatabase();
            if (Config::env() === 'testing') {
                throw $e;
            }
            $this->markTestSkipped('Temporary database unavailable: ' . $e->getMessage());
        }

        Container::reset();
        Container::set('db', $this->database);
        $this->resetSchemaState();
    }

    protected function tearDown(): void
    {
        Container::reset();
        $this->resetSchemaState();
        $this->dropTemporaryDatabase();
    }

    public function testFreshDatabaseCreatesEveryOwnedTableWithoutSqlImport(): void
    {
        $this->initializeAllSchemas();

        $this->assertSame([
            'app_settings',
            'login_attempts',
            'play_history',
            'push_subscriptions',
            'seerr_requests',
            'users',
        ], $this->tableNames());
    }

    public function testExistingRowsSurviveSchemaInitialization(): void
    {
        $this->initializeAllSchemas();

        $this->dibi->insert('users', [
            'username' => 'schema-user',
            'password' => 'not-used',
            'name' => 'Schema User',
            'role' => 2,
        ])->execute();
        $this->dibi->insert('login_attempts', [
            'identifier' => 'schema-user|127.0.0.1',
            'attempts' => 2,
            'locked_until' => null,
            'updated_at' => '2026-08-09 12:00:00',
        ])->execute();
        $this->dibi->insert('play_history', [
            'session_key' => 'schema-session',
            'item_id' => 'schema-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-09 12:00:00',
            'updated_at' => '2026-08-09 12:05:00',
        ])->execute();
        $this->dibi->insert('push_subscriptions', [
            'endpoint' => 'https://push.example.test/schema',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/schema'),
            'p256dh' => 'schema-key',
            'auth' => 'schema-auth',
            'failure_count' => 0,
            'created_at' => '2026-08-09 12:00:00',
        ])->execute();
        $this->dibi->insert('seerr_requests', [
            'request_id' => 9001,
            'media_type' => 'movie',
            'tmdb_id' => 42,
            'title' => 'Schema Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-08-09 12:00:00',
            'notified' => 0,
            'created_at' => '2026-08-09 12:00:00',
        ])->execute();

        // This matches an install from before playback notifications existed.
        $this->dibi->query('ALTER TABLE `play_history` DROP COLUMN `notified`');

        $this->resetSchemaState();
        $this->initializeAllSchemas();

        $this->assertSame('schema-user', (string) $this->dibi->select('username')->from('users')->fetchSingle());
        $this->assertSame(2, (int) $this->dibi->select('attempts')->from('login_attempts')->fetchSingle());
        $this->assertSame(300, (int) $this->dibi->select('watched_sec')->from('play_history')->fetchSingle());
        $this->assertSame(1, (int) $this->dibi->select('notified')->from('play_history')->fetchSingle());
        $this->assertSame('ok', AppSettings::get('schema_test'));
        $this->assertSame(1, (int) $this->dibi->select('COUNT(*)')->from('push_subscriptions')->fetchSingle());
        $this->assertSame('Schema Movie', (string) $this->dibi->select('title')->from('seerr_requests')->fetchSingle());
    }

    private function initializeAllSchemas(): void
    {
        $this->database->ensureAuthSchema();
        AppSettings::set('schema_test', 'ok');
        new PlayHistoryRepository($this->database);
        new PushSubscriptionRepository($this->database);
        new SeerrRequestRepository($this->database);
    }

    /** @return array<int, string> */
    private function tableNames(): array
    {
        $tables = [];
        foreach ($this->dibi->query('SHOW TABLES')->fetchAll() as $row) {
            $values = array_values($row->toArray());
            $tables[] = (string) $values[0];
        }

        sort($tables);

        return $tables;
    }

    private function databaseUsing(\Dibi\Connection $connection): Database
    {
        $reflection = new \ReflectionClass(Database::class);
        $database = $reflection->newInstanceWithoutConstructor();
        $this->assertInstanceOf(Database::class, $database);
        $reflection->getProperty('dibi')->setValue($database, $connection);

        return $database;
    }

    private function resetSchemaState(): void
    {
        $properties = [
            [AppSettings::class, 'cache', null],
            [AppSettings::class, 'schemaEnsured', false],
            [PlayHistoryRepository::class, 'schemaEnsured', false],
            [PushSubscriptionRepository::class, 'schemaEnsured', false],
            [SeerrRequestRepository::class, 'schemaEnsured', false],
        ];

        foreach ($properties as [$class, $property, $value]) {
            (new \ReflectionClass($class))->getProperty($property)->setValue(null, $value);
        }
    }

    private function dropTemporaryDatabase(): void
    {
        if (!isset($this->admin) || $this->databaseName === '') {
            return;
        }

        $this->assertSafeDatabaseName($this->databaseName);
        $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
        $this->databaseName = '';
    }

    private function assertSafeDatabaseName(string $name): void
    {
        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $name) !== 1) {
            throw new \RuntimeException('Refusing to use an unsafe temporary database name.');
        }
    }
}
