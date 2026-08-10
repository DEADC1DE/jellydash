<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

/**
 * Stores browser Web Push subscriptions (the endpoint + keys a PushSubscription
 * hands us). One row per device that has opted in to playback notifications.
 */
final class PushSubscriptionRepository
{
    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    public function __construct(?Database $database = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->ensureSchema();
    }

    /**
     * Insert or refresh a subscription. Keyed by a hash of the endpoint so the
     * same device re-subscribing just updates its keys instead of duplicating.
     */
    public function save(string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        if (!PushSubscriptionValidator::isValid($endpoint, $p256dh, $auth)) {
            throw new \InvalidArgumentException('Invalid Web Push subscription.');
        }

        $hash = hash('sha256', $endpoint);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $data = [
            'endpoint' => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'failure_count' => 0,
        ];

        try {
            $insert = $data;
            $insert['created_at'] = $now;
            $this->db->insert('push_subscriptions', $insert)->execute();
        } catch (\Dibi\UniqueConstraintViolationException) {
            $this->db->update('push_subscriptions', $data)
                ->where('endpoint_hash = %s', $hash)
                ->execute();
        }
    }

    public function delete(string $endpoint): void
    {
        $this->db->delete('push_subscriptions')
            ->where('endpoint_hash = %s', hash('sha256', $endpoint))
            ->execute();
    }

    /**
     * @return array<int, array{endpoint: string, p256dh: string, auth: string}>
     */
    public function all(): array
    {
        $rows = $this->db->select('endpoint, p256dh, auth')
            ->from('push_subscriptions')
            ->fetchAll();

        return array_map(static fn ($r): array => [
            'endpoint' => (string) $r['endpoint'],
            'p256dh' => (string) $r['p256dh'],
            'auth' => (string) $r['auth'],
        ], $rows);
    }

    public function count(): int
    {
        return (int) $this->db->select('COUNT(*)')->from('push_subscriptions')->fetchSingle();
    }

    public function markSuccess(string $endpoint): void
    {
        $this->db->update('push_subscriptions', [
            'failure_count' => 0,
            'last_success_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ])
            ->where('endpoint_hash = %s', hash('sha256', $endpoint))
            ->execute();
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `endpoint` text NOT NULL,
                `endpoint_hash` char(64) NOT NULL,
                `p256dh` varchar(255) NOT NULL,
                `auth` varchar(255) NOT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `failure_count` int NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                `last_success_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_endpoint_hash` (`endpoint_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` TEXT NOT NULL,
                `p256dh` TEXT NOT NULL,
                `auth` TEXT NOT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `failure_count` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL,
                `last_success_at` TEXT DEFAULT NULL,
                UNIQUE (`endpoint_hash`)
            )'
        );

        self::$schemaConnections[$this->db] = true;
    }
}
