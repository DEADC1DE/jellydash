<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

/** Schedules recent activity independently of the live queue. */
final class HistoryRefresh
{
    /** @param array<string,mixed> $cursor */
    public static function due(array $cursor, int $now): bool
    {
        $due = $cursor['history']['next_due_at'] ?? null;
        return !is_int($due) || $due <= $now || $due > $now + 300;
    }

    /** @param array<string,mixed> $cursor @return array<string,mixed> */
    public static function success(array $cursor, int $now): array
    {
        return ['last_attempt_at' => $now, 'last_success_at' => $now,
            'error' => null, 'next_due_at' => $now + 60, 'failure_count' => 0];
    }

    /** @param array<string,mixed> $cursor @return array<string,mixed> */
    public static function failure(array $cursor, int $now, string $reason): array
    {
        $old = $cursor['history'] ?? [];
        $failures = min(4, max(0, (int) ($old['failure_count'] ?? 0)) + 1);
        $last = $old['last_success_at'] ?? null;
        return ['last_attempt_at' => $now, 'last_success_at' => is_int($last) ? $last : null,
            'error' => preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) === 1 ? $reason : 'request_failed',
            'next_due_at' => $now + min(300, 30 * (2 ** $failures)), 'failure_count' => $failures];
    }
}
