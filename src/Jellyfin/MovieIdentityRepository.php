<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

final class MovieIdentityRepository
{
    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    private readonly string $serverKey;
    private ?string $leaseToken = null;
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

    public function serverKey(): string
    {
        return $this->serverKey;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $json = $this->db->select('data_json')->from('movie_identity_state')
            ->where('server_key = %s', $this->serverKey)->fetchSingle();

        return $json === null || $json === false ? [] : $this->decode((string) $json);
    }

    /** @param array<string, mixed> $data */
    public function saveState(array $data): void
    {
        $json = $this->encode($data);
        $this->write(function () use ($json): void {
            $this->db->update('movie_identity_state', ['data_json' => $json])
                ->where('server_key = %s AND lease_token = %s', $this->serverKey, $this->leaseToken)->execute();
        });
    }

    // Call inside the repair transaction to keep takeover and alias writes serialized.
    public function assertLease(int $now): void
    {
        $this->assertOwned($now, true);
    }

    public function acquireLease(int $now, int $ttl = 120): ?string
    {
        if ($now < 0 || $ttl < 1 || $now > PHP_INT_MAX - $ttl) {
            throw new \InvalidArgumentException('Movie identity leases require a valid timestamp and positive duration.');
        }
        if ($this->platform->isSqlite()) {
            $this->db->query(
                'INSERT INTO movie_identity_state (server_key, data_json, lease_token, lease_until_epoch)
                 VALUES (%s, %s, NULL, 0) ON CONFLICT (server_key) DO NOTHING',
                $this->serverKey,
                '{}',
            );
        } else {
            $this->db->query(
                'INSERT INTO movie_identity_state (server_key, data_json, lease_token, lease_until_epoch)
                 VALUES (%s, %s, NULL, 0) ON DUPLICATE KEY UPDATE server_key = VALUES(server_key)',
                $this->serverKey,
                '{}',
            );
        }
        $token = bin2hex(random_bytes(32));
        $this->db->update('movie_identity_state', ['lease_token' => $token, 'lease_until_epoch' => $now + $ttl])
            ->where('server_key = %s', $this->serverKey)
            ->where('lease_token IS NULL OR lease_until_epoch <= %i', $now)->execute();
        if ($this->db->getAffectedRows() === 0) {
            return null;
        }
        $this->leaseToken = $token;

        return $token;
    }

    public function releaseLease(string $token): void
    {
        $this->db->update('movie_identity_state', ['lease_token' => null, 'lease_until_epoch' => 0])
            ->where('server_key = %s AND lease_token = %s', $this->serverKey, $token)->execute();
        if ($this->leaseToken === $token) {
            $this->leaseToken = null;
        }
    }

    /** @return array<string, mixed>|null */
    public function get(string $itemId): ?array
    {
        $json = $this->db->select('data_json')->from('movie_identity_items')
            ->where('server_key = %s AND item_id = %s', $this->serverKey, $itemId)->fetchSingle();

        return $json === null || $json === false ? null : $this->decode((string) $json);
    }

    /** @param array<string, mixed> $data */
    public function put(string $itemId, array $data): void
    {
        $this->validateId($itemId);
        $json = $this->encode($data);
        $this->write(function () use ($itemId, $json): void {
            $sql = $this->platform->isSqlite()
                ? 'INSERT INTO movie_identity_items (server_key, item_id, data_json)
                   SELECT %s, %s, %s FROM movie_identity_state WHERE server_key = %s AND lease_token = %s
                   ON CONFLICT (server_key, item_id) DO UPDATE SET data_json = excluded.data_json'
                : 'INSERT INTO movie_identity_items (server_key, item_id, data_json)
                   SELECT %s, %s, %s FROM movie_identity_state WHERE server_key = %s AND lease_token = %s
                   ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)';
            $this->db->query($sql, $this->serverKey, $itemId, $json, $this->serverKey, $this->leaseToken);
        });
    }

    /** @return array<string, array<string, mixed>> */
    public function items(): array
    {
        $items = [];
        foreach ($this->db->select('item_id, data_json')->from('movie_identity_items')
            ->where('server_key = %s', $this->serverKey)->orderBy('item_id')->fetchAll() as $row) {
            $items[(string) $row['item_id']] = $this->decode((string) $row['data_json']);
        }

        return $items;
    }

    private function write(callable $operation): void
    {
        $this->assertOwned();
        $operation();
        $this->assertOwned();
    }

    private function assertOwned(?int $now = null, bool $lock = false): void
    {
        if ($this->leaseToken === null) {
            throw new \RuntimeException('A movie identity lease is required before writing.');
        }
        $row = $this->db->query(
            'SELECT lease_token, lease_until_epoch FROM movie_identity_state WHERE server_key = %s'
            . ($lock && !$this->platform->isSqlite() ? ' FOR UPDATE' : ''),
            $this->serverKey,
        )->fetch();
        if ($row === null || $row === false || $row['lease_token'] !== $this->leaseToken
            || ($now !== null && (int) $row['lease_until_epoch'] <= $now)) {
            throw new \RuntimeException('The movie identity lease is no longer owned by this worker.');
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('The stored movie identity payload must be a JSON object or array.');
        }

        return $data;
    }

    private function validateId(string $itemId): void
    {
        if (trim($itemId) === '' || strlen($itemId) > 64) {
            throw new \InvalidArgumentException('Movie identity IDs must contain between 1 and 64 bytes.');
        }
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `movie_identity_items` (
                `server_key` varbinary(64) NOT NULL,
                `item_id` varbinary(64) NOT NULL,
                `data_json` longtext NOT NULL,
                PRIMARY KEY (`server_key`, `item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            'CREATE TABLE IF NOT EXISTS `movie_identity_items` (
                `server_key` TEXT COLLATE BINARY NOT NULL,
                `item_id` TEXT COLLATE BINARY NOT NULL,
                `data_json` TEXT NOT NULL,
                PRIMARY KEY (`server_key`, `item_id`)
            )',
        );
        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `movie_identity_state` (
                `server_key` varbinary(64) NOT NULL,
                `data_json` longtext NOT NULL,
                `lease_token` varbinary(64) DEFAULT NULL,
                `lease_until_epoch` bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (`server_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            'CREATE TABLE IF NOT EXISTS `movie_identity_state` (
                `server_key` TEXT COLLATE BINARY NOT NULL PRIMARY KEY,
                `data_json` TEXT NOT NULL,
                `lease_token` TEXT COLLATE BINARY DEFAULT NULL,
                `lease_until_epoch` INTEGER NOT NULL DEFAULT 0
            )',
        );
        self::$schemaConnections[$this->db] = true;
    }
}
