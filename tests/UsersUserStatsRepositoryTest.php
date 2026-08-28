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
                library TEXT,
                client TEXT,
                device TEXT,
                watched_sec INTEGER,
                started_at TEXT
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
}
