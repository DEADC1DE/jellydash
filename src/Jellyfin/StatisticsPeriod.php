<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class StatisticsPeriod
{
    private const RANGES = ['week', 'month', 'year', 'all'];

    public static function isValidRange(?string $range): bool
    {
        return $range !== null && in_array($range, self::RANGES, true);
    }

    public static function normalizeRange(?string $range, string $fallback = 'week'): string
    {
        $fallback = self::isValidRange($fallback) ? $fallback : 'week';

        return self::isValidRange($range) ? (string) $range : $fallback;
    }

    public static function currentStart(string $range, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $today = $now->setTime(0, 0);

        return match ($range) {
            'week' => $today->modify('-6 days'),
            'month' => $today->modify('-29 days'),
            'year' => $today->modify('first day of this month')->modify('-11 months'),
            default => null,
        };
    }

    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null */
    public static function previous(string $range, \DateTimeImmutable $now): ?array
    {
        $end = self::currentStart($range, $now);
        if ($end === null) {
            return null;
        }

        $start = match ($range) {
            'week' => $end->modify('-7 days'),
            'month' => $end->modify('-30 days'),
            'year' => $end->modify('-12 months'),
            default => null,
        };

        return $start === null ? null : ['start' => $start, 'end' => $end];
    }
}
