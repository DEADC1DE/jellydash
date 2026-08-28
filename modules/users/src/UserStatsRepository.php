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

    public function recentPlaysCount(string $userName): int
    {
        return (int) $this->dibi->select('COUNT(*)')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->fetchSingle();
    }

    /**
     * @return array<int, array{itemName: ?string, seriesName: ?string, seasonEp: ?string, library: ?string, client: ?string, device: ?string, watchedSec: int, startedAt: string, isFinished: bool}>
     */
    public function recentPlays(string $userName, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->dibi->select('item_name, series_name, season_ep, library, client, device, watched_sec, started_at, is_finished')
            ->from('play_history')
            ->where('user_name = %s', $userName)
            ->orderBy('started_at')->desc()
            ->limit($limit)->offset($offset)
            ->fetchAll();

        $plays = [];
        foreach ($rows as $row) {
            $plays[] = [
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
