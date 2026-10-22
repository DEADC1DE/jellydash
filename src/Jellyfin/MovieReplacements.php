<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

final class MovieReplacements
{
    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    private readonly string $serverKey;
    private MovieIdentityRepository $identities;
    private bool $inMutation = false;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(?Database $database = null, ?string $serverKey = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->serverKey = $serverKey ?? ThemePlaybackExclusions::serverKey(Config::get('JELLYFIN_URL', ''));
        $this->validateId($this->serverKey);
        $this->identities = new MovieIdentityRepository($database, $this->serverKey);
        $this->ensureSchema();
    }

    public function resolve(string $itemId): string
    {
        if ($this->identities->state()['serverMismatch'] ?? false) {
            return $itemId;
        }
        $replacement = $this->db->select('replacement_id')->from('movie_replacements')
            ->where('server_key = %s AND item_id = %s', $this->serverKey, $itemId)->fetchSingle();

        return $replacement === null || $replacement === false ? $itemId : (string) $replacement;
    }

    /** @return list<string> */
    public function members(string $itemId): array
    {
        $map = $this->all();
        $target = $map[$itemId] ?? $itemId;
        $members = [$target];
        foreach ($map as $oldId => $replacementId) {
            if ($replacementId === $target) {
                $members[] = (string) $oldId;
            }
        }

        return $members;
    }

    /**
     * Read-side group membership includes the full-GUID spellings that History
     * presentation groups together. Stored aliases and their journals stay exact.
     *
     * @param array<string, string>|null $map
     * @return list<string>
     */
    public function historyMembers(string $itemId, ?array $map = null): array
    {
        $map ??= $this->all();
        $target = $map[$itemId] ?? $itemId;
        $targetKey = ThemePlaybackExclusions::canonicalItemId($target);
        $members = [$target];
        foreach ($map as $oldId => $replacementId) {
            if (ThemePlaybackExclusions::canonicalItemId($replacementId) === $targetKey) {
                $members[] = (string) $oldId;
            }
        }
        return array_values(array_unique($members));
    }

    /** @param list<string> $ids */
    public function historyCondition(array $ids): string
    {
        $conditions = [];
        foreach ($ids as $id) {
            $key = ThemePlaybackExclusions::canonicalItemId($id);
            if (preg_match('/^[0-9a-f]{32}$/D', $key) === 1) {
                $dashed = substr($key, 0, 8) . '-' . substr($key, 8, 4) . '-'
                    . substr($key, 12, 4) . '-' . substr($key, 16, 4) . '-' . substr($key, 20, 12);
                $conditions[] = $this->db->translate('(LOWER(item_id) = %s OR LOWER(item_id) = %s)', $key, $dashed);
            } else {
                $conditions[] = $this->db->translate($this->platform->isSqlite()
                    ? 'item_id COLLATE BINARY = %s' : 'BINARY item_id = %s', $id);
            }
        }
        return $conditions === [] ? '1 = 0' : '(' . implode(' OR ', array_unique($conditions)) . ')';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->identities->state()['serverMismatch'] ?? false) {
            return [];
        }
        $map = [];
        foreach ($this->db->select('item_id, replacement_id')->from('movie_replacements')
            ->where('server_key = %s', $this->serverKey)->orderBy('item_id')->fetchAll() as $row) {
            $map[(string) $row['item_id']] = (string) $row['replacement_id'];
        }

        return $map;
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize([$this->serverKey, $this->all()]));
    }

    public function link(string $oldId, string $newId): void
    {
        $this->validateId($oldId);
        $this->validateId($newId);
        if ($oldId === $newId) {
            throw new \InvalidArgumentException('A movie cannot replace itself.');
        }

        $this->mutate(function () use ($oldId, $newId): void {
            if ($this->identities->state()['serverMismatch'] ?? false) {
                throw new \RuntimeException('Movie replacements are paused because the Jellyfin server changed.');
            }
            $map = $this->all();
            $target = $map[$newId] ?? $newId;
            if ($target === $oldId) {
                throw new \InvalidArgumentException('The movie replacement would create a cycle.');
            }
            if (isset($map[$oldId])) {
                if ($map[$oldId] !== $target) {
                    throw new \InvalidArgumentException('The movie already has a different replacement. Unlink it first.');
                }

                return;
            }

            $this->db->update('movie_replacements', ['replacement_id' => $target])
                ->where('server_key = %s AND replacement_id = %s', $this->serverKey, $oldId)->execute();
            $this->db->insert('movie_replacements', [
                'server_key' => $this->serverKey,
                'item_id' => $oldId,
                'replacement_id' => $target,
            ])->execute();
        });
    }

    public function unlink(string $oldId): bool
    {
        $this->validateId($oldId);
        $removed = false;
        $this->mutate(function () use ($oldId, &$removed): void {
            $this->db->delete('movie_replacements')
                ->where('server_key = %s AND item_id = %s', $this->serverKey, $oldId)->execute();
            $removed = $this->db->getAffectedRows() > 0;
        });

        return $removed;
    }

    /** Save only real alias changes, preserving an existing journal on an idempotent repeat. */
    public function journaledLink(
        string $oldId,
        string $newId,
        bool $automatic = false,
        ?MovieIdentityRepository $identities = null,
        ?int $now = null,
    ): void {
        $identities ??= $this->identities;
        if ($identities->serverKey() !== $this->serverKey) {
            throw new \InvalidArgumentException('The movie journal must belong to the same server.');
        }
        $now ??= time();
        $lease = null;
        if ($identities === $this->identities) {
            $lease = $identities->acquireLease($now);
            if ($lease === null) {
                throw new \RuntimeException('Movie recovery is busy. Please try again shortly.');
            }
        }
        try {
            $this->atomic(function () use ($oldId, $newId, $automatic, $identities, $now): void {
                $identities->assertLease($now);
                $before = $this->all();
                $this->link($oldId, $newId);
                $after = $this->all();
                $changedBefore = [];
                $changedAfter = [];
                foreach ($after as $id => $target) {
                    if (($before[$id] ?? null) !== $target) {
                        $changedBefore[$id] = $before[$id] ?? null;
                        $changedAfter[$id] = $target;
                    }
                }
                if ($changedAfter === []) {
                    return;
                }
                $source = $identities->get($oldId) ?? [];
                $source['candidateIds'] = [];
                $source['suppressed'] = false;
                $source['repairAutomatic'] = $automatic;
                $source['repairBefore'] = $changedBefore;
                $source['repairAfter'] = $changedAfter;
                $identities->put($oldId, $source);
            });
        } finally {
            if ($lease !== null) {
                $identities->releaseLease($lease);
            }
        }
    }

    /**
     * Keep a repair and its decision record in the same database transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomic(callable $operation): mixed
    {
        $result = null;
        $this->mutate(function () use ($operation, &$result): void {
            $result = $operation();
        });

        /** @var T $result */
        return $result;
    }

    private function validateId(string $itemId): void
    {
        if (trim($itemId) === '' || strlen($itemId) > 64) {
            throw new \InvalidArgumentException('Movie replacement IDs must contain between 1 and 64 bytes.');
        }
    }

    private function mutate(callable $operation): void
    {
        if ($this->inMutation) {
            $operation();

            return;
        }
        $lockName = 'jellydash:movie:' . substr(hash('sha256', (string) $this->db->getConfig('database') . ':' . $this->serverKey), 0, 49);
        $locked = false;
        $transaction = false;
        try {
            if ($this->platform->isSqlite()) {
                $this->db->query('BEGIN IMMEDIATE');
            } else {
                if ((int) $this->db->query('SELECT GET_LOCK(%s, 5)', $lockName)->fetchSingle() !== 1) {
                    throw new \RuntimeException('Could not lock the movie replacements for this server.');
                }
                $locked = true;
                $this->db->begin();
            }
            $transaction = true;
            $this->inMutation = true;
            $operation();
            $this->db->commit();
            $transaction = false;
        } catch (\Throwable $error) {
            if ($transaction) {
                $this->db->rollback();
            }
            throw $error;
        } finally {
            $this->inMutation = false;
            if ($locked) {
                $this->db->query('SELECT RELEASE_LOCK(%s)', $lockName);
            }
        }
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `movie_replacements` (
                `server_key` varbinary(64) NOT NULL,
                `item_id` varbinary(64) NOT NULL,
                `replacement_id` varbinary(64) NOT NULL,
                PRIMARY KEY (`server_key`, `item_id`),
                KEY `idx_movie_replacement_target` (`server_key`, `replacement_id`)
            ) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS `movie_replacements` (
                `server_key` TEXT COLLATE BINARY NOT NULL,
                `item_id` TEXT COLLATE BINARY NOT NULL,
                `replacement_id` TEXT COLLATE BINARY NOT NULL,
                PRIMARY KEY (`server_key`, `item_id`)
            )',
        );
        $this->platform->createSqliteIndex('idx_movie_replacement_target', 'movie_replacements', ['server_key', 'replacement_id']);
        self::$schemaConnections[$this->db] = true;
    }
}
