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
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(?Database $database = null, ?string $serverKey = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->serverKey = $serverKey ?? ThemePlaybackExclusions::serverKey(Config::get('JELLYFIN_URL', ''));
        $this->validateId($this->serverKey);
        $this->ensureSchema();
    }

    public function resolve(string $itemId): string
    {
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

    /** @return array<string, string> */
    public function all(): array
    {
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

    private function validateId(string $itemId): void
    {
        if (trim($itemId) === '' || strlen($itemId) > 64) {
            throw new \InvalidArgumentException('Movie replacement IDs must contain between 1 and 64 bytes.');
        }
    }

    private function mutate(callable $operation): void
    {
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
            $operation();
            $this->db->commit();
            $transaction = false;
        } catch (\Throwable $error) {
            if ($transaction) {
                $this->db->rollback();
            }
            throw $error;
        } finally {
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
