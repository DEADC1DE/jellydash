<?php

declare(strict_types=1);

namespace Mk\Modules\Users\Invite;

use Mk\Framework\Jellyfin\JellyfinClient;

/**
 * Native invitation engine: creates invite codes, serves the public join
 * flow, and provisions Jellyfin accounts for redeemed invites. Replaces the
 * external Wizarr service — everything lives in the local database and the
 * Jellyfin API.
 */
final class InviteManager
{
    public function __construct(
        private readonly InviteRepository $repository = new InviteRepository(),
        private readonly JellyfinClient $jellyfin = new JellyfinClient(),
    ) {
    }

    public function repository(): InviteRepository
    {
        return $this->repository;
    }

    /**
     * @param array<int, string> $libraryIds Jellyfin media folder ids
     * @return array<string, mixed> the created invitation row
     */
    public function createInvitation(
        ?int $linkExpiresInDays,
        ?int $accessDays,
        array $libraryIds,
        bool $allowDownloads = false,
        bool $allowLiveTv = false,
    ): array {
        $linkExpiresAt = $linkExpiresInDays !== null && $linkExpiresInDays > 0
            ? date('Y-m-d H:i:s', time() + $linkExpiresInDays * 86400)
            : null;

        $this->repository->createInvitation(
            $linkExpiresAt,
            $accessDays !== null && $accessDays > 0 ? $accessDays : null,
            $libraryIds,
            $allowDownloads,
            $allowLiveTv,
            $this->generateCode(),
        );

        $invitations = $this->repository->invitations();

        return $invitations[0];
    }

    /**
     * Invitation view models for the Users page, newest first: computed
     * status, expiry, and the absolute join URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invitations(?string $host = null): array
    {
        $now = time();
        $view = [];
        foreach ($this->repository->invitations() as $invitation) {
            $linkExpired = $invitation['link_expires_at'] !== null
                && strtotime((string) $invitation['link_expires_at']) < $now;
            $invitation['status'] = $invitation['used_by'] !== null
                ? 'used'
                : ($linkExpired ? 'expired' : null);
            $invitation['join_url'] = $invitation['used_by'] === null
                ? $this->joinUrl((string) $invitation['code'], $host)
                : null;
            $view[] = $invitation;
        }

        return $view;
    }

    public function deleteInvitation(int $id): void
    {
        $this->repository->deleteInvitation($id);
    }

    /**
     * Redeem an invitation: validate the code, create the Jellyfin account
     * with the invite's access policy, and start the expiry bookkeeping.
     * Throws RuntimeException with a user-facing message on any refusal.
     *
     * @param array<string, string> $posted
     */
    public function redeem(string $code, array $posted): void
    {
        $username = trim((string) ($posted['username'] ?? ''));
        $password = (string) ($posted['password'] ?? '');
        $confirm = (string) ($posted['password2'] ?? '');

        if ($username === '' || strlen($username) > 100) {
            throw new \RuntimeException('Please pick a username (max 100 characters).');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('The password must be at least 8 characters long.');
        }
        if ($password !== $confirm) {
            throw new \RuntimeException('The passwords do not match.');
        }

        $invitation = $this->repository->invitationByCode(trim($code));
        if ($invitation === null) {
            throw new \RuntimeException('This invitation code is not valid.');
        }
        if ($invitation['used_by'] !== null) {
            throw new \RuntimeException('This invitation has already been used.');
        }
        if ($invitation['link_expires_at'] !== null && strtotime((string) $invitation['link_expires_at']) < time()) {
            throw new \RuntimeException('This invitation has expired.');
        }

        foreach ($this->jellyfin->users() as $user) {
            if (strcasecmp((string) $user['name'], $username) === 0) {
                throw new \RuntimeException('That username is already taken.');
            }
        }

        $user = $this->jellyfin->createUser($username, $password);
        $this->applyPolicy(
            (string) $user['Id'],
            array_values(array_filter(array_map('strval', (array) json_decode((string) $invitation['libraries'], true)))),
            (bool) $invitation['allow_downloads'],
            (bool) $invitation['allow_live_tv'],
            false,
        );

        $expiresAt = $invitation['duration_days'] !== null
            ? date('Y-m-d H:i:s', time() + (int) $invitation['duration_days'] * 86400)
            : null;

        $this->repository->trackAccount((string) $user['Id'], $username, $expiresAt);
        $this->repository->markUsed((int) $invitation['id'], $username);
    }

    public function setDisabled(string $jellyfinUserId, bool $disabled): void
    {
        $this->applyPolicyUpdate($jellyfinUserId, ['IsDisabled' => $disabled]);
        $disabled
            ? $this->repository->markDisabled($jellyfinUserId)
            : $this->repository->clearDisabled($jellyfinUserId);
    }

    /**
     * Extend an invite-managed account. Re-enables it when the extension
     * clears the expiry that had it disabled.
     */
    public function extend(string $jellyfinUserId, int $days): ?string
    {
        $expires = $this->repository->extendAccount($jellyfinUserId, $days);
        if ($expires !== null && strtotime($expires) > time()) {
            $this->setDisabled($jellyfinUserId, false);
        }

        return $expires;
    }

    /**
     * Disable every tracked account past its expiry. Cheap: one indexed
     * SELECT, and a policy write only when something actually lapsed.
     *
     * @return int number of accounts disabled
     */
    public function enforceExpiry(): int
    {
        $disabled = 0;
        foreach ($this->repository->expiredAccounts() as $account) {
            if ($account['disabled_at'] !== null) {
                continue;
            }
            $this->setDisabled((string) $account['jellyfin_user_id'], true);
            $disabled++;
        }

        return $disabled;
    }

    /**
     * Absolute URL of the public join page. Falls back to a relative path
     * when the request context is unknown (CLI, tests).
     */
    public function joinUrl(string $code, ?string $host = null): string
    {
        $path = '/?page=join&code=' . rawurlencode($code);
        if ($host === null || $host === '') {
            return $path;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<int, string> $libraryIds
     */
    private function applyPolicy(
        string $userId,
        array $libraryIds,
        bool $allowDownloads,
        bool $allowLiveTv,
        bool $disabled,
    ): void {
        $this->applyPolicyUpdate($userId, [
            'IsDisabled' => $disabled,
            'EnableAllFolders' => $libraryIds === [],
            'EnabledFolders' => $libraryIds,
            'EnableContentDownloading' => $allowDownloads,
            'EnableLiveTvAccess' => $allowLiveTv,
        ]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyPolicyUpdate(string $userId, array $patch): void
    {
        try {
            $this->jellyfin->updatePolicy($userId, $patch);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Could not update the Jellyfin account: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Unambiguous lowercase code, e.g. "k7m3xq2a" — readable enough to copy
     * by hand, random enough (43 bits) to rule out guessing.
     */
    private function generateCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
