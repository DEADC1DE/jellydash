<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\DatabaseSchemaInitializer;
use Mk\Framework\Migration\MariaDbToSqliteMigrator;
use PHPUnit\Framework\TestCase;

final class MariaDbToSqliteMigratorTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_migration_';

    private string $databaseName = '';
    private string $destinationPath = '';
    private \Dibi\Connection $admin;
    private \Dibi\Connection $sourceConnection;
    private Database $source;

    protected function setUp(): void
    {
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->markTestSkipped('This migration test requires a MariaDB source.');
        }

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
            $this->admin->query(
                'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $this->databaseName,
            );

            $config['database'] = $this->databaseName;
            $this->sourceConnection = new \Dibi\Connection($config);
            $this->source = new Database($this->sourceConnection);
        } catch (\Throwable $e) {
            $this->dropTemporaryDatabase();
            if (Config::env() === 'testing') {
                throw $e;
            }
            $this->markTestSkipped('Temporary MariaDB database unavailable: ' . $e->getMessage());
        }

        $this->destinationPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'jellydash_migration_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        $this->removeDestination();
        $this->dropTemporaryDatabase();
    }

    public function testCopiesAndVerifiesEveryOwnedTableWithoutChangingMariaDb(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->seedSource();

        $result = $this->runConsoleMigration(true);
        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $this->assertStringContainsString('Migration verified successfully', $result['output']);
        $this->assertStringContainsString('play_history: 1 row(s)', $result['output']);
        $this->assertFileExists($this->destinationPath);

        $destination = Database::sqlite($this->destinationPath);
        $sqlite = $destination->getDibi();
        $this->assertSame(101, (int) $sqlite->select('id')->from('users')->fetchSingle());
        $this->assertSame('migration-value', (string) $sqlite->select('setting_value')->from('app_settings')->fetchSingle());
        $this->assertSame(104, (int) $sqlite->select('id')->from('play_history')->fetchSingle());
        $this->assertSame(1, (int) $sqlite->select('notified')->from('play_history')->fetchSingle());
        $this->assertSame('Migration Movie', (string) $sqlite->select('title')->from('seerr_requests')->fetchSingle());
        $this->assertSame(102, $destination->addAuthUser('after-migration', 'password-123', 'After Migration', 2));
        $sqlite->disconnect();

        foreach (['users', 'login_attempts', 'app_settings', 'play_history', 'push_subscriptions', 'seerr_requests'] as $table) {
            $this->assertSame(1, (int) $this->sourceConnection->select('COUNT(*)')->from($table)->fetchSingle());
        }
    }

    public function testConsoleCommandRequiresStoppedConfirmation(): void
    {
        $result = $this->runConsoleMigration(false);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('--confirm-stopped', $result['error']);
        $this->assertFileDoesNotExist($this->destinationPath);
    }

    public function testMigrationCrossesTheBatchBoundary(): void
    {
        DatabaseSchemaInitializer::initialize($this->source);
        $this->sourceConnection->begin();
        for ($row = 1; $row <= 501; ++$row) {
            $this->sourceConnection->insert('app_settings', [
                'setting_key' => sprintf('batch-%03d', $row),
                'setting_value' => "value-{$row}",
                'updated_at' => '2026-08-11 12:00:00',
            ])->execute();
        }
        $this->sourceConnection->commit();

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(501, $counts['app_settings']);

        $destination = Database::sqlite($this->destinationPath);
        $this->assertSame(501, (int) $destination->getDibi()
            ->select('COUNT(*)')->from('app_settings')->fetchSingle());
        $destination->getDibi()->disconnect();
    }

    public function testOlderHistoryWithoutNotificationColumnIsMigratedSafely(): void
    {
        $this->sourceConnection->query(
            'CREATE TABLE `play_history` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `session_key` varchar(128) NOT NULL,
                `item_id` varchar(64) NOT NULL,
                `item_type` varchar(16) NOT NULL,
                `play_method` varchar(32) NOT NULL,
                `watched_sec` int NOT NULL DEFAULT 0,
                `runtime_sec` int NOT NULL DEFAULT 0,
                `started_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `is_finished` tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_session_item` (`session_key`, `item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->sourceConnection->insert('play_history', [
            'id' => 77,
            'session_key' => 'legacy-session',
            'item_id' => 'legacy-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:10:00',
            'is_finished' => 0,
        ])->execute();

        $counts = (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
        $this->assertSame(0, $counts['users']);
        $this->assertSame(1, $counts['play_history']);

        $destination = Database::sqlite($this->destinationPath);
        $row = $destination->getDibi()->select('id, notified')->from('play_history')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(77, (int) $row['id']);
        $this->assertSame(1, (int) $row['notified']);
        $destination->getDibi()->disconnect();
    }

    public function testFailedCopyRemovesDestinationAndLeavesSourceUntouched(): void
    {
        $this->sourceConnection->query(
            'CREATE TABLE `users` (
                `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `username` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `name` varchar(100) NOT NULL,
                `role` tinyint(4) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        foreach ([1, 2] as $id) {
            $this->sourceConnection->insert('users', [
                'id' => $id,
                'username' => 'duplicate-user',
                'password' => 'password-hash',
                'name' => "Duplicate {$id}",
                'role' => 2,
            ])->execute();
        }

        try {
            (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
            $this->fail('The incompatible source should make the copy fail.');
        } catch (\Dibi\UniqueConstraintViolationException) {
        }

        $this->assertFileDoesNotExist($this->destinationPath);
        $this->assertSame(2, (int) $this->sourceConnection->select('COUNT(*)')->from('users')->fetchSingle());
    }

    public function testRefusesToOverwriteAnExistingDestination(): void
    {
        file_put_contents($this->destinationPath, 'keep me');

        try {
            (new MariaDbToSqliteMigrator($this->source))->migrate($this->destinationPath);
            $this->fail('An existing destination must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame('keep me', file_get_contents($this->destinationPath));
    }

    private function seedSource(): void
    {
        $this->sourceConnection->insert('users', [
            'id' => 101,
            'username' => 'migration-user',
            'password' => 'password-hash',
            'name' => 'Migration User',
            'role' => 2,
        ])->execute();
        $this->sourceConnection->insert('login_attempts', [
            'id' => 102,
            'identifier' => 'migration-user|127.0.0.1',
            'attempts' => 3,
            'locked_until' => null,
            'updated_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('app_settings', [
            'setting_key' => 'migration-key',
            'setting_value' => 'migration-value',
            'updated_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('play_history', [
            'id' => 104,
            'session_key' => 'migration-session',
            'item_id' => 'migration-item',
            'item_type' => 'Movie',
            'item_name' => 'Migration Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-11 12:00:00',
            'updated_at' => '2026-08-11 12:05:00',
            'notified' => 1,
        ])->execute();
        $this->sourceConnection->insert('push_subscriptions', [
            'id' => 105,
            'endpoint' => 'https://push.example.test/migration',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/migration'),
            'p256dh' => 'migration-key',
            'auth' => 'migration-auth',
            'failure_count' => 0,
            'created_at' => '2026-08-11 12:00:00',
        ])->execute();
        $this->sourceConnection->insert('seerr_requests', [
            'id' => 106,
            'request_id' => 9001,
            'media_type' => 'movie',
            'tmdb_id' => 42,
            'title' => 'Migration Movie',
            'request_status' => 1,
            'media_status' => 2,
            'is_4k' => 0,
            'requested_at' => '2026-08-11 12:00:00',
            'notified' => 1,
            'created_at' => '2026-08-11 12:00:00',
        ])->execute();
    }

    private function dropTemporaryDatabase(): void
    {
        if (!isset($this->admin) || $this->databaseName === '') {
            return;
        }

        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $this->databaseName) !== 1) {
            throw new \RuntimeException('Refusing to drop an unsafe temporary database name.');
        }

        if (isset($this->sourceConnection) && $this->sourceConnection->isConnected()) {
            $this->sourceConnection->disconnect();
        }
        $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
        $this->databaseName = '';
    }

    /** @return array{exitCode: int, output: string, error: string} */
    private function runConsoleMigration(bool $confirmStopped): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_DRIVER' => (string) DATABASE_DRIVER_DIBI,
            'DB_HOST' => (string) DATABASE_HOST,
            'DB_PORT' => (string) DATABASE_PORT,
            'DB_NAME' => $this->databaseName,
            'DB_USER' => (string) DATABASE_USERNAME,
            'DB_PASS' => (string) DATABASE_PASSWORD,
        ]);

        $arguments = [
            PHP_BINARY,
            ROOT_DIR . '/bin/console.php',
            'database:migrate-to-sqlite',
            $this->destinationPath,
        ];
        if ($confirmStopped) {
            $arguments[] = '--confirm-stopped';
        }

        $pipes = [];
        $process = proc_open(
            $arguments,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        $this->assertIsResource($process);

        $output = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'output' => $output,
            'error' => $error,
        ];
    }

    private function removeDestination(): void
    {
        if ($this->destinationPath === '') {
            return;
        }

        @unlink($this->destinationPath . '-shm');
        @unlink($this->destinationPath . '-wal');
        @unlink($this->destinationPath);
    }
}
