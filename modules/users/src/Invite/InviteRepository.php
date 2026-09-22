<?php

declare(strict_types=1);

namespace Mk\Modules\Users\Invite;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

/**
 * Local persistence for the native invitation engine: open invitations and
 * the expiry bookkeeping for accounts created through them.
 *
 * Both tables are self-created (no migration runner in this app); the WeakMap
 * guard keeps concurrent first requests from re-running the DDL.
 */
final class InviteRepository
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

    private function ensureSchema(): void
    {
        if (self::$schemaConnections?->offsetExists($this->db)) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `invite_invitations` (
                `id` int NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `created_at` datetime NOT NULL,
                `link_expires_at` datetime DEFAULT NULL,
                `duration_days` int DEFAULT NULL,
                `libraries` text NOT NULL,
                `allow_downloads` tinyint(1) NOT NULL DEFAULT 0,
                `allow_live_tv` tinyint(1) NOT NULL DEFAULT 0,
                `used_by` varchar(100) DEFAULT NULL,
                `used_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_invite_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `invite_invitations` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `code` TEXT NOT NULL,
                `created_at` TEXT NOT NULL,
                `link_expires_at` TEXT DEFAULT NULL,
                `duration_days` INTEGER DEFAULT NULL,
                `libraries` TEXT NOT NULL,
                `allow_downloads` INTEGER NOT NULL DEFAULT 0,
                `allow_live_tv` INTEGER NOT NULL DEFAULT 0,
                `used_by` TEXT DEFAULT NULL,
                `used_at` TEXT DEFAULT NULL,
                UNIQUE (`code`)
            )'
        );

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `invite_accounts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `jellyfin_user_id` varchar(64) NOT NULL,
                `username` varchar(100) NOT NULL,
                `expires_at` datetime DEFAULT NULL,
                `disabled_at` datetime DEFAULT NULL,
                `invited_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_invite_account_user` (`jellyfin_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `invite_accounts` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `jellyfin_user_id` TEXT NOT NULL,
                `username` TEXT NOT NULL,
                `expires_at` TEXT DEFAULT NULL,
                `disabled_at` TEXT DEFAULT NULL,
                `invited_at` TEXT NOT NULL,
                UNIQUE (`jellyfin_user_id`)
            )'
        );

        self::$schemaConnections ??= new \WeakMap();
        self::$schemaConnections->offsetSet($this->db, true);
    }

    /**
     * @param array<int, string> $libraries
     */
    public function createInvitation(
        ?string $linkExpiresAt,
        ?int $durationDays,
        array $libraries,
        bool $allowDownloads,
        bool $allowLiveTv,
        string $code,
    ): int {
        return $this->db->insert('invite_invitations', [
            'code' => $code,
            'created_at' => date('Y-m-d H:i:s'),
            'link_expires_at' => $linkExpiresAt,
            'duration_days' => $durationDays,
            'libraries' => json_encode(array_values($libraries), JSON_THROW_ON_ERROR),
            'allow_downloads' => $allowDownloads ? 1 : 0,
            'allow_live_tv' => $allowLiveTv ? 1 : 0,
        ])->execute(\dibi::IDENTIFIER);
    }

    /**
     * Newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invitations(): array
    {
        $rows = $this->db->select('*')->from('invite_invitations')
            ->orderBy('id DESC')->fetchAll();

        return array_map(static fn ($row): array => $row->toArray(), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invitationByCode(string $code): ?array
    {
        $row = $this->db->select('*')->from('invite_invitations')
            ->where('code = %s', $code)->limit(1)->fetch();

        return $row !== null ? $row->toArray() : null;
    }

    public function deleteInvitation(int $id): void
    {
        $this->db->delete('invite_invitations')->where('id = %i', $id)->execute();
    }

    public function markUsed(int $invitationId, string $username): void
    {
        $this->db->update('invite_invitations', [
            'used_by' => $username,
            'used_at' => date('Y-m-d H:i:s'),
        ])->where('id = %i', $invitationId)->execute();
    }

    public function trackAccount(string $jellyfinUserId, string $username, ?string $expiresAt): void
    {
        $this->db->insert('invite_accounts', [
            'jellyfin_user_id' => $jellyfinUserId,
            'username' => $username,
            'expires_at' => $expiresAt,
            'invited_at' => date('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * Invite-managed accounts keyed by lowercased username (join key against
     * the Jellyfin user list on the Users page).
     *
     * @return array<string, array<string, mixed>>
     */
    public function accountsByName(): array
    {
        $accounts = [];
        foreach ($this->db->select('*')->from('invite_accounts')->fetchAll() as $row) {
            $accounts[mb_strtolower((string) $row['username'])] = $row->toArray();
        }

        return $accounts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function accountByUserId(string $jellyfinUserId): ?array
    {
        $row = $this->db->select('*')->from('invite_accounts')
            ->where('jellyfin_user_id = %s', $jellyfinUserId)->limit(1)->fetch();

        return $row !== null ? $row->toArray() : null;
    }

    /**
     * Account expiry: base = the later of the stored expiry and now, so an
     * extension never shortens a still-valid term. Pass null to clear.
     */
    public function extendAccount(string $jellyfinUserId, int $days): ?string
    {
        $account = $this->accountByUserId($jellyfinUserId);
        if ($account === null) {
            return null;
        }

        $base = max(time(), strtotime((string) ($account['expires_at'] ?? '')) ?: time());
        $expires = date('Y-m-d H:i:s', $base + max(1, $days) * 86400);
        $this->db->update('invite_accounts', ['expires_at' => $expires])
            ->where('id = %i', $account['id'])->execute();

        return $expires;
    }

    /**
     * @return array<int, array<string, mixed>> accounts past their expiry
     */
    public function expiredAccounts(): array
    {
        $rows = $this->db->select('*')->from('invite_accounts')
            ->where('expires_at IS NOT NULL AND expires_at < %dt', new \DateTime())
            ->fetchAll();

        return array_map(static fn ($row): array => $row->toArray(), $rows);
    }

    public function markDisabled(string $jellyfinUserId): void
    {
        $this->db->update('invite_accounts', ['disabled_at' => date('Y-m-d H:i:s')])
            ->where('jellyfin_user_id = %s', $jellyfinUserId)->execute();
    }

    public function clearDisabled(string $jellyfinUserId): void
    {
        $this->db->update('invite_accounts', ['disabled_at' => null])
            ->where('jellyfin_user_id = %s', $jellyfinUserId)->execute();
    }
}
