<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Jellyfin\JellyfinUserAvatars;
use Mk\Framework\Jellyfin\StatisticsPeriod;

/**
 * Builds the Users page period overview (last 7 / 30 days / all time): KPI
 * cards with previous-period deltas plus a per-user watch-time table in the
 * same shape the Statistics page's user table consumes, so both pages read
 * and look alike.
 */
final class UserRangeOverview
{
    private const RANGES = [
        'week' => ['label' => '7 Days', 'sub' => 'Last 7 days'],
        'month' => ['label' => '30 Days', 'sub' => 'Last 30 days'],
        'all' => ['label' => 'All Time', 'sub' => 'All recorded history'],
    ];

    private const COLORS = ['#7c5cff', '#34d8a6', '#3b9eff', '#f7b955', '#ff6b9d', '#f0913a', '#6f7bff', '#c44fff'];

    private const SERIES_BADGE_BG = 'linear-gradient(135deg,#3b9eff,#7c5cff)';
    private const MOVIE_BADGE_BG = 'linear-gradient(135deg,#7c5cff,#b06bff)';

    private const MUTED = 'rgba(255,255,255,0.42)';
    private const POSITIVE = '#46e0b0';
    private const NEGATIVE = '#f7b955';

    private JellyfinUserAvatars $avatars;

    public function __construct(private ?UserStatsRepository $repository = null)
    {
        $this->avatars = new JellyfinUserAvatars();
    }

    /**
     * @param array<int, array{id: string, name: string}> $jellyfinUsers
     * @return array<string, mixed>
     */
    public function build(string $range, array $jellyfinUsers = [], ?\DateTimeImmutable $now = null): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : 'week';
        $now ??= new \DateTimeImmutable('now');
        $repository = $this->repository ?? new UserStatsRepository();

        $start = StatisticsPeriod::currentStart($range, $now);
        $users = $this->userRows($repository->rangeSummaries($start), $jellyfinUsers);

        $totals = $repository->rangeTotals($start, null);
        $previous = null;
        if ($start !== null) {
            $period = StatisticsPeriod::previous($range, $now);
            $previous = $period !== null ? $repository->rangeTotals($period['start'], $period['end']) : null;
        }

        return [
            'range' => $range,
            'rangeLabel' => (string) self::RANGES[$range]['label'],
            'subLabel' => (string) self::RANGES[$range]['sub'],
            'ranges' => $this->ranges($range),
            'kpis' => $this->kpis($totals, $previous),
            'users' => $users,
            'titles' => $this->titleRows($repository->rangeTopTitles($start)),
        ];
    }

    /**
     * Shapes the repository's top titles for the overview table: badge letter
     * and color (S = series episode, M = movie), a primary label that carries
     * the series for episodes, and a context line — episode code + title for
     * episodes, the user who watched it most for movies.
     *
     * @param array<int, array{itemId: string, itemType: string, name: string, series: string, seasonEp: string, topUser: string, plays: int, watchSec: int}> $rows
     * @return array<int, array{badge: string, badgeBg: string, name: string, sub: string, watch: string, plays: string}>
     */
    private function titleRows(array $rows): array
    {
        $titles = [];
        foreach ($rows as $row) {
            $isEpisode = ($row['itemType'] ?? '') === 'episode'
                || (trim($row['series']) !== '' && trim($row['seasonEp']) !== '');

            if ($isEpisode) {
                $label = trim($row['series']) !== '' ? $row['series'] : $row['name'];
                $sub = trim($row['seasonEp']);
                if ($row['name'] !== '' && $row['name'] !== $label) {
                    $sub = ($sub !== '' ? $sub . ' · ' : '') . $row['name'];
                }
            } else {
                $label = $row['name'] !== '' ? $row['name'] : 'Unknown title';
                $sub = trim($row['topUser']) !== '' ? 'top: ' . $row['topUser'] : '';
            }

            $titles[] = [
                'badge' => $isEpisode ? 'S' : 'M',
                'badgeBg' => $isEpisode ? self::SERIES_BADGE_BG : self::MOVIE_BADGE_BG,
                'name' => $label,
                'sub' => $sub,
                'watch' => $this->duration((int) $row['watchSec']),
                'plays' => $this->comma((int) $row['plays']),
            ];
        }

        return $titles;
    }

    /**
     * @return array<int, array{key: string, label: string, href: string, active: bool}>
     */
    private function ranges(string $active): array
    {
        $ranges = [];
        foreach (self::RANGES as $key => $range) {
            $ranges[] = [
                'key' => $key,
                'label' => (string) $range['label'],
                'href' => '/users?range=' . $key,
                'active' => $key === $active,
            ];
        }

        return $ranges;
    }

    /**
     * @param array<string, array{userId: string, plays: int, watchSec: int}> $summaries
     * @param array<int, array{id: string, name: string}> $jellyfinUsers
     * @return array<int, array<string, mixed>>
     */
    private function userRows(array $summaries, array $jellyfinUsers): array
    {
        $avatarIds = [];
        foreach ($jellyfinUsers as $user) {
            if ($user['name'] !== '') {
                $avatarIds[$user['name']] = $user['id'];
            }
        }

        uasort($summaries, static fn (array $a, array $b): int => [$b['watchSec'], $b['plays']] <=> [$a['watchSec'], $a['plays']]);

        $max = 1;
        foreach ($summaries as $summary) {
            $max = max($max, max(0, (int) $summary['watchSec']));
        }
        $shares = $this->wholePercentages(array_map(static fn (array $summary): int => max(0, (int) $summary['watchSec']), $summaries));

        $rows = [];
        $index = 0;
        foreach ($summaries as $name => $summary) {
            $color = self::COLORS[$index % count(self::COLORS)];
            $index++;
            $seconds = (int) $summary['watchSec'];
            $plays = (int) $summary['plays'];
            $avatarId = $avatarIds[$name] ?? (string) $summary['userId'];

            $rows[] = [
                'user' => $name,
                'initials' => $this->initials($name),
                'avatarBg' => 'linear-gradient(135deg,' . $color . ',#3b9eff)',
                'avatarUrl' => $this->avatars->url($avatarId !== '' ? $avatarId : null) ?? '',
                'color' => $color,
                'watch' => $this->duration($seconds),
                'plays' => $this->comma($plays),
                'avg' => $this->duration((int) round(($seconds / max(1, $plays)) / 60) * 60),
                'w' => (int) round(($seconds / $max) * 100) . '%',
                'share' => ($shares[$name] ?? 0) . '%',
            ];
        }

        return $rows;
    }

    /**
     * @param array{plays: int, watchSec: int, users: int} $totals
     * @param array{plays: int, watchSec: int, users: int}|null $previous
     * @return array<int, array<string, string>>
     */
    private function kpis(array $totals, ?array $previous): array
    {
        return [
            $this->kpi('Total Watch Time', self::COLORS[0], $this->duration((int) $totals['watchSec']), $this->shareDelta(
                (int) $totals['watchSec'],
                $previous !== null ? (int) $previous['watchSec'] : null,
            )),
            $this->kpi('Plays', self::COLORS[1], $this->comma((int) $totals['plays']), $this->shareDelta(
                (int) $totals['plays'],
                $previous !== null ? (int) $previous['plays'] : null,
            )),
            $this->kpi('Active Users', self::COLORS[2], $this->comma((int) $totals['users']), $this->countDelta(
                (int) $totals['users'],
                $previous !== null ? (int) $previous['users'] : null,
            )),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function kpi(string $label, string $color, string $value, array $delta): array
    {
        return [
            'label' => $label,
            'color' => $color,
            'value' => $value,
            'delta' => $delta['text'],
            'deltaColor' => $delta['color'],
        ];
    }

    /**
     * Percentage movement vs the preceding period; the all-time view has no
     * predecessor, so it reports lifetime totals instead.
     *
     * @return array{text: string, color: string}
     */
    private function shareDelta(int $current, ?int $previous): array
    {
        if ($previous === null) {
            return ['text' => 'lifetime total', 'color' => self::MUTED];
        }

        if ($current <= 0 && $previous <= 0) {
            return ['text' => 'no activity this period', 'color' => self::MUTED];
        }

        if ($previous <= 0) {
            return ['text' => 'new this period', 'color' => self::POSITIVE];
        }

        $pct = (int) round((($current - $previous) / $previous) * 100);

        return [
            'text' => ($pct >= 0 ? '+' : '') . $pct . '% vs previous',
            'color' => $pct >= 0 ? self::POSITIVE : self::NEGATIVE,
        ];
    }

    /**
     * @return array{text: string, color: string}
     */
    private function countDelta(int $current, ?int $previous): array
    {
        if ($previous === null) {
            return ['text' => 'lifetime viewers', 'color' => self::MUTED];
        }

        $diff = $current - $previous;
        if ($diff === 0) {
            return ['text' => 'same as previous', 'color' => self::MUTED];
        }

        $plural = abs($diff) === 1 ? 'user' : 'users';

        return [
            'text' => ($diff > 0 ? '+' : '') . $diff . ' ' . $plural . ' vs previous',
            'color' => $diff > 0 ? self::POSITIVE : self::NEGATIVE,
        ];
    }

    /**
     * Largest-remainder percentages so the share column sums to 100 — same
     * approach as the core Statistics page.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function wholePercentages(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return array_fill_keys(array_keys($counts), 0);
        }

        $percentages = [];
        $fractions = [];
        foreach ($counts as $key => $count) {
            $exact = (max(0, $count) / $total) * 100;
            $percentages[$key] = (int) floor($exact);
            $fractions[$key] = $exact - $percentages[$key];
        }

        arsort($fractions);
        $remaining = 100 - array_sum($percentages);
        foreach (array_keys($fractions) as $key) {
            if ($remaining <= 0) {
                break;
            }
            $percentages[$key]++;
            $remaining--;
        }

        return $percentages;
    }

    private function duration(int $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        if ($minutes <= 0) {
            return $seconds > 0 ? '<1m' : '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0
            ? $this->comma($hours) . 'h ' . $remainingMinutes . 'm'
            : $remainingMinutes . 'm';
    }

    private function comma(int $value): string
    {
        return number_format($value);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return mb_substr($letters !== '' ? $letters : 'U', 0, 2);
    }
}
