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

final class DownloadRepositoryTest extends TestCase
{
    private string $databasePath;
    private string $keyPath;
    private Database $database;
    private ConnectionRepository $connections;
    private DownloadRepository $downloads;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/jellydash-downloads-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->keyPath = sys_get_temp_dir() . '/jellydash-integration-key-' . bin2hex(random_bytes(8));
        $this->database = Database::sqlite($this->databasePath);
        $store = new CredentialStore($this->keyPath);
        $this->connections = new ConnectionRepository($this->database, $store);
        $this->downloads = new DownloadRepository($this->database, $store);
        $this->connection = $this->connections->save(new Connection(
            'qb-one',
            'qbittorrent',
            'qB One',
            'https://qb.example.test',
            'admin',
            filterMode: 'selected',
            categories: ['movies'],
            tags: ['keep'],
        ), 'password');
    }

    protected function tearDown(): void
    {
        $this->database->getDibi()->disconnect();
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm', $this->keyPath, $this->keyPath . '.lock'] as $path) {
            @unlink($path);
        }
    }

    public function testLeaseSuccessStoresOnlyFilteredNormalizedDataAndEncryptedSession(): void
    {
        $now = 1_800_000_000;
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertNull($this->downloads->claim($this->connection, $now));

        $included = $this->item('included', 'movies', []);
        $included['remote_path'] = '/private/downloads/title';
        $included['tracker_url'] = 'https://tracker.example.test/secret';
        $tagged = $this->item('tagged', 'other', ['keep']);
        $excluded = $this->item('excluded', 'other', ['skip']);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            items: [$included, $tagged, $excluded],
            completions: [
                $this->completion('complete-in', 'movies', [], $now - 1),
                $this->completion('complete-out', 'other', ['skip'], $now - 1),
            ],
            speed: 512.5,
            cursor: ['offset' => 100, 'boundary_ids' => ['one']],
            complete: false,
            session: ['sid' => 'private-session', 'expires_at' => $now + 1200],
        ), $now + 1));

        $state = $this->downloads->state($this->connection->id);
        self::assertNotNull($state);
        self::assertSame($this->connection->revision, $state['revision']);
        self::assertCount(2, $state['snapshot']['items']);
        self::assertNull($state['snapshot']['speed']);
        self::assertSame(512.5, $state['snapshot']['client_speed']);
        self::assertFalse($state['snapshot']['speed_complete']);
        self::assertFalse($state['snapshot']['complete']);
        self::assertArrayNotHasKey('remote_path', $state['snapshot']['items'][0]);
        self::assertArrayNotHasKey('tracker_url', $state['snapshot']['items'][0]);
        self::assertSame(['offset' => 100, 'boundary_ids' => ['one']], $state['cursor']);
        self::assertSame(['sid' => 'private-session', 'expires_at' => $now + 1200], $this->downloads->session($this->connection));

        $ciphertext = (string) $this->database->getDibi()->select('session_envelope')
            ->from('download_connection_state')->where('connection_id = %s', $this->connection->id)->fetchSingle();
        self::assertStringNotContainsString('private-session', $ciphertext);
        $this->database->getDibi()->update('integration_connections', ['credential_envelope' => null])
            ->where('id = %s', $this->connection->id)->execute();
        self::assertTrue($this->connections->hasStoredSecrets());
        self::assertSame(['complete-in'], array_column($this->downloads->recent([$this->connection->id]), 'source_id'));
        self::assertNull($this->downloads->claim($this->connection, $now + 15));
        self::assertNotNull($this->downloads->claim($this->connection, $now + 16));
    }

    public function testFailureRetainsSnapshotAndUsesBoundedBackoff(): void
    {
        $now = 1_800_000_000;
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            items: [$this->item('active', 'movies', [])],
        ), $now + 1));
        $before = $this->downloads->state($this->connection->id);

        $failureToken = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($failureToken);
        $this->downloads->fail($this->connection, $failureToken, 'message with unsafe details', $now + 17);
        $after = $this->downloads->state($this->connection->id);
        self::assertNotNull($after);
        self::assertSame($before['snapshot'], $after['snapshot']);
        self::assertSame('request_failed', $after['failure_code']);
        self::assertSame(1, $after['failure_count']);
        self::assertSame($now + 32, $after['next_due_at']);

        $authToken = $this->downloads->claim($this->connection, $now + 32);
        self::assertNotNull($authToken);
        $this->downloads->fail($this->connection, $authToken, 'authentication_failed', $now + 33);
        self::assertSame($now + 333, $this->downloads->state($this->connection->id)['next_due_at']);
    }

    public function testHistoryFailureKeepsLiveSnapshotHealthyAndTwentyCachedResultsVisible(): void
    {
        $now = 1_800_000_000;
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        $completions = [];
        for ($i = 0; $i < 23; ++$i) {
            $completions[] = $this->completion('result-' . $i, 'movies', [], $now - 100 + $i);
        }
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            items: [$this->item('active', 'movies', [])],
            completions: $completions,
            history: [
                'last_attempt_at' => $now + 1,
                'last_success_at' => $now - 59,
                'error' => 'unsafe remote response with credentials',
                'next_due_at' => $now + 61,
                'failure_count' => 1,
                'private_response' => 'must not persist',
            ],
        ), $now + 1));

        $snapshot = $this->downloads->state($this->connection->id)['snapshot'];
        self::assertArrayNotHasKey('private_response', $snapshot['history']);
        self::assertSame('history_update_failed', $snapshot['history']['error']);
        $overview = (new OverviewService($this->connections, $this->downloads))->snapshot($now + 1);
        $client = $overview['connections'][0];
        self::assertSame('connected', $client['status']);
        self::assertFalse($client['partial']);
        self::assertSame(100.5, $client['speed']);
        self::assertSame('error', $client['history']['status']);
        self::assertSame($now - 59, $client['history']['last_success_at']);
        self::assertCount(20, $overview['history']);
        self::assertCount(20, $overview['history_by_connection'][$this->connection->id]);
        self::assertCount(23, $this->downloads->recent([$this->connection->id]));

        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            items: [$this->item('active', 'movies', [])],
            history: [
                'last_attempt_at' => $now + 17,
                'last_success_at' => $now + 17,
                'error' => null,
                'next_due_at' => $now + 77,
                'failure_count' => 0,
            ],
        ), $now + 17));
        $recovered = (new OverviewService($this->connections, $this->downloads))->snapshot($now + 17);
        self::assertSame('connected', $recovered['connections'][0]['history']['status']);
        self::assertNull($recovered['connections'][0]['history']['error']);
        self::assertCount(20, $recovered['history']);
        self::assertSame('stale', (new OverviewService($this->connections, $this->downloads))
            ->snapshot($now + 138)['connections'][0]['history']['status']);
    }

    public function testExpiredLeaseCannotWriteAndAReplacementTokenFencesIt(): void
    {
        $now = 1_800_000_000;
        $expired = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($expired);
        self::assertFalse($this->downloads->succeed(
            $this->connection,
            $expired,
            new CollectionBatch(items: [$this->item('late', 'movies', [])]),
            $now + 60,
        ));

        $replacement = $this->downloads->claim($this->connection, $now + 60);
        self::assertNotNull($replacement);
        self::assertNotSame($expired, $replacement);
        self::assertFalse($this->downloads->succeed(
            $this->connection,
            $expired,
            new CollectionBatch(items: [$this->item('stale', 'movies', [])]),
            $now + 61,
        ));
        self::assertTrue($this->downloads->succeed(
            $this->connection,
            $replacement,
            new CollectionBatch(items: [$this->item('current', 'movies', [])]),
            $now + 61,
        ));
    }

    public function testHistoryIsCaseSensitiveStableAndBounded(): void
    {
        $now = 1_800_000_000;
        $completions = [
            $this->completion('Case', 'movies', [], $now - 10),
            $this->completion('case', 'movies', [], $now - 9),
        ];
        for ($i = 0; $i < 250; ++$i) {
            $completions[] = $this->completion('item-' . $i, 'movies', [], $now - $i);
        }
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            completions: $completions,
        ), $now + 1));
        $history = $this->downloads->recent([$this->connection->id]);
        self::assertCount(250, $history);
        self::assertContains('Case', array_column($history, 'source_id'));
        self::assertContains('case', array_column($history, 'source_id'));

        $original = array_values(array_filter($history, static fn (array $row): bool => $row['source_id'] === 'Case'))[0];
        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            completions: [$this->completion('Case', 'movies', [], $now - 10)],
        ), $now + 17));
        $unchanged = array_values(array_filter(
            $this->downloads->recent([$this->connection->id]),
            static fn (array $row): bool => $row['source_id'] === 'Case',
        ))[0];
        self::assertSame($original['observed_at'], $unchanged['observed_at']);

        $token = $this->downloads->claim($this->connection, $now + 32);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token, new CollectionBatch(
            completions: [$this->completion('Case', 'movies', [], $now + 30)],
        ), $now + 33));
        self::assertSame('Case', $this->downloads->recent([$this->connection->id], 1)[0]['source_id']);
        self::assertSame($now + 33, $this->downloads->recent([$this->connection->id], 1)[0]['observed_at']);
    }

    public function testFailedHistoryPersistsWithoutTimestampChurnAndClearsForAnActiveRetry(): void
    {
        $now = 1_800_000_000;
        $failed = $this->completion('retry', 'movies', [], $now - 20);
        $failed['state'] = 'failed';
        $failed['title'] = 'Failed retry';
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 1));
        $recent = $this->downloads->recent([$this->connection->id]);
        self::assertSame('failed', $recent[0]['status']);
        self::assertSame($now - 20, $recent[0]['completed_at']);
        self::assertSame($now + 1, $recent[0]['observed_at']);

        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 17));
        self::assertSame($recent[0]['observed_at'], $this->downloads->recent([$this->connection->id])[0]['observed_at']);

        $token = $this->downloads->claim($this->connection, $now + 32);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(items: [$this->item('retry', 'movies', [])], completions: [$failed]), $now + 33));
        self::assertSame([], $this->downloads->recent([$this->connection->id]));

        $token = $this->downloads->claim($this->connection, $now + 48);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$this->completion('retry', 'movies', [], $now + 47)]), $now + 49));
        self::assertSame('completed', $this->downloads->recent([$this->connection->id])[0]['status']);
    }

    public function testNumericSourceIdentityCanClearAnObsoleteFailure(): void
    {
        $now = 1_800_000_000;
        $failed = $this->completion('1', 'movies', [], $now - 1);
        $failed['state'] = 'failed';
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 1));

        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(items: [$this->item('1', 'movies', [])], completions: [$failed]), $now + 17));
        self::assertSame([], $this->downloads->recent([$this->connection->id]));
    }

    public function testSuccessWithoutSourceTimestampReplacesEarlierTimestampedFailure(): void
    {
        $now = 1_800_000_000;
        $failed = $this->completion('same-id', 'movies', [], $now - 10);
        $failed['state'] = 'failed';
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 1));

        $completed = $this->completion('same-id', 'movies', [], $now + 15);
        $completed['completed_at'] = null;
        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$completed]), $now + 17));
        $recent = $this->downloads->recent([$this->connection->id]);
        self::assertSame('completed', $recent[0]['status']);
        self::assertNull($recent[0]['completed_at']);
        self::assertSame($now + 17, $recent[0]['observed_at']);

        $token = $this->downloads->claim($this->connection, $now + 32);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 33));
        $replayed = $this->downloads->recent([$this->connection->id]);
        self::assertSame('completed', $replayed[0]['status']);
        self::assertNull($replayed[0]['completed_at']);
        self::assertSame($now + 17, $replayed[0]['observed_at']);
    }

    public function testOlderOutcomeCannotReplaceNewerOutcomeForTheSameIdentity(): void
    {
        $now = 1_800_000_000;
        $failed = $this->completion('same-id', 'movies', [], $now - 10);
        $failed['state'] = 'failed';
        $token = $this->downloads->claim($this->connection, $now);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 1));

        $olderSuccess = $this->completion('same-id', 'movies', [], $now - 20);
        $token = $this->downloads->claim($this->connection, $now + 16);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$olderSuccess]), $now + 17));
        self::assertSame('failed', $this->downloads->recent([$this->connection->id])[0]['status']);

        $newerSuccess = $this->completion('same-id', 'movies', [], $now + 30);
        $token = $this->downloads->claim($this->connection, $now + 32);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$newerSuccess]), $now + 33));
        self::assertSame('completed', $this->downloads->recent([$this->connection->id])[0]['status']);

        $token = $this->downloads->claim($this->connection, $now + 48);
        self::assertNotNull($token);
        self::assertTrue($this->downloads->succeed($this->connection, $token,
            new CollectionBatch(completions: [$failed]), $now + 49));
        self::assertSame('completed', $this->downloads->recent([$this->connection->id])[0]['status']);
        self::assertSame($now + 30, $this->downloads->recent([$this->connection->id])[0]['completed_at']);
    }

    /** @return array<string,mixed> */
    private function item(string $id, string $category, array $tags): array
    {
        return [
            'source_id' => $id,
            'title' => 'Download ' . $id,
            'category' => $category,
            'tags' => $tags,
            'state' => 'downloading',
            'progress' => 25.0,
            'size' => 4000,
            'downloaded' => 1000,
            'speed' => 100.5,
            'eta' => 30,
            'position' => 1,
            'completed_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function completion(string $id, string $category, array $tags, int $completedAt): array
    {
        return [
            'source_id' => $id,
            'title' => 'Completed ' . $id,
            'category' => $category,
            'tags' => $tags,
            'state' => 'completed',
            'progress' => 100.0,
            'size' => 8000,
            'downloaded' => 8000,
            'speed' => 0.0,
            'eta' => 0,
            'position' => null,
            'completed_at' => $completedAt,
        ];
    }
}
