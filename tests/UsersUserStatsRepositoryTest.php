<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/users/src/UserStatsRepository.php';

use Mk\Framework\Database;
use Mk\Modules\Users\UserStatsRepository;
use PHPUnit\Framework\TestCase;

final class UsersUserStatsRepositoryTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->db->getDibi()->query('
            CREATE TABLE play_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name TEXT,
                item_id TEXT,
                item_type TEXT,
                item_name TEXT,
                series_name TEXT,
                season_ep TEXT,
                library TEXT,
                client TEXT,
                device TEXT,
                watched_sec INTEGER,
                started_at TEXT,
                is_finished INTEGER DEFAULT 0
            )
        ');
    }

    public function testSummaryForUserAggregatesPlaysAndTopLibrary(): void
    {
        $dibi = $this->db->getDibi();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'library' => 'Movies', 'client' => 'Jellyfin Web', 'device' => "Test Fire TV Device", 'watched_sec' => 1800, 'started_at' => '2026-08-20 20:00:00'])->execute();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'library' => 'Movies', 'client' => 'Jellyfin Web', 'device' => "Test Fire TV Device", 'watched_sec' => 3600, 'started_at' => '2026-08-21 21:00:00'])->execute();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'library' => 'TV Shows', 'client' => 'Jellyfin Android TV', 'device' => 'Other device', 'watched_sec' => 900, 'started_at' => '2026-08-22 22:00:00'])->execute();

        $summary = (new UserStatsRepository($this->db))->summaryForUser('jf_test_user_1');

        $this->assertSame(3, $summary['plays']);
        $this->assertSame(6300, $summary['watchSec']);
        $this->assertSame('2026-08-22 22:00:00', $summary['lastSeen']);
        $this->assertSame('Movies', $summary['topLibrary']);
        $this->assertSame("Test Fire TV Device", $summary['topDevice']);
        $this->assertSame(3600, $summary['longestSessionSec']);
    }

    public function testHeatmapBucketsByWeekdayAndHour(): void
    {
        $dibi = $this->db->getDibi();
        // 2026-08-24 is a Monday.
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'watched_sec' => 60, 'started_at' => '2026-08-24 20:15:00'])->execute();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'watched_sec' => 60, 'started_at' => '2026-08-24 20:45:00'])->execute();

        $heatmap = (new UserStatsRepository($this->db))->heatmap('jf_test_user_1');

        $this->assertSame(2, $heatmap[0][20]);
    }

    public function testRecentPlaysReturnsNewestFirstUpToLimit(): void
    {
        $dibi = $this->db->getDibi();
        $dibi->insert('play_history', [
            'user_name' => 'jf_test_user_1', 'item_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'item_type' => 'Episode',
            'item_name' => 'Pilot', 'series_name' => 'Naruto', 'season_ep' => 'S1 E1',
            'library' => 'Anime', 'client' => 'Jellyfin Web', 'device' => 'Test Fire TV Device',
            'watched_sec' => 1200, 'started_at' => '2026-08-20 20:00:00', 'is_finished' => 1,
        ])->execute();
        $dibi->insert('play_history', [
            'user_name' => 'jf_test_user_1', 'item_name' => 'Cruella', 'series_name' => null, 'season_ep' => null,
            'library' => 'Movies', 'client' => 'Jellyfin Android TV', 'device' => 'Other device',
            'watched_sec' => 300, 'started_at' => '2026-08-22 22:00:00', 'is_finished' => 0,
        ])->execute();
        $dibi->insert('play_history', [
            'user_name' => 'someone-else', 'item_name' => 'Not this user', 'series_name' => null, 'season_ep' => null,
            'library' => 'Movies', 'client' => 'x', 'device' => 'x',
            'watched_sec' => 60, 'started_at' => '2026-08-23 00:00:00', 'is_finished' => 0,
        ])->execute();

        $plays = (new UserStatsRepository($this->db))->recentPlays('jf_test_user_1', 10);

        $this->assertCount(2, $plays);
        $this->assertSame('Cruella', $plays[0]['itemName']);
        $this->assertNull($plays[0]['seriesName']);
        $this->assertFalse($plays[0]['isFinished']);
        $this->assertSame('Pilot', $plays[1]['itemName']);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $plays[1]['itemId']);
        $this->assertSame('Episode', $plays[1]['itemType']);
        $this->assertSame('Naruto', $plays[1]['seriesName']);
        $this->assertSame('S1 E1', $plays[1]['seasonEp']);
        $this->assertTrue($plays[1]['isFinished']);
    }

    public function testRecentPlaysRespectsLimit(): void
    {
        $dibi = $this->db->getDibi();
        for ($i = 0; $i < 5; $i++) {
            $dibi->insert('play_history', [
                'user_name' => 'jf_test_user_1', 'item_name' => "Item $i", 'watched_sec' => 60,
                'started_at' => sprintf('2026-08-2%d 12:00:00', $i),
            ])->execute();
        }

        $plays = (new UserStatsRepository($this->db))->recentPlays('jf_test_user_1', 2);

        $this->assertCount(2, $plays);
    }

    public function testRecentPlaysOffsetPagesThroughResultsNewestFirst(): void
    {
        $dibi = $this->db->getDibi();
        for ($i = 0; $i < 5; $i++) {
            $dibi->insert('play_history', [
                'user_name' => 'jf_test_user_1', 'item_name' => "Item $i", 'watched_sec' => 60,
                'started_at' => sprintf('2026-08-2%d 12:00:00', $i),
            ])->execute();
        }

        $repository = new UserStatsRepository($this->db);
        $firstPage = $repository->recentPlays('jf_test_user_1', 2, 0);
        $secondPage = $repository->recentPlays('jf_test_user_1', 2, 2);

        $this->assertSame('Item 4', $firstPage[0]['itemName']);
        $this->assertSame('Item 3', $firstPage[1]['itemName']);
        $this->assertSame('Item 2', $secondPage[0]['itemName']);
        $this->assertSame('Item 1', $secondPage[1]['itemName']);
    }

    public function testRecentPlaysCountMatchesTotalRowsForUser(): void
    {
        $dibi = $this->db->getDibi();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'item_name' => 'A', 'watched_sec' => 60, 'started_at' => '2026-08-20 12:00:00'])->execute();
        $dibi->insert('play_history', ['user_name' => 'jf_test_user_1', 'item_name' => 'B', 'watched_sec' => 60, 'started_at' => '2026-08-21 12:00:00'])->execute();
        $dibi->insert('play_history', ['user_name' => 'someone-else', 'item_name' => 'C', 'watched_sec' => 60, 'started_at' => '2026-08-22 12:00:00'])->execute();

        $count = (new UserStatsRepository($this->db))->recentPlaysCount('jf_test_user_1');

        $this->assertSame(2, $count);
    }
}
