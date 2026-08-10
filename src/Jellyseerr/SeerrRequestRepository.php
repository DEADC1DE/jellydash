<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

/**
 * Local mirror of Jellyseerr requests.
 *
 * Titles and posters never change, so each request is looked up once and stored;
 * only the two status fields are refreshed from the (single) list call. That
 * makes the Requests page a plain SELECT: no API calls in the request path, and
 * it keeps working when Jellyseerr is unreachable.
 */
final class SeerrRequestRepository
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

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function count(): int
    {
        return (int) $this->db->select('COUNT(*)')->from('seerr_requests')->fetchSingle();
    }

    /**
     * Which of the given Jellyseerr request ids we already store.
     *
     * @param array<int, int> $requestIds
     * @return array<int, int>
     */
    public function knownIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = $this->db->select('request_id')
            ->from('seerr_requests')
            ->where('request_id IN %in', $requestIds)
            ->fetchPairs(null, 'request_id');

        return array_map('intval', $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): void
    {
        try {
            $this->db->insert('seerr_requests', $row)->execute();
        } catch (\Dibi\UniqueConstraintViolationException) {
            // Another sync inserted it first; nothing to do.
        }
    }

    public function updateStatuses(int $requestId, int $requestStatus, int $mediaStatus): void
    {
        $this->db->update('seerr_requests', [
            'request_status' => $requestStatus,
            'media_status' => $mediaStatus,
        ])
            ->where('request_id = %i', $requestId)
            ->execute();
    }

    /**
     * Newest requests first, for the page.
     *
     * @return array<int, \Dibi\Row>
     */
    public function latest(int $limit = 24): array
    {
        return $this->db->select('*')
            ->from('seerr_requests')
            ->orderBy('requested_at')->desc()
            ->orderBy('request_id')->desc()
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Claim requests that haven't been announced yet, flipping them to notified
     * so an alert can never fire twice.
     *
     * @return array<int, \Dibi\Row>
     */
    public function claimUnnotified(): array
    {
        $rows = $this->db->select('*')
            ->from('seerr_requests')
            ->where('notified = 0')
            ->orderBy('requested_at')->asc()
            ->fetchAll();

        if ($rows === []) {
            return [];
        }

        $claimed = [];
        foreach ($rows as $row) {
            $this->db->update('seerr_requests', ['notified' => 1])
                ->where('id = %i', (int) $row['id'])
                ->where('notified = 0')
                ->execute();

            if ($this->db->getAffectedRows() === 1) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `seerr_requests` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `request_id` int NOT NULL,
                `media_type` varchar(16) NOT NULL,
                `tmdb_id` int NOT NULL,
                `title` varchar(255) NOT NULL,
                `year` varchar(4) DEFAULT NULL,
                `poster_path` varchar(255) DEFAULT NULL,
                `requested_by` varchar(128) DEFAULT NULL,
                `request_status` tinyint NOT NULL DEFAULT 0,
                `media_status` tinyint NOT NULL DEFAULT 0,
                `is_4k` tinyint(1) NOT NULL DEFAULT 0,
                `season_count` int DEFAULT NULL,
                `requested_at` datetime NOT NULL,
                `notified` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_request_id` (`request_id`),
                KEY `idx_requested_at` (`requested_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `seerr_requests` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `request_id` INTEGER NOT NULL,
                `media_type` TEXT NOT NULL,
                `tmdb_id` INTEGER NOT NULL,
                `title` TEXT NOT NULL,
                `year` TEXT DEFAULT NULL,
                `poster_path` TEXT DEFAULT NULL,
                `requested_by` TEXT DEFAULT NULL,
                `request_status` INTEGER NOT NULL DEFAULT 0,
                `media_status` INTEGER NOT NULL DEFAULT 0,
                `is_4k` INTEGER NOT NULL DEFAULT 0,
                `season_count` INTEGER DEFAULT NULL,
                `requested_at` TEXT NOT NULL,
                `notified` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL,
                UNIQUE (`request_id`)
            )'
        );
        $this->platform->createSqliteIndex('idx_requested_at', 'seerr_requests', ['requested_at']);

        self::$schemaConnections[$this->db] = true;
    }
}
