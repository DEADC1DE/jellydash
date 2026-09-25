<?php

declare(strict_types=1);

use Mk\Framework\Database;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;
use Mk\Framework\Integrations\CredentialStore;
use PHPUnit\Framework\TestCase;

final class DownloadConnectionRepositoryTest extends TestCase
{
    private string $databasePath;
    private string $keyPath;
    private Database $database;
    private ConnectionRepository $connections;
    private DownloadRepository $downloads;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/jellydash-connections-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->keyPath = sys_get_temp_dir() . '/jellydash-integration-key-' . bin2hex(random_bytes(8));
        $this->database = Database::sqlite($this->databasePath);
        $store = new CredentialStore($this->keyPath);
        $this->connections = new ConnectionRepository($this->database, $store);
        $this->downloads = new DownloadRepository($this->database, $store);
        $this->environment('SAB_API_URL', null);
        $this->environment('SAB_API_KEY', null);
        $this->environment('SAB_VERIFY_SSL', null);
    }

    protected function tearDown(): void
    {
        $this->database->getDibi()->disconnect();
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm', $this->keyPath, $this->keyPath . '.lock'] as $path) {
            @unlink($path);
        }
        $this->environment('SAB_API_URL', null);
        $this->environment('SAB_API_KEY', null);
        $this->environment('SAB_VERIFY_SSL', null);
    }

    public function testEnvironmentFallbackCanBeExplicitlyDisabledWithoutImportingItsSecret(): void
    {
        $this->environment('SAB_API_URL', 'https://Sab.Example.test:443/api/');
        $this->environment('SAB_API_KEY', 'environment-secret');
        $this->environment('SAB_VERIFY_SSL', 'false');

        $environment = $this->connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($environment);
        self::assertGreaterThan(0, $environment->revision);
        self::assertLessThanOrEqual(9007199254740991, $environment->revision);
        self::assertSame('https://sab.example.test', $environment->url);
        self::assertFalse($environment->verifyTls);
        self::assertSame('environment-secret', $this->connections->credentials($environment)['secret']);

        $disabled = $this->connections->save(new Connection(
            $environment->id,
            $environment->provider,
            'Environment',
            $environment->url,
            enabled: false,
            revision: $environment->revision,
            source: 'environment',
            credentialSource: 'environment',
            hasSecret: true,
        ), null, $environment->revision);

        self::assertSame(1, $disabled->revision);
        self::assertFalse($disabled->enabled);
        self::assertSame('environment', $disabled->credentialSource);
        self::assertNull($this->database->getDibi()->select('credential_envelope')
            ->from('integration_connections')->where('id = %s', ConnectionRepository::LEGACY_ID)->fetchSingle());
        self::assertCount(1, $this->connections->all());

        $this->connections->delete($disabled->id, $disabled->revision);
        $restored = $this->connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($restored);
        self::assertSame('environment', $restored->source);
    }

    public function testEnvironmentRevisionChangesAndOverridesCanResumeCollection(): void
    {
        $now = 1_800_000_000;
        $this->environment('SAB_API_URL', 'https://sab.example.test');
        $this->environment('SAB_API_KEY', 'first-key');
        $first = $this->connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($first);
        $oldToken = $this->downloads->claim($first, $now);
        self::assertNotNull($oldToken);
        $this->environment('SAB_API_KEY', 'second-key');
        $changed = $this->connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($changed);
        self::assertNotSame($first->revision, $changed->revision);
        $newToken = $this->downloads->claim($changed, $now + 1);
        self::assertNotNull($newToken);
        self::assertFalse($this->downloads->succeed($first, $oldToken, new CollectionBatch(), $now + 2));
        self::assertTrue($this->downloads->succeed($changed, $newToken, new CollectionBatch(), $now + 2));
        for ($i = 1; $i <= 9; ++$i) {
            $this->connections->save($this->candidate('extra-' . $i, 'https://client-' . $i . '.test'), 'secret');
        }
        $data = $changed->managementData();
        $data['enabled'] = false;
        $disabled = $this->connections->save(\Mk\Framework\Integrations\ConnectionInput::parse($data, $changed), null, $changed->revision);
        self::assertCount(10, $this->connections->all());
        self::assertNull($this->downloads->claim($disabled, $now + 3));
        $data = $disabled->managementData();
        $data['enabled'] = true;
        $enabled = $this->connections->save(\Mk\Framework\Integrations\ConnectionInput::parse($data, $disabled), null, $disabled->revision);
        self::assertNotNull($this->downloads->claim($enabled, $now + 4));
    }

    public function testStoredCredentialCanBeRetainedOnlyForTheSameEndpoint(): void
    {
        $saved = $this->connections->save($this->candidate('one', 'https://client.example.test'), 'first-secret');
        self::assertSame('first-secret', $this->connections->credentials($saved)['secret']);
        self::assertTrue($this->connections->hasStoredSecrets());

        $renamed = $this->connections->save(new Connection(
            $saved->id,
            $saved->provider,
            'Renamed',
            $saved->url,
            revision: $saved->revision,
            hasSecret: true,
        ), null, $saved->revision);
        self::assertSame(2, $renamed->revision);
        self::assertSame('first-secret', $this->connections->credentials($renamed)['secret']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter the client credential');
        $this->connections->save(new Connection(
            $renamed->id,
            $renamed->provider,
            $renamed->name,
            'https://other.example.test',
            revision: $renamed->revision,
            hasSecret: true,
        ), null, $renamed->revision);
    }

    public function testDuplicateEndpointAndStaleRevisionAreRejected(): void
    {
        $saved = $this->connections->save($this->candidate('one', 'https://client.example.test'), 'secret');
        try {
            $this->connections->save($this->candidate('two', 'https://CLIENT.example.test:443/'), 'other-secret');
            self::fail('A duplicate provider and endpoint must be rejected.');
        } catch (DomainException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->connections->save(new Connection(
            $saved->id,
            $saved->provider,
            'Stale',
            $saved->url,
            revision: $saved->revision,
        ), null, $saved->revision + 1);
    }

    public function testEditFencesAnInflightCollectorAndDeleteClearsOwnedData(): void
    {
        $saved = $this->connections->save($this->candidate('one', 'https://client.example.test'), 'secret');
        $token = $this->downloads->claim($saved, 1_800_000_000);
        self::assertNotNull($token);

        $edited = $this->connections->save(new Connection(
            $saved->id,
            $saved->provider,
            'Edited',
            $saved->url,
            enabled: true,
            revision: $saved->revision,
            hasSecret: true,
        ), null, $saved->revision);
        self::assertFalse($this->downloads->succeed($saved, $token, new CollectionBatch(
            completions: [$this->completion('late')],
        ), 1_800_000_010));
        self::assertSame([], $this->downloads->recent([$saved->id]));

        $newToken = $this->downloads->claim($edited, 1_800_000_011);
        self::assertNotNull($newToken);
        self::assertTrue($this->downloads->succeed($edited, $newToken, new CollectionBatch(
            completions: [$this->completion('kept')],
        ), 1_800_000_012));
        self::assertCount(1, $this->downloads->recent([$saved->id]));

        $filtered = $this->connections->save(new Connection(
            $edited->id,
            $edited->provider,
            $edited->name,
            $edited->url,
            filterMode: 'selected',
            categories: ['shows'],
            revision: $edited->revision,
            hasSecret: true,
        ), null, $edited->revision);
        self::assertSame([], $this->downloads->recent([$saved->id]));
        $restored = $this->connections->save(new Connection(
            $filtered->id,
            $filtered->provider,
            $filtered->name,
            $filtered->url,
            filterMode: 'selected',
            categories: ['movies'],
            revision: $filtered->revision,
            hasSecret: true,
        ), null, $filtered->revision);
        self::assertCount(1, $this->downloads->recent([$saved->id]));

        $this->connections->delete($restored->id, $restored->revision);
        self::assertNull($this->connections->find($restored->id));
        self::assertNull($this->downloads->state($restored->id));
        self::assertSame([], $this->downloads->recent([$restored->id]));
        self::assertNull($this->downloads->claim($restored, 1_800_000_100));
    }

    public function testConnectionLimitIsTen(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->connections->save($this->candidate('client-' . $i, 'https://client-' . $i . '.example.test'), 'secret');
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maximum of 10');
        $this->connections->save($this->candidate('client-10', 'https://client-10.example.test'), 'secret');
    }

    public function testUncategorizedIsAValidExplicitCategoryFilter(): void
    {
        $saved = $this->connections->save(new Connection(
            'uncategorized-client',
            'qbittorrent',
            'Uncategorized client',
            'https://uncategorized.example.test',
            'admin',
            filterMode: 'selected',
            categories: [''],
            tags: [''],
        ), 'secret');

        self::assertSame([''], $saved->categories);
        self::assertSame([], $saved->tags);
        self::assertTrue($saved->matches(['category' => '', 'tags' => []]));
        self::assertFalse($saved->matches(['category' => 'movies', 'tags' => []]));
    }

    public function testLongUnicodeFilterNamesSurviveStorageWithoutChangingMatching(): void
    {
        $category = str_repeat('ä', 129);
        $tag = str_repeat('t', 129);
        $saved = $this->connections->save(new Connection(
            'long-filter-client', 'qbittorrent', 'Long filter client', 'https://long.example.test',
            'admin', filterMode: 'selected', categories: [$category], tags: [$tag],
        ), 'secret');
        $loaded = $this->connections->find($saved->id);

        self::assertNotNull($loaded);
        self::assertSame([$category], $loaded->categories);
        self::assertSame([$tag], $loaded->tags);
        self::assertTrue($loaded->matches(['category' => $category, 'tags' => []]));
        self::assertTrue($loaded->matches(['category' => '', 'tags' => [$tag]]));
    }

    public function testNumericFilterNamesRemainStringsForExactMatching(): void
    {
        $saved = $this->connections->save(new Connection(
            'numeric-filter-client', 'qbittorrent', 'Numeric filter client', 'https://numeric.example.test',
            'admin', filterMode: 'selected', categories: ['123'], tags: ['456'],
        ), 'secret');
        $loaded = $this->connections->find($saved->id);

        self::assertNotNull($loaded);
        self::assertSame(['123'], $loaded->categories);
        self::assertSame(['456'], $loaded->tags);
        self::assertTrue($loaded->matches(['category' => '123', 'tags' => []]));
        self::assertTrue($loaded->matches(['category' => '', 'tags' => ['456']]));
    }

    public function testEditingSavedConnectionCannotDuplicateActiveEnvironmentFallback(): void
    {
        $this->environment('SAB_API_URL', 'https://environment.example.test/api');
        $this->environment('SAB_API_KEY', 'environment-key');
        $saved = $this->connections->save(
            $this->candidate('saved-client', 'https://saved.example.test'),
            'saved-secret',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already exists');
        $this->connections->save(new Connection(
            $saved->id,
            $saved->provider,
            $saved->name,
            'https://environment.example.test',
            revision: $saved->revision,
            hasSecret: true,
        ), 'replacement-secret', $saved->revision);
    }

    public function testLegacyOverrideCannotBeRemovedWhenItWouldRevealADuplicate(): void
    {
        $this->environment('SAB_API_URL', 'https://environment.example.test/api');
        $this->environment('SAB_API_KEY', 'environment-key');
        $environment = $this->connections->find(ConnectionRepository::LEGACY_ID);
        self::assertNotNull($environment);
        $override = $this->connections->save(new Connection(
            $environment->id,
            $environment->provider,
            'Environment override',
            'https://override.example.test',
            revision: $environment->revision,
            source: $environment->source,
            credentialSource: 'stored',
            hasSecret: true,
        ), 'override-secret', $environment->revision);
        $duplicate = $this->connections->save(
            $this->candidate('saved-environment-client', 'https://environment.example.test'),
            'saved-secret',
        );

        try {
            $this->connections->delete($override->id, $override->revision);
            self::fail('Removing the override must not reveal a duplicate environment connection.');
        } catch (DomainException $e) {
            self::assertStringContainsString('environment client', $e->getMessage());
        }

        self::assertSame($override->revision, $this->connections->find($override->id)?->revision);
        self::assertSame($duplicate->revision, $this->connections->find($duplicate->id)?->revision);
    }

    private function candidate(string $id, string $url): Connection
    {
        return new Connection($id, 'sabnzbd', ucfirst($id), $url);
    }

    /** @return array<string,mixed> */
    private function completion(string $id): array
    {
        return [
            'source_id' => $id,
            'title' => 'Completed ' . $id,
            'category' => 'movies',
            'tags' => [],
            'state' => 'completed',
            'progress' => 100.0,
            'size' => 1024,
            'downloaded' => 1024,
            'speed' => 0.0,
            'eta' => 0,
            'position' => null,
            'completed_at' => 1_800_000_000,
        ];
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
