<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;
use Mk\Framework\DatabaseSchemaInitializer;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class DownloadStorageDatabaseTest extends TestCase
{
    private const DATABASE_PREFIX = 'jellydash_phpunit_downloads_';

    private string $databaseName = '';
    private string $databasePath = '';
    private string $keyPath = '';
    private ?\Dibi\Connection $admin = null;
    private Database $database;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jellydash-integration-key-' . bin2hex(random_bytes(8));
        if (DatabasePlatform::isSqliteDriver(DATABASE_DRIVER_DIBI)) {
            $this->databasePath = sys_get_temp_dir() . '/jellydash-storage-driver-' . bin2hex(random_bytes(8)) . '.sqlite';
            $this->database = Database::sqlite($this->databasePath);

            return;
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
            $this->database = new Database(new \Dibi\Connection($config));
        } catch (\Throwable $e) {
            $this->dropTemporaryDatabase();
            if (Config::env() === 'testing') {
                throw $e;
            }
            $this->markTestSkipped('Temporary database unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->getDibi()->isConnected()) {
            $this->database->getDibi()->disconnect();
        }
        $this->dropTemporaryDatabase();
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm', $this->keyPath, $this->keyPath . '.lock'] as $path) {
            if ($path !== '') {
                @unlink($path);
            }
        }
        $this->environment('SAB_API_URL', null);
        $this->environment('SAB_API_KEY', null);
    }

    public function testCredentialsAndSessionsSurviveContainerReplacementWithOnlyTheDatabase(): void
    {
        $connections = new ConnectionRepository($this->database, new CredentialStore($this->keyPath));
        $downloads = new DownloadRepository($this->database, new CredentialStore($this->keyPath));
        $connection = $connections->save(new Connection(
            'replacement-client', 'qbittorrent', 'Replacement client', 'https://replacement.example.test', 'admin',
        ), 'replacement-secret');
        $token = $downloads->claim($connection, 1_800_100_000);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token, new CollectionBatch(
            session: ['sid' => 'replacement-session'],
        ), 1_800_100_001));
        self::assertFileDoesNotExist($this->keyPath);
        self::assertFileDoesNotExist($this->keyPath . '.lock');

        $db = $this->database->getDibi();
        $credentialRow = (string) $db->select('credential_envelope')->from('integration_connections')
            ->where('id = %s', $connection->id)->fetchSingle();
        $sessionRow = (string) $db->select('session_envelope')->from('download_connection_state')
            ->where('connection_id = %s', $connection->id)->fetchSingle();
        self::assertSame(2, json_decode($credentialRow, true, flags: JSON_THROW_ON_ERROR)['v']);
        self::assertSame(2, json_decode($sessionRow, true, flags: JSON_THROW_ON_ERROR)['v']);

        $replacementStore = new CredentialStore($this->keyPath . '-different');
        $replacementConnections = new ConnectionRepository($this->database, $replacementStore);
        $replacementDownloads = new DownloadRepository($this->database, $replacementStore);
        $loaded = $replacementConnections->find($connection->id);
        self::assertNotNull($loaded);
        self::assertSame('replacement-secret', $replacementConnections->credentials($loaded)['secret']);
        self::assertSame(['sid' => 'replacement-session'], $replacementDownloads->session($loaded));
        self::assertStringNotContainsString('replacement-secret', json_encode($loaded->managementData(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('replacement-session', json_encode($replacementDownloads->state($loaded->id), JSON_THROW_ON_ERROR));
        self::assertFileDoesNotExist($this->keyPath . '-different');
    }

    public function testExistingEncryptedRowsMigrateWhenTheOldKeyIsAvailable(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $connection = $connections->save(new Connection(
            'legacy-migration-client', 'qbittorrent', 'Legacy client', 'https://legacy.example.test', 'admin',
            enabled: false,
        ), 'current-secret');
        $db = $this->database->getDibi();
        $db->update('integration_connections', ['credential_envelope' => $this->legacyEnvelope(
            'old-secret', 'connection:' . $connection->id . ':' . $connection->provider,
        )])->where('id = %s', $connection->id)->execute();

        // all() also visits disabled connections, before their old key disappears on an update.
        $connections->all();
        $migrated = (string) $db->select('credential_envelope')->from('integration_connections')
            ->where('id = %s', $connection->id)->fetchSingle();
        self::assertSame(2, json_decode($migrated, true, flags: JSON_THROW_ON_ERROR)['v']);
        unlink($this->keyPath);
        self::assertSame('old-secret', (new ConnectionRepository($this->database, new CredentialStore($this->keyPath)))
            ->credentials($connection)['secret']);

        $active = $connections->save(new Connection(
            'legacy-session-client', 'qbittorrent', 'Session client', 'https://session.example.test', 'admin',
        ), 'session-secret');
        $token = $downloads->claim($active, 1_800_100_000);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($active, $token, new CollectionBatch(session: ['sid' => 'new']), 1_800_100_001));
        $db->update('download_connection_state', ['session_envelope' => $this->legacyEnvelope(
            '{"sid":"old"}', sprintf('session:%s:%s:%d', $active->id, $active->provider, $active->revision),
        )])->where('connection_id = %s', $active->id)->execute();
        self::assertSame(['sid' => 'old'], $downloads->session($active));
        unlink($this->keyPath);
        self::assertSame(['sid' => 'old'], (new DownloadRepository($this->database, new CredentialStore($this->keyPath)))
            ->session($active));
    }

    public function testMissingOldKeyAffectsOnlyItsClientAndDisposableSession(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $old = $connections->save(new Connection(
            'missing-key-client', 'qbittorrent', 'Missing key', 'https://missing.example.test', 'admin',
        ), 'old-secret');
        $db = $this->database->getDibi();
        $db->update('integration_connections', ['credential_envelope' => $this->legacyEnvelope(
            'old-secret', 'connection:' . $old->id . ':' . $old->provider,
        )])->where('id = %s', $old->id)->execute();
        $token = $downloads->claim($old, 1_800_100_000);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($old, $token, new CollectionBatch(session: ['sid' => 'new']), 1_800_100_001));
        $db->update('download_connection_state', ['session_envelope' => $this->legacyEnvelope(
            '{"sid":"old"}', sprintf('session:%s:%s:%d', $old->id, $old->provider, $old->revision),
        )])->where('connection_id = %s', $old->id)->execute();
        unlink($this->keyPath);

        $fresh = new ConnectionRepository($this->database, new CredentialStore($this->keyPath));
        $new = $fresh->save(new Connection(
            'new-client', 'qbittorrent', 'New client', 'https://new.example.test', 'admin',
        ), 'new-secret');
        self::assertSame('new-secret', $fresh->credentials($new)['secret']);
        self::assertSame([], (new DownloadRepository($this->database, new CredentialStore($this->keyPath)))->session($old));
        self::assertNull($db->select('session_envelope')->from('download_connection_state')
            ->where('connection_id = %s', $old->id)->fetchSingle());

        $repaired = $fresh->save(new Connection(
            $old->id, $old->provider, $old->name, $old->url, $old->username,
            revision: $old->revision, hasSecret: true,
        ), 'replacement-secret', $old->revision);
        self::assertSame('replacement-secret', $fresh->credentials($repaired)['secret']);
        self::assertFileDoesNotExist($this->keyPath);
    }

    public function testOversizedOldEnvelopeDoesNotBlockOtherClients(): void
    {
        $connections = new ConnectionRepository($this->database, new CredentialStore($this->keyPath));
        $broken = $connections->save(new Connection(
            'broken-old-client', 'qbittorrent', 'Broken old client', 'https://broken.example.test', 'admin',
        ), 'old-secret');
        $healthy = $connections->save(new Connection(
            'healthy-client', 'qbittorrent', 'Healthy client', 'https://healthy.example.test', 'admin',
        ), 'healthy-secret');
        $this->database->getDibi()->update('integration_connections', [
            'credential_envelope' => json_encode(['v' => 1, 'pad' => str_repeat('x', 1048576)], JSON_THROW_ON_ERROR),
        ])->where('id = %s', $broken->id)->execute();

        $replacement = new ConnectionRepository($this->database, new CredentialStore($this->keyPath . '-new'));
        self::assertCount(2, $replacement->all());
        self::assertNotNull($replacement->find($broken->id));
        self::assertSame('healthy-secret', $replacement->credentials($healthy)['secret']);
        $added = $replacement->save(new Connection(
            'another-client', 'qbittorrent', 'Another client', 'https://another.example.test', 'admin',
        ), 'another-secret');
        self::assertSame('another-secret', $replacement->credentials($added)['secret']);
    }

    public function testConnectionLeaseCompletionAndCleanupUseTheConfiguredDriver(): void
    {
        DatabaseSchemaInitializer::initialize($this->database);
        DatabaseSchemaInitializer::initialize($this->database);
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);

        $connection = $connections->save(new Connection(
            'driver-case-client',
            'qbittorrent',
            'Driver case client',
            'https://driver.example.test',
            'admin',
        ), 'driver-secret');
        self::assertSame('driver-secret', $connections->credentials($connection)['secret']);

        $now = 1_800_100_000;
        $token = $downloads->claim($connection, $now);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token, new CollectionBatch(
            completions: [
                $this->completion('OpaqueID', $now - 2, 9_876_543_210),
                $this->completion('opaqueid', $now - 1, 9_876_543_211),
            ],
            speed: 1024.5,
            cursor: ['offset' => 100],
            session: ['sid' => 'driver-session', 'expires_at' => $now + 900],
        ), $now + 1));

        $history = $downloads->recent([$connection->id]);
        self::assertSame(['opaqueid', 'OpaqueID'], array_column($history, 'source_id'));
        self::assertSame(9_876_543_211, $history[0]['size']);
        self::assertSame(['sid' => 'driver-session', 'expires_at' => $now + 900], $downloads->session($connection));
        self::assertSame(['offset' => 100], $downloads->state($connection->id)['cursor']);

        $lease = $downloads->claim($connection, $now + 16);
        self::assertNotNull($lease);
        $disabled = $connections->save(new Connection(
            $connection->id,
            $connection->provider,
            $connection->name,
            $connection->url,
            $connection->username,
            enabled: false,
            revision: $connection->revision,
            hasSecret: true,
        ), null, $connection->revision);
        self::assertFalse($downloads->succeed($connection, $lease, new CollectionBatch(
            completions: [$this->completion('late', $now + 15, 1)],
        ), $now + 17));
        self::assertCount(2, $downloads->recent([$disabled->id]));

        $connections->delete($disabled->id, $disabled->revision);
        self::assertNull($downloads->state($disabled->id));
        self::assertSame([], $downloads->recent([$disabled->id]));
    }

    public function testExistingCompletionTableGainsOutcomeWithoutChangingRows(): void
    {
        DatabaseSchemaInitializer::initialize($this->database);
        $db = $this->database->getDibi();
        $db->query('ALTER TABLE `download_completions` DROP COLUMN `status`');
        $db->insert('download_completions', [
            'connection_id' => 'legacy-client',
            'source_id_digest' => hash('sha256', 'legacy-item'),
            'source_id' => 'legacy-item',
            'title' => 'Existing completion',
            'category' => '',
            'tags_json' => '[]',
            'size_bytes' => 50,
            'completed_at' => 1_800_000_000,
            'observed_at' => 1_800_000_001,
            'last_seen_at' => 1_800_000_001,
        ])->execute();

        DownloadRepository::ensureSchema($this->database);
        DownloadRepository::ensureSchema($this->database);

        $row = $db->select('source_id, status, completed_at')->from('download_completions')->fetch();
        self::assertNotNull($row);
        self::assertSame('legacy-item', (string) $row['source_id']);
        self::assertSame('completed', (string) $row['status']);
        self::assertSame(1_800_000_000, (int) $row['completed_at']);
    }

    public function testLongUnicodeCompletionCategorySurvivesFreshAndExistingSchema(): void
    {
        DatabaseSchemaInitializer::initialize($this->database);
        $db = $this->database->getDibi();
        if (!$this->database->getPlatform()->isSqlite()) {
            $db->query("ALTER TABLE `download_completions` MODIFY COLUMN `category` varchar(128) NOT NULL DEFAULT ''");
        }
        $db->insert('download_completions', [
            'connection_id' => 'legacy-client', 'source_id_digest' => hash('sha256', 'legacy-item'),
            'source_id' => 'legacy-item', 'title' => 'Existing completion', 'category' => 'old',
            'tags_json' => '[]', 'size_bytes' => 10, 'status' => 'completed',
            'completed_at' => 1_800_000_000, 'observed_at' => 1_800_000_001,
            'last_seen_at' => 1_800_000_001,
        ])->execute();

        DownloadRepository::ensureSchema($this->database);
        DownloadRepository::ensureSchema($this->database);
        self::assertSame('old', (string) $db->select('category')->from('download_completions')
            ->where('source_id = %s', 'legacy-item')->fetchSingle());

        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $category = str_repeat('ä', 129);
        $tag = str_repeat('t', 129);
        $connection = $connections->save(new Connection(
            'unicode-client', 'qbittorrent', 'Unicode client', 'https://unicode.example.test',
            'admin', filterMode: 'selected', categories: [$category], tags: [$tag],
        ), 'secret');
        $now = 1_800_100_000;
        $token = $downloads->claim($connection, $now);
        self::assertNotNull($token);
        $completion = $this->completion('unicode-item', $now - 1, 10);
        $completion['category'] = $category;
        $completion['tags'] = [$tag];
        self::assertTrue($downloads->succeed($connection, $token, new CollectionBatch(
            completions: [$completion],
        ), $now + 1));
        $recent = $downloads->recent([$connection->id]);
        self::assertSame($category, $recent[0]['category']);
        self::assertSame([$tag], $recent[0]['tags']);
    }

    public function testTerminalFailureOutcomeUsesTheConfiguredDriver(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $connection = $connections->save(new Connection(
            'driver-failure-client', 'sabnzbd', 'SAB failure client', 'https://sab.example.test',
        ), 'test-secret');
        $now = 1_800_100_000;
        $failure = $this->completion('failed-item', $now - 2, 50);
        $failure['state'] = 'failed';
        $failure['completed_at'] = null;
        $token = $downloads->claim($connection, $now);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token,
            new CollectionBatch(completions: [$failure]), $now + 1));
        self::assertSame('failed', $downloads->recent([$connection->id])[0]['status']);
        self::assertNull($downloads->recent([$connection->id])[0]['completed_at']);
        self::assertSame($now + 1, $downloads->recent([$connection->id])[0]['observed_at']);

        $token = $downloads->claim($connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token,
            new CollectionBatch(completions: [$failure]), $now + 17));
        self::assertSame($now + 1, $downloads->recent([$connection->id])[0]['observed_at']);

        $token = $downloads->claim($connection, $now + 32);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token,
            new CollectionBatch(completions: [$this->completion('failed-item', $now + 30, 50)]), $now + 33));
        self::assertSame('completed', $downloads->recent([$connection->id])[0]['status']);
        self::assertSame($now + 30, $downloads->recent([$connection->id])[0]['completed_at']);
    }

    public function testFilterAndEnvironmentDuplicateRulesUseTheConfiguredDriver(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $uncategorized = $connections->save(new Connection(
            'driver-uncategorized',
            'qbittorrent',
            'Uncategorized',
            'https://uncategorized.example.test',
            'admin',
            filterMode: 'selected',
            categories: [''],
            tags: [''],
        ), 'secret');
        self::assertSame([''], $uncategorized->categories);
        self::assertSame([], $uncategorized->tags);

        $this->environment('SAB_API_URL', 'https://environment.example.test/api');
        $this->environment('SAB_API_KEY', 'environment-key');
        $saved = $connections->save(new Connection(
            'driver-sab',
            'sabnzbd',
            'Saved SAB',
            'https://saved.example.test',
        ), 'saved-secret');
        try {
            $connections->save(new Connection(
                $saved->id,
                $saved->provider,
                $saved->name,
                'https://environment.example.test',
                revision: $saved->revision,
                hasSecret: true,
            ), 'replacement-secret', $saved->revision);
            self::fail('An edit must not duplicate the active environment fallback.');
        } catch (DomainException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        $environment = $connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($environment);
        $override = $connections->save(new Connection(
            $environment->id,
            $environment->provider,
            'Environment override',
            'https://override.example.test',
            revision: $environment->revision,
            source: $environment->source,
            hasSecret: true,
        ), 'override-secret', $environment->revision);
        $connections->save(new Connection(
            'driver-env-duplicate',
            'sabnzbd',
            'Saved environment endpoint',
            'https://environment.example.test',
        ), 'saved-secret');
        try {
            $connections->delete($override->id, $override->revision);
            self::fail('Removing an override must not reveal a duplicate environment fallback.');
        } catch (DomainException $e) {
            self::assertStringContainsString('environment client', $e->getMessage());
        }
    }

    public function testAdditionalProvidersPersistFilteredCompletionsAndServerOnlySessions(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $now = 1_800_100_000;
        foreach (['transmission', 'deluge', 'nzbget'] as $provider) {
            $saved = $connections->save(new Connection(
                'driver-' . $provider, $provider, $provider, 'https://' . $provider . '.example.test',
                $provider === 'deluge' ? '' : 'admin', filterMode: 'selected', categories: ['movies'],
            ), 'provider-secret');
            $loaded = $connections->find($saved->id);
            self::assertNotNull($loaded);
            self::assertSame($provider, $loaded->provider);
            self::assertSame(['movies'], $loaded->categories);
            self::assertSame('provider-secret', $connections->credentials($loaded)['secret']);
            $completion = $this->completion('native-id', $now - 1, 9_876_543_210);
            if ($provider === 'transmission') {
                $completion['category'] = 'other';
                $completion['tags'] = ['other', 'movies'];
            }
            $excluded = array_replace($completion, ['source_id' => 'excluded', 'category' => 'other', 'tags' => ['other']]);
            for ($i = 0; $i < 2; ++$i) {
                $token = $downloads->claim($loaded, $now + $i * 16);
                self::assertNotNull($token);
                self::assertTrue($downloads->succeed($loaded, $token, new CollectionBatch(
                    completions: [$completion, $excluded], speed: 0.0, session: ['session' => 'private-session'],
                ), $now + $i * 16 + 1));
            }
            self::assertSame(['native-id'], array_column($downloads->recent([$saved->id]), 'source_id'));
            self::assertSame(['session' => 'private-session'], $downloads->session($loaded));
            $envelope = (string) $this->database->getDibi()->select('session_envelope')->from('download_connection_state')
                ->where('connection_id = %s', $saved->id)->fetchSingle();
            self::assertSame(2, json_decode($envelope, true, flags: JSON_THROW_ON_ERROR)['v']);
            self::assertStringNotContainsString('private-session', json_encode($downloads->state($saved->id), JSON_THROW_ON_ERROR));
        }
    }

    public function testWarningResultIsRetainedAndReprocessingClearsIt(): void
    {
        $store = new CredentialStore($this->keyPath);
        $connections = new ConnectionRepository($this->database, $store);
        $downloads = new DownloadRepository($this->database, $store);
        $connection = $connections->save(new Connection('warning-client', 'nzbget', 'NZBGet', 'https://nzbget.example.test', 'admin'), 'secret');
        $now = 1_800_100_000;
        $warning = array_replace($this->completion('nzb-warning', $now - 2, 100), ['state' => 'warning']);
        $token = $downloads->claim($connection, $now);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token, new CollectionBatch(completions: [$warning]), $now + 1));
        self::assertSame('warning', $downloads->recent([$connection->id])[0]['status']);
        $token = $downloads->claim($connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($downloads->succeed($connection, $token, new CollectionBatch(
            items: [array_replace($warning, ['state' => 'processing', 'completed_at' => null])],
            completions: [$warning],
        ), $now + 17));
        self::assertSame([], $downloads->recent([$connection->id]));
    }

    private function legacyEnvelope(string $value, string $context): string
    {
        $key = is_file($this->keyPath) ? file_get_contents($this->keyPath) : random_bytes(32);
        self::assertIsString($key);
        if (!is_file($this->keyPath)) {
            file_put_contents($this->keyPath, $key);
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        self::assertIsString($ciphertext);

        return json_encode([
            'v' => 1, 'key' => substr(hash('sha256', $key), 0, 16),
            'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function completion(string $id, int $completedAt, int $size): array
    {
        return [
            'source_id' => $id,
            'title' => 'Completed ' . $id,
            'category' => 'movies',
            'tags' => [],
            'state' => 'completed',
            'progress' => 100.0,
            'size' => $size,
            'downloaded' => $size,
            'speed' => 0.0,
            'eta' => 0,
            'position' => null,
            'completed_at' => $completedAt,
        ];
    }

    private function dropTemporaryDatabase(): void
    {
        if ($this->admin === null || $this->databaseName === '') {
            return;
        }
        if (preg_match('/^' . self::DATABASE_PREFIX . '[a-z0-9_]+$/', $this->databaseName) !== 1) {
            throw new RuntimeException('Refusing to drop an unsafe temporary database name.');
        }
        $this->admin->query('DROP DATABASE IF EXISTS %n', $this->databaseName);
        $this->admin->disconnect();
        $this->databaseName = '';
    }

    private function environment(string $key, ?string $value): void
    {
        putenv($value === null ? $key : $key . '=' . $value);
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
