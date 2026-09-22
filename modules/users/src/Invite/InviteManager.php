<?php

declare(strict_types=1);

namespace Mk\Modules\Users\Invite;

use Mk\Framework\Config;
use Mk\Framework\Jellyfin\JellyfinClient;

/**
 * Native invitation engine: creates invite codes, serves the public join
 * flow, and provisions Jellyfin accounts for redeemed invites. Replaces the
 * external Wizarr service — everything lives in the local database and the
 * Jellyfin API.
 */
final class InviteManager
{
    /** Redeem attempts allowed per client IP within one hour. */
    public const ATTEMPTS_PER_HOUR = 10;

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

        $id = $this->repository->createInvitation(
            $linkExpiresAt,
            $accessDays !== null && $accessDays > 0 ? $accessDays : null,
            $libraryIds,
            $allowDownloads,
            $allowLiveTv,
            $this->generateCode(),
        );

        return $this->repository->invitationById((int) $id);
    }

    /**
     * Invitation view models for the Users page, newest first: computed
     * status, expiry, and the join URL. Pass $baseUrl (scheme + host) to get
     * absolute links; without it the URLs stay relative.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invitations(?string $baseUrl = null): array
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
                ? $this->joinUrl((string) $invitation['code'], $baseUrl)
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
     * Whether $ip may redeem right now (per-IP throttle against code
     * brute-forcing on the public page). Records the attempt.
     */
    public function withinRateLimit(string $ip): bool
    {
        return $this->repository->recordAttempt($ip) <= self::ATTEMPTS_PER_HOUR;
    }

    /**
     * Redeem an invitation: validate the input, atomically claim the code,
     * create the Jellyfin account with the invite's access policy, and start
     * the expiry bookkeeping.
     *
     * Validation refusals throw RedeemError (safe to show the visitor);
     * infrastructure failures rethrow as RuntimeException after releasing
     * the claim, so the code stays redeemable.
     *
     * @param array<string, string> $posted
     */
    public function redeem(string $code, array $posted): void
    {
        $username = trim((string) ($posted['username'] ?? ''));
        $password = (string) ($posted['password'] ?? '');
        $confirm = (string) ($posted['password2'] ?? '');

        if ($username === '' || strlen($username) > 100) {
            throw new RedeemError('Please pick a username (max 100 characters).');
        }
        if (strlen($password) < 8) {
            throw new RedeemError('The password must be at least 8 characters long.');
        }
        if ($password !== $confirm) {
            throw new RedeemError('The passwords do not match.');
        }

        // Input validated — claim before touching Jellyfin. claimCode only
        // wins for an unused, unexpired code, which also folds the
        // valid/expired checks into the same atomic step.
        $invitation = $this->repository->claimCode(trim($code), $username);
        if ($invitation === null) {
            throw new RedeemError('This invitation code is not valid or has already been used.');
        }

        try {
            foreach ($this->jellyfin->users() as $user) {
                if (strcasecmp((string) $user['name'], $username) === 0) {
                    throw new RedeemError('That username is already taken.');
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
        } catch (\Throwable $e) {
            $this->repository->releaseClaim((int) $invitation['id']);
            if ($e instanceof RedeemError) {
                throw $e;
            }
            throw new \RuntimeException('Account provisioning failed: ' . $e->getMessage(), previous: $e);
        }

        $expiresAt = $invitation['duration_days'] !== null
            ? date('Y-m-d H:i:s', time() + (int) $invitation['duration_days'] * 86400)
            : null;

        $this->repository->trackAccount((string) $user['Id'], $username, $expiresAt);
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
     * Absolute URL of the public join page. $baseUrl is scheme + host; when
     * absent the URL stays relative. Building the base (env override,
     * proxy headers) is the caller's job — see UsersController.
     */
    public function joinUrl(string $code, ?string $baseUrl = null): string
    {
        $path = '/?page=join&code=' . rawurlencode($code);

        return $baseUrl !== null && $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    /**
     * Public base URL of this deployment, or null when unknown. Priority:
     * APP_PUBLIC_URL (recommended behind proxies), then the request's
     * X-Forwarded-Proto/Host headers, then plain HTTPS/HTTP_HOST.
     */
    public static function publicBaseUrl(): ?string
    {
        $configured = trim((string) Config::get('APP_PUBLIC_URL', ''), '/');
        if ($configured !== '') {
            return preg_match('#^https?://#i', $configured) === 1 ? $configured : null;
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || !preg_match('#^[A-Za-z0-9.\-\[\]:]+$#', $host)) {
            return null;
        }

        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $scheme = in_array($forwarded, ['https', 'http'], true)
            ? $forwarded
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

        return $scheme . '://' . $host;
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
     * Unambiguous lowercase code, e.g. "k7m3xq2apd9w". 12 characters over a
     * 31-symbol alphabet ≈ 2^59 — beyond offline-guessing reach for a link
     * that only ever lives in the admin's hands.
     */
    private function generateCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
