<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * "Stay signed in" persistent login tokens (selector/validator scheme).
 *
 * The cookie carries `selector:validator`. The selector is an indexed lookup
 * key; the validator is checked with a hashed, constant-time comparison and
 * never stored in plaintext, so a leaked `remember_tokens` row alone can't be
 * replayed as a cookie. Every successful use rotates the token (old row
 * deleted, new one issued) so a stolen-but-already-used cookie stops working.
 */
final class RememberToken
{
    public const COOKIE_NAME = 'jellydash_remember';
    private const TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days

    // Issue a fresh token for a user and return the cookie value to set.
    public static function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(33));

        Container::db()->getDibi()->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => self::hash($validator),
            'expires_at' => new \Carbon\Carbon('@' . (time() + self::TTL_SECONDS)),
        ])->execute();

        return $selector . ':' . $validator;
    }

    /**
     * Validate a cookie value, rotate it, and return the user row plus the
     * new cookie value. Null on any failure (also deletes a stale/used row).
     *
     * @return array{user: array<string, mixed>, cookie: string}|null
     */
    public static function consume(string $cookieValue): ?array
    {
        [$selector, $validator] = array_pad(explode(':', $cookieValue, 2), 2, '');
        if ($selector === '' || $validator === '') {
            return null;
        }

        $dibi = Container::db()->getDibi();
        $row = $dibi->select('*')->from('remember_tokens')
            ->where('selector = %s', $selector)->limit(1)->fetch();

        // Always delete on presentation: a used/invalid token must not linger.
        $dibi->delete('remember_tokens')->where('selector = %s', $selector)->execute();

        if ($row === null || !hash_equals((string) $row['validator_hash'], self::hash($validator))) {
            return null;
        }

        if (new \Carbon\Carbon((string) $row['expires_at']) < \Carbon\Carbon::now()) {
            return null;
        }

        $user = $dibi->select('id, username, name, role')->from('users')
            ->where('id = %i', $row['user_id'])->limit(1)->fetch();
        if ($user === null) {
            return null;
        }

        return [
            'user' => $user->toArray(),
            'cookie' => self::issue((int) $row['user_id']),
        ];
    }

    // Delete a single token by its cookie value (e.g. on logout).
    public static function forget(string $cookieValue): void
    {
        [$selector] = explode(':', $cookieValue, 2);
        if ($selector === '') {
            return;
        }

        Container::db()->getDibi()->delete('remember_tokens')
            ->where('selector = %s', $selector)->execute();
    }

    // Delete every token for a user (e.g. on password change).
    public static function forgetForUser(int $userId): void
    {
        Container::db()->getDibi()->delete('remember_tokens')
            ->where('user_id = %i', $userId)->execute();
    }

    private static function hash(string $validator): string
    {
        // Not password_hash: the validator is a 33-byte random value, not a
        // human-chosen secret, so a fast hash is fine and keeps lookups cheap.
        return hash('sha256', $validator);
    }
}
