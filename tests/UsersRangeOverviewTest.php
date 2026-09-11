<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/users/src/UserStatsRepository.php';
require_once __DIR__ . '/../modules/users/src/UserRangeOverview.php';

use Mk\Framework\Database;
use Mk\Modules\Users\UserRangeOverview;
use Mk\Modules\Users\UserStatsRepository;
use PHPUnit\Framework\TestCase;

final class UsersRangeOverviewTest extends TestCase
{
    private Database $db;
    private UserStatsRepository $repository;

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->repository = new UserStatsRepository($this->db);
        $this->db->getDibi()->query('
            CREATE TABLE play_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                user_name TEXT,
                watched_sec INTEGER,
                watch_duration_sec INTEGER,
                started_at TEXT,
                item_id TEXT,
                item_type TEXT,
                item_name TEXT,
                series_name TEXT,
                season_ep TEXT
            )
        ');
    }

    private function play(string $userId, string $userName, string $startedAt, int $watchedSec, ?int $watchDurationSec = null): void
    {
        $this->db->getDibi()->insert('play_history', [
            'user_id' => $userId,
            'user_name' => $userName,
            'watched_sec' => $watchedSec,
            'watch_duration_sec' => $watchDurationSec,
            'started_at' => $startedAt,
        ])->execute();
    }

    private function itemPlay(string $itemId, string $itemType, string $itemName, string $userName, string $startedAt, int $watchedSec, string $series = '', string $seasonEp = ''): void
    {
        $this->db->getDibi()->insert('play_history', [
            'user_id' => 'jf_test_id_' . strtolower($userName),
            'user_name' => $userName,
            'watched_sec' => $watchedSec,
            'started_at' => $startedAt,
            'item_id' => $itemId,
            'item_type' => $itemType,
            'item_name' => $itemName,
            'series_name' => $series,
            'season_ep' => $seasonEp,
        ])->execute();
    }

    /**
     * Fixed "now" so period windows are deterministic:
     * week = 2026-09-02..now, previous week = 2026-08-26..09-01,
     * month = 2026-08-10..now, previous month = 2026-07-11..08-09.
     */
    private function overview(string $range): array
    {
        $jellyfinUsers = [
            ['id' => 'jf_test_id_a', 'name' => 'Alpha One'],
            ['id' => 'jf_test_id_b', 'name' => 'Beta Two'],
        ];

        return (new UserRangeOverview($this->repository))->build(
            $range,
            $jellyfinUsers,
            new DateTimeImmutable('2026-09-08 12:00:00')
        );
    }

    public function testWeekOverviewRanksUsersAndComputesSharesAveragesAndDeltas(): void
    {
        // In week: 1800 counted (sampled wins), 900 fallback from watched_sec.
        $this->play('jf_test_id_a', 'Alpha One', '2026-09-03 20:00:00', 1700, 1800);
        $this->play('jf_test_id_a', 'Alpha One', '2026-09-05 21:00:00', 900);
        // Previous week only: 600.
        $this->play('jf_test_id_a', 'Alpha One', '2026-08-30 20:00:00', 600);
        // In week: 3600 — tops Alpha's 2700.
        $this->play('jf_test_id_b', 'Beta Two', '2026-09-06 22:00:00', 3600);

        $overview = $this->overview('week');

        $this->assertSame('week', $overview['range']);
        $this->assertSame('Last 7 days', $overview['subLabel']);

        $users = $overview['users'];
        $this->assertCount(2, $users);
        $this->assertSame('Beta Two', $users[0]['user']);
        $this->assertSame('1h 0m', $users[0]['watch']);
        $this->assertSame('1', $users[0]['plays']);
        $this->assertSame('100%', $users[0]['w']);
        $this->assertSame('57%', $users[0]['share']);

        $this->assertSame('Alpha One', $users[1]['user']);
        $this->assertSame('45m', $users[1]['watch']);
        $this->assertSame('2', $users[1]['plays']);
        $this->assertSame('23m', $users[1]['avg']);
        $this->assertSame('75%', $users[1]['w']);
        $this->assertSame('43%', $users[1]['share']);
        $this->assertSame('/api/image.php?user=jf_test_id_a&maxWidth=80', $users[1]['avatarUrl']);

        $kpis = array_column($overview['kpis'], null, 'label');
        $this->assertSame('1h 45m', $kpis['Total Watch Time']['value']);
        $this->assertSame('+950% vs previous', $kpis['Total Watch Time']['delta']);
        $this->assertSame('#46e0b0', $kpis['Total Watch Time']['deltaColor']);
        $this->assertSame('3', $kpis['Plays']['value']);
        $this->assertSame('+200% vs previous', $kpis['Plays']['delta']);
        $this->assertSame('2', $kpis['Active Users']['value']);
        $this->assertSame('+1 user vs previous', $kpis['Active Users']['delta']);
    }

    public function testMonthOverviewIncludesMidPeriodPlaysButNotOlderOnes(): void
    {
        $this->play('jf_test_id_a', 'Alpha One', '2026-09-05 21:00:00', 900);
        $this->play('jf_test_id_a', 'Alpha One', '2026-08-30 20:00:00', 600);
        $this->play('jf_test_id_b', 'Beta Two', '2026-08-15 20:00:00', 1200);
        // Before the month window, must not appear.
        $this->play('jf_test_id_a', 'Alpha One', '2026-07-20 20:00:00', 3000);

        $overview = $this->overview('month');

        // Alpha 1500s (900 + 600) leads over Beta 1200s inside the month window.
        $this->assertSame(
            ['Alpha One', 'Beta Two'],
            array_map(static fn (array $user): string => (string) $user['user'], $overview['users'])
        );
        $this->assertSame('25m', $overview['users'][0]['watch']);

        // Previous month: only Alpha's 2026-07-20 play (3000s); this month: 2700s.
        $kpis = array_column($overview['kpis'], null, 'label');
        $this->assertSame('-10% vs previous', $kpis['Total Watch Time']['delta']);
        $this->assertSame('#f7b955', $kpis['Total Watch Time']['deltaColor']);
        $this->assertSame('2', $kpis['Active Users']['value']);
    }

    public function testAllTimeCoversLifetimeAndReportsLifetimeDeltas(): void
    {
        $this->play('jf_test_id_a', 'Alpha One', '2026-09-03 20:00:00', 2700);
        $this->play('jf_test_id_b', 'Beta Two', '2026-08-15 20:00:00', 1200);
        $this->play('jf_test_id_a', 'Alpha One', '2026-07-01 20:00:00', 3000);

        $overview = $this->overview('all');

        $this->assertSame('all', $overview['range']);
        $this->assertSame('All recorded history', $overview['subLabel']);

        // Alpha lifetime 5700s leads over Beta 1200s.
        $this->assertSame('Alpha One', $overview['users'][0]['user']);
        $this->assertSame('1h 35m', $overview['users'][0]['watch']);

        $kpis = array_column($overview['kpis'], null, 'label');
        $this->assertSame('lifetime total', $kpis['Total Watch Time']['delta']);
        $this->assertSame('rgba(255,255,255,0.42)', $kpis['Total Watch Time']['deltaColor']);
        $this->assertSame('lifetime viewers', $kpis['Active Users']['delta']);
    }

    public function testRangeSelectionAndHrefLinks(): void
    {
        $overview = $this->overview('bogus-range');

        $this->assertSame('week', $overview['range']);

        $hrefs = array_column($overview['ranges'], 'href', 'key');
        $this->assertSame('/users?range=week', $hrefs['week']);
        $this->assertSame('/users?range=month', $hrefs['month']);
        $this->assertSame('/users?range=all', $hrefs['all']);

        $active = array_column($overview['ranges'], 'active', 'key');
        $this->assertTrue($active['week']);
        $this->assertFalse($active['month']);
        $this->assertFalse($active['all']);
    }

    public function testEmptyPeriodYieldsNoUserRowsAndNeutralDeltas(): void
    {
        // Only activity far outside every window except all-time.
        $this->play('jf_test_id_a', 'Alpha One', '2026-01-01 20:00:00', 600);

        $overview = $this->overview('week');

        $this->assertSame([], $overview['users']);
        $kpis = array_column($overview['kpis'], null, 'label');
        $this->assertSame('0m', $kpis['Total Watch Time']['value']);
        $this->assertSame('no activity this period', $kpis['Total Watch Time']['delta']);
        $this->assertSame('0', $kpis['Active Users']['value']);
    }

    public function testRangeTopTitlesAggregatesPerItemAndRanksTopUser(): void
    {
        // 3 plays across two users: Beta tops with 2 plays, Alpha has the longer single watch.
        $this->itemPlay('itm_series', 'Episode', 'Pilot', 'Alpha One', '2026-09-03 20:00:00', 2400, 'Test Series', 'S1 E1');
        $this->itemPlay('itm_series', 'Episode', 'Episode Two', 'Beta Two', '2026-09-04 20:00:00', 600, 'Test Series', 'S1 E2');
        $this->itemPlay('itm_series', 'Episode', 'Episode Three', 'Beta Two', '2026-09-05 20:00:00', 600, 'Test Series', 'S1 E3');
        $this->itemPlay('itm_movie', 'Movie', 'Test Movie', 'Alpha One', '2026-09-06 20:00:00', 5400);
        // Outside the week window: must be ignored.
        $this->itemPlay('itm_old', 'Movie', 'Old Movie', 'Alpha One', '2026-08-20 20:00:00', 9000);

        $titles = $this->repository->rangeTopTitles(new DateTimeImmutable('2026-09-02 00:00:00'));

        $this->assertCount(2, $titles);

        // Movie leads on watch seconds (5400 vs 3600).
        $this->assertSame('itm_movie', $titles[0]['itemId']);
        $this->assertSame('Test Movie', $titles[0]['name']);
        $this->assertSame('Alpha One', $titles[0]['topUser']);
        $this->assertSame(1, $titles[0]['plays']);
        $this->assertSame(5400, $titles[0]['watchSec']);

        $series = $titles[1];
        $this->assertSame('itm_series', $series['itemId']);
        $this->assertSame(3, $series['plays']);
        $this->assertSame(3600, $series['watchSec']);
        // Beta Two (2 plays / 1200s) outranks Alpha One (1 play) as top viewer.
        $this->assertSame('Beta Two', $series['topUser']);
    }

    public function testRangeTopTitlesLimitsAndMapsEpisodesAndMovies(): void
    {
        $this->itemPlay('itm_series', 'Episode', 'Pilot', 'Alpha One', '2026-09-03 20:00:00', 1800, 'Test Series', 'S1 E1');
        $this->itemPlay('itm_movie', 'Movie', 'Test Movie', 'Beta Two', '2026-09-04 20:00:00', 5400);

        $overview = $this->overview('week');
        $titles = $overview['titles'];

        $this->assertCount(2, $titles);

        // Movie first (watch time ranking), no viewer context line.
        $this->assertSame('itm_movie', $titles[0]['itemId']);
        $this->assertFalse($titles[0]['isEpisode']);
        $this->assertSame('Test Movie', $titles[0]['name']);
        $this->assertSame('', $titles[0]['sub']);
        $this->assertSame('1h 30m', $titles[0]['watch']);

        // Episode second: series carries the label, episode code + title the context line.
        $this->assertSame('itm_series', $titles[1]['itemId']);
        $this->assertTrue($titles[1]['isEpisode']);
        $this->assertSame('Test Series', $titles[1]['name']);
        $this->assertSame('S1 E1 · Pilot', $titles[1]['sub']);
        $this->assertSame('30m', $titles[1]['watch']);
    }

    public function testRangeTopTitlesRespectsLimit(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->itemPlay('itm_' . $i, 'Movie', 'Movie ' . $i, 'Alpha One', '2026-09-0' . $i . ' 20:00:00', 600 * $i);
        }

        $this->assertCount(2, $this->repository->rangeTopTitles(new DateTimeImmutable('2026-09-01 00:00:00'), 2));
        $this->assertCount(0, $this->repository->rangeTopTitles(new DateTimeImmutable('2026-09-09 00:00:00')));
    }
}
