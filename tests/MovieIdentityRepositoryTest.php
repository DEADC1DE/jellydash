<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\MovieIdentityRepository;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class MovieIdentityRepositoryTest extends TestCase
{
    private Database $database;
    private MovieIdentityRepository $repository;
    private string $scope;
    private string $otherScope;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->scope = hash('sha256', 'identity-test-' . bin2hex(random_bytes(8)));
        $this->otherScope = hash('sha256', $this->scope);
        $this->repository = new MovieIdentityRepository($this->database, $this->scope);
    }

    protected function tearDown(): void
    {
        if (!isset($this->repository)) {
            return;
        }
        foreach (['movie_identity_items', 'movie_identity_state'] as $table) {
            $this->database->getDibi()->delete($table)
                ->where('server_key IN %in', [$this->scope, $this->otherScope])->execute();
        }
    }

    public function testStateAndIdentityPayloadsPersistWithoutLosingEvidenceKeys(): void
    {
        self::assertSame([], $this->repository->state());
        self::assertNull($this->repository->get('old'));
        self::assertSame([], $this->repository->items());
        self::assertIsString($this->repository->acquireLease(1000));
        $state = ['scan' => 'scan-A', 'offset' => 100, 'complete' => false];
        $identity = ['title' => 'Příběh 🪼', 'year' => 2026, 'providers' => ['Tmdb' => '123', 'IMDb' => 'tt123'], 'remoteServerId' => 'Case-Sensitive', 'seenScan' => 'scan-A', 'original_id' => 'old'];
        $this->repository->saveState($state);
        $this->repository->put('old', $identity);
        $other = new MovieIdentityRepository($this->database, $this->scope);
        self::assertSame($state, $other->state());
        self::assertSame($identity, $other->get('old'));
        self::assertSame(['old' => $identity], $other->items());
        $identity['year'] = null;
        $this->repository->put('old', $identity);
        self::assertSame($identity, $other->get('old'));
    }

    public function testLeasesContendExpireAndRejectStaleReleaseAndWrites(): void
    {
        $second = new MovieIdentityRepository($this->database, $this->scope);
        $firstToken = $this->repository->acquireLease(1000, 120);
        self::assertIsString($firstToken);
        self::assertNull($second->acquireLease(1119));
        $secondToken = $second->acquireLease(1120);
        self::assertIsString($secondToken);
        self::assertNotSame($firstToken, $secondToken);
        try {
            $this->repository->put('old', ['stale' => true]);
            self::fail('A taken-over lease cannot write identity.');
        } catch (RuntimeException) {
            self::assertNull($second->get('old'));
        }
        $this->repository->releaseLease($firstToken);
        self::assertNull($this->repository->acquireLease(1121));
        try {
            $this->repository->saveState(['stale' => true]);
            self::fail('A stale lease cannot overwrite state.');
        } catch (RuntimeException) {
            self::assertSame([], $second->state());
        }
        $second->saveState(['owner' => 'second']);
        $second->releaseLease($secondToken);
        self::assertIsString($this->repository->acquireLease(1122));
        self::assertSame(['owner' => 'second'], $this->repository->state());
    }

    public function testSaveStateNeverChangesLeaseFields(): void
    {
        $token = $this->repository->acquireLease(5000, 30);
        self::assertIsString($token);
        $this->repository->saveState(['scan' => 'running', 'lease_token' => 'payload-only', 'lease_until_epoch' => 0]);
        $row = $this->database->getDibi()->select('lease_token, lease_until_epoch')->from('movie_identity_state')
            ->where('server_key = %s', $this->scope)->fetch();
        self::assertNotFalse($row);
        self::assertSame($token, (string) $row['lease_token']);
        self::assertSame(5030, (int) $row['lease_until_epoch']);
        $this->repository->saveState(['scan' => 'running', 'lease_token' => 'payload-only', 'lease_until_epoch' => 0]);
        self::assertNull((new MovieIdentityRepository($this->database, $this->scope))->acquireLease(5010));
    }

    public function testExactIdsAndServerIsolation(): void
    {
        self::assertIsString($this->repository->acquireLease(1000));
        foreach (['12345', 'a-B', 'A-B', 'a-B ', str_repeat('x', 64)] as $index => $id) {
            $this->repository->put($id, ['index' => $index]);
            self::assertSame(['index' => $index], $this->repository->get($id));
        }
        self::assertSame(['index' => 1], $this->repository->get('a-B'));
        self::assertSame(['index' => 2], $this->repository->get('A-B'));
        self::assertSame(['index' => 3], $this->repository->get('a-B '));
        self::assertCount(5, $this->repository->items());
        self::assertNull($this->repository->get('a-b'));
        $other = new MovieIdentityRepository($this->database, $this->otherScope);
        self::assertSame($this->scope, $this->repository->serverKey());
        self::assertSame([], $other->state());
        self::assertSame([], $other->items());
        self::assertIsString($other->acquireLease(1000));
        $other->put('a-B', ['index' => 'other-server']);
        self::assertSame(['index' => 1], $this->repository->get('a-B'));
    }

    public function testMutationRequiresLeaseOwnership(): void
    {
        foreach ([fn () => $this->repository->saveState(['scan' => 1]), fn () => $this->repository->put('old', ['title' => 'Movie'])] as $operation) {
            try {
                $operation();
                self::fail('A mutation without a lease must fail.');
            } catch (RuntimeException) {
                self::assertSame([], $this->repository->items());
                self::assertSame([], $this->repository->state());
            }
        }
    }

    public function testCorruptJsonThrowsInsteadOfResettingIdentityOrScanState(): void
    {
        self::assertIsString($this->repository->acquireLease(1000));
        $this->repository->put('old', ['title' => 'Movie']);
        $db = $this->database->getDibi();
        $db->update('movie_identity_state', ['data_json' => '{bad'])->where('server_key = %s', $this->scope)->execute();
        $db->update('movie_identity_items', ['data_json' => 'null'])->where('server_key = %s', $this->scope)->execute();
        foreach ([fn () => $this->repository->state(), fn () => $this->repository->get('old'), fn () => $this->repository->items()] as $operation) {
            try {
                $operation();
                self::fail('Invalid stored JSON must fail.');
            } catch (JsonException | UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testInvalidUtf8NeverPartiallyOverwritesExistingJson(): void
    {
        self::assertIsString($this->repository->acquireLease(1000));
        $this->repository->saveState(['scan' => 'good']);
        $this->repository->put('old', ['title' => 'good']);
        foreach ([fn () => $this->repository->saveState(['scan' => "\xB1"]), fn () => $this->repository->put('old', ['title' => "\xB1"])] as $operation) {
            try {
                $operation();
                self::fail('Invalid UTF-8 must fail.');
            } catch (JsonException) {
                self::assertSame(['scan' => 'good'], $this->repository->state());
                self::assertSame(['title' => 'good'], $this->repository->get('old'));
            }
        }
    }

    public function testDefaultScopeUsesConfiguredJellyfinUrl(): void
    {
        self::assertSame(ThemePlaybackExclusions::serverKey(Config::get('JELLYFIN_URL', '')), (new MovieIdentityRepository($this->database))->serverKey());
    }

    public function testWritesParticipateInTheCallersTransactionAndRollBackTogether(): void
    {
        $aliases = new MovieReplacements($this->database, $this->scope);
        self::assertIsString($this->repository->acquireLease(1000));
        $this->repository->put('old', ['title' => 'Original']);
        try {
            try {
                $aliases->atomic(function () use ($aliases): void {
                    $this->repository->assertLease(1001);
                    $aliases->link('old', 'new');
                    $this->repository->put('old', ['title' => 'Updated']);
                    $this->repository->saveState(['scan' => 'updated']);
                    throw new RuntimeException('Rollback fixture');
                });
                self::fail('The transaction fixture must throw.');
            } catch (RuntimeException $error) {
                self::assertSame('Rollback fixture', $error->getMessage());
            }
            self::assertSame([], $aliases->all());
            self::assertSame(['title' => 'Original'], $this->repository->get('old'));
            self::assertSame([], $this->repository->state());
        } finally {
            $this->database->getDibi()->delete('movie_replacements')->where('server_key = %s', $this->scope)->execute();
        }
    }

    public function testLeaseAssertionRejectsExpiredAndStaleTokens(): void
    {
        self::assertIsString($this->repository->acquireLease(1000, 30));
        $this->database->getDibi()->begin();
        try {
            $this->repository->assertLease(1029);
            try {
                $this->repository->assertLease(1030);
                self::fail('An expired lease must fail.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        } finally {
            $this->database->getDibi()->rollback();
        }
        $other = new MovieIdentityRepository($this->database, $this->scope);
        self::assertIsString($other->acquireLease(1030));
        try {
            $this->repository->assertLease(1031);
            self::fail('A stale token must fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
