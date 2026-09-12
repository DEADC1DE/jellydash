<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Container;
use Mk\Framework\Database;

final class UserStatsRepository
{
    private \Dibi\Connection $dibi;

    public function __construct(?Database $database = null)
    {
        $this->dibi = ($database ?? Container::db())->getDibi();
    }

    /**
     * @return array{plays: int, watchSec: int, lastSeen: ?string, topLibrary: ?string, topDevice: ?string, longestSessionSec: int}
     */
    public function summaryForUser(string $userName): array
    {
        $totals = $this->dibi->select('COUNT(*) AS plays, COALESCE(SUM(watched_sec), 0) AS watch_sec, MAX(started_at) AS last_seen, COALESCE(MAX(watched_sec), 0) AS longest')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->fetch();

        $topLibrary = $this->dibi->select('library')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->where('library IS NOT NULL AND library != %s', '')
            ->groupBy('library')
            ->orderBy('COUNT(*)')->desc()
            ->limit(1)
            ->fetchSingle();

        $topDevice = $this->dibi->select('device')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->where('device IS NOT NULL AND device != %s', '')
            ->groupBy('device')
            ->orderBy('COUNT(*)')->desc()
            ->limit(1)
            ->fetchSingle();

        return [
            'plays' => (int) ($totals['plays'] ?? 0),
            'watchSec' => (int) ($totals['watch_sec'] ?? 0),
            'lastSeen' => $totals['last_seen'] !== null ? (string) $totals['last_seen'] : null,
            'topLibrary' => $topLibrary !== false && $topLibrary !== null ? (string) $topLibrary : null,
            'topDevice' => $topDevice !== false && $topDevice !== null ? (string) $topDevice : null,
            'longestSessionSec' => (int) ($totals['longest'] ?? 0),
        ];
    }

    /**
     * Per-user playback aggregates for a period, ranked-ready: one grouped
     * query so the range overview stays a single round trip. Watch seconds
     * prefer the sampled watch_duration_sec, falling back to watched_sec —
     * the same rule the core Statistics page applies.
     *
     * @return array<string, array{userId: string, plays: int, watchSec: int}> keyed by user name
     */
    public function rangeSummaries(?\DateTimeImmutable $start): array
    {
        $selection = $this->dibi
            ->select('user_name, MAX(user_id) AS user_id, COUNT(*) AS plays, COALESCE(SUM(COALESCE(watch_duration_sec, watched_sec)), 0) AS watch_sec')
            ->from('play_history');

        if ($start !== null) {
            $selection->where('started_at >= %s', $start->format('Y-m-d H:i:s'));
        }

        $summaries = [];
        foreach ($selection->groupBy('user_name')->fetchAll() as $row) {
            $name = trim((string) ($row['user_name'] ?? ''));
            $summaries[$name !== '' ? $name : 'Unknown user'] = [
                'userId' => trim((string) ($row['user_id'] ?? '')),
                'plays' => (int) $row['plays'],
                'watchSec' => (int) $row['watch_sec'],
            ];
        }

        return $summaries;
    }

    /**
     * Whole-period totals for a range — used for the overview KPIs and their
     * comparison against the preceding period ($end is exclusive).
     *
     * @return array{plays: int, watchSec: int, users: int}
     */
    public function rangeTotals(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $selection = $this->dibi
            ->select('COUNT(*) AS plays, COALESCE(SUM(COALESCE(watch_duration_sec, watched_sec)), 0) AS watch_sec, COUNT(DISTINCT user_name) AS users')
            ->from('play_history');

        if ($start !== null) {
            $selection->where('started_at >= %s', $start->format('Y-m-d H:i:s'));
        }

        if ($end !== null) {
            $selection->where('started_at < %s', $end->format('Y-m-d H:i:s'));
        }

        $totals = $selection->fetch();

        return [
            'plays' => (int) ($totals['plays'] ?? 0),
            'watchSec' => (int) ($totals['watch_sec'] ?? 0),
            'users' => (int) ($totals['users'] ?? 0),
        ];
    }

    /**
     * Most-watched titles for a period, Tautulli "Top Movies / TV Shows"
     * style: one row per item with play count, watch seconds and the user
     * who watched it most. Watch seconds prefer the sampled
     * watch_duration_sec, falling back to watched_sec — the same rule the
     * rest of the overview applies.
     *
     * @return array<int, array{itemId: string, itemType: string, name: string, series: string, seasonEp: string, topUser: string, plays: int, watchSec: int}>
     */
    public function rangeTopTitles(?\DateTimeImmutable $start, int $limit = 10): array
    {
        // Item ids from Playback Reporting imports may carry GUID dashes while
        // live polls store plain hex — normalize so both merge into one item.
        $sql = "SELECT item_id, item_type, item_name, series_name, season_ep, top_user, item_plays AS plays, item_watch_sec AS watch_sec
            FROM (
                SELECT per_user.*,
                    ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY user_plays DESC, user_watch_sec DESC, top_user ASC) AS user_rank
                FROM (
                    SELECT REPLACE(item_id, '-', '') AS item_id,
                        MAX(item_type) AS item_type,
                        MAX(item_name) AS item_name,
                        MAX(series_name) AS series_name,
                        MAX(season_ep) AS season_ep,
                        user_name AS top_user,
                        COUNT(*) AS user_plays,
                        SUM(COALESCE(watch_duration_sec, watched_sec)) AS user_watch_sec,
                        SUM(COUNT(*)) OVER (PARTITION BY REPLACE(item_id, '-', '')) AS item_plays,
                        SUM(SUM(COALESCE(watch_duration_sec, watched_sec))) OVER (PARTITION BY REPLACE(item_id, '-', '')) AS item_watch_sec
                    FROM play_history";

        $args = [];
        if ($start !== null) {
            $sql .= ' WHERE started_at >= %s';
            $args[] = $start->format('Y-m-d H:i:s');
        }

        $sql .= ' GROUP BY item_id, user_name
                ) AS per_user
            ) AS ranked
            WHERE user_rank = 1
            ORDER BY COALESCE(watch_sec, 0) DESC, plays DESC, item_name ASC
            LIMIT ' . max(1, $limit);

        $rows = $this->dibi->query($sql, ...$args)->fetchAll();

        $titles = [];
        foreach ($rows as $row) {
            $titles[] = [
                'itemId' => trim((string) ($row['item_id'] ?? '')),
                'itemType' => strtolower(trim((string) ($row['item_type'] ?? ''))),
                'name' => trim((string) ($row['item_name'] ?? '')),
                'series' => trim((string) ($row['series_name'] ?? '')),
                'seasonEp' => trim((string) ($row['season_ep'] ?? '')),
                'topUser' => trim((string) ($row['top_user'] ?? '')),
                'plays' => (int) $row['plays'],
                'watchSec' => (int) $row['watch_sec'],
            ];
        }

        return $titles;
    }

    public function recentPlaysCount(string $userName): int
    {
        return (int) $this->dibi->select('COUNT(*)')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->fetchSingle();
    }

    /**
     * @return array<int, array{itemId: ?string, itemType: ?string, itemName: ?string, seriesName: ?string, seasonEp: ?string, library: ?string, client: ?string, device: ?string, watchedSec: int, startedAt: string, isFinished: bool}>
     */
    public function recentPlays(string $userName, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->dibi->select('item_id, item_type, item_name, series_name, season_ep, library, client, device, watched_sec, started_at, is_finished')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->orderBy('started_at')->desc()
            ->limit($limit)->offset($offset)
            ->fetchAll();

        $plays = [];
        foreach ($rows as $row) {
            $plays[] = [
                'itemId' => $row['item_id'] !== null ? (string) $row['item_id'] : null,
                'itemType' => $row['item_type'] !== null ? (string) $row['item_type'] : null,
                'itemName' => $row['item_name'] !== null ? (string) $row['item_name'] : null,
                'seriesName' => $row['series_name'] !== null ? (string) $row['series_name'] : null,
                'seasonEp' => $row['season_ep'] !== null ? (string) $row['season_ep'] : null,
                'library' => $row['library'] !== null ? (string) $row['library'] : null,
                'client' => $row['client'] !== null ? (string) $row['client'] : null,
                'device' => $row['device'] !== null ? (string) $row['device'] : null,
                'watchedSec' => (int) $row['watched_sec'],
                'startedAt' => (string) $row['started_at'],
                'isFinished' => (bool) $row['is_finished'],
            ];
        }

        return $plays;
    }

    /**
     * @return array<int, array<int, int>> [weekday(0=Mon..6=Sun)][hour(0-23)] => count
     */
    public function heatmap(string $userName): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        $rows = $this->dibi->select('started_at')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->fetchAll();

        foreach ($rows as $row) {
            $timestamp = strtotime((string) $row['started_at']);
            if ($timestamp === false) {
                continue;
            }
            $weekday = ((int) date('N', $timestamp)) - 1; // 1=Mon..7=Sun -> 0..6
            $hour = (int) date('G', $timestamp);
            $grid[$weekday][$hour]++;
        }

        return $grid;
    }
}
