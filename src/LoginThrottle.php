<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Brute-force protection for login (S7).
 *
 * Tracks failed attempts per username+IP in the `login_attempts` table; after
 * MAX_ATTEMPTS failures the identifier is locked for LOCKOUT_SECONDS.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    public static function isLocked(string $username, string $ip): bool
    {
        $row = self::row($username, $ip);
        if ($row === null || $row['locked_until'] === null) {
            return false;
        }

        return new \Carbon\Carbon($row['locked_until']) > \Carbon\Carbon::now();
    }

    public static function recordFailure(string $username, string $ip): void
    {
        $dibi = Container::db()->getDibi();
        $id = self::identifier($username, $ip);
        $row = self::row($username, $ip);

        $attempts = ($row['attempts'] ?? 0) + 1;
        $lockedUntil = $attempts >= self::MAX_ATTEMPTS
            ? \Carbon\Carbon::now()->addSeconds(self::LOCKOUT_SECONDS)
            : null;

        if ($row === null) {
            $dibi->insert('login_attempts', [
                'identifier' => $id,
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'updated_at' => \Carbon\Carbon::now(),
            ])->execute();
        } else {
            $dibi->update('login_attempts', [
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'updated_at' => \Carbon\Carbon::now(),
            ])->where('identifier = %s', $id)->execute();
        }
    }

    public static function clear(string $username, string $ip): void
    {
        Container::db()->getDibi()
            ->delete('login_attempts')->where('identifier = %s', self::identifier($username, $ip))->execute();
    }

    private static function identifier(string $username, string $ip): string
    {
        return substr($username . '|' . $ip, 0, 190);
    }

    /** @return array<string, mixed>|null */
    private static function row(string $username, string $ip): ?array
    {
        $row = Container::db()->getDibi()
            ->select('*')->from('login_attempts')
            ->where('identifier = %s', self::identifier($username, $ip))->limit(1)->fetch();

        return $row ? $row->toArray() : null;
    }
}
