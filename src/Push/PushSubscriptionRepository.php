<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

use Mk\Framework\Container;
use Mk\Framework\Database;

/**
 * Stores browser Web Push subscriptions (the endpoint + keys a PushSubscription
 * hands us). One row per device that has opted in to playback notifications.
 */
final class PushSubscriptionRepository
{
    private \Dibi\Connection $db;
    private static bool $schemaEnsured = false;

    public function __construct(?Database $database = null)
    {
        $this->db = ($database ?? Container::db())->getDibi();
        $this->ensureSchema();
    }

    /**
     * Insert or refresh a subscription. Keyed by a hash of the endpoint so the
     * same device re-subscribing just updates its keys instead of duplicating.
     */
    public function save(string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
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
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->query(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::$schemaEnsured = true;
    }
}
