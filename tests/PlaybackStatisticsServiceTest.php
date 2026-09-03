<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\StatisticsPeriod;
use PHPUnit\Framework\TestCase;

final class PlaybackStatisticsServiceTest extends TestCase
{
    public function testStatisticsRangesNormalizeToAValidFallback(): void
    {
        $this->assertTrue(StatisticsPeriod::isValidRange('month'));
        $this->assertFalse(StatisticsPeriod::isValidRange('decade'));
        $this->assertSame('month', StatisticsPeriod::normalizeRange('month'));
        $this->assertSame('year', StatisticsPeriod::normalizeRange(null, 'year'));
        $this->assertSame('week', StatisticsPeriod::normalizeRange('decade', 'invalid'));
    }

    public function testYearTrendAlwaysBuildsTwelveCalendarMonthsAtMonthEnd(): void
    {
        $service = new PlaybackStatisticsService();
        $method = new ReflectionMethod($service, 'monthTrend');
        $trend = $method->invoke($service, [], new DateTimeImmutable('2026-03-31 12:00:00'));

        $this->assertIsArray($trend);
        $this->assertCount(12, $trend);
        $this->assertSame(
            ['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar'],
            array_column($trend, 'label'),
        );
    }

    public function testTrendCaptionsDescribeRollingRanges(): void
    {
        $service = new PlaybackStatisticsService();
        $method = new ReflectionMethod($service, 'trendUnit');

        $this->assertSame('by day - last 7 days', $method->invoke($service, 'week'));
        $this->assertSame('by day - last 30 days', $method->invoke($service, 'month'));
        $this->assertSame('by month - last 12 months', $method->invoke($service, 'year'));
    }

    public function testPeriodBoundariesUseWholeCalendarBuckets(): void
    {
        $marchEnd = new DateTimeImmutable('2026-03-31 17:45:00');
        $this->assertSame('2026-03-25 00:00:00', StatisticsPeriod::currentStart('week', $marchEnd)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 00:00:00', StatisticsPeriod::currentStart('month', $marchEnd)?->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-01 00:00:00', StatisticsPeriod::currentStart('year', $marchEnd)?->format('Y-m-d H:i:s'));

        $leapDay = new DateTimeImmutable('2028-02-29 23:59:59');
        $this->assertSame('2028-01-31 00:00:00', StatisticsPeriod::currentStart('month', $leapDay)?->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-01 00:00:00', StatisticsPeriod::currentStart('year', $leapDay)?->format('Y-m-d H:i:s'));

        $previous = StatisticsPeriod::previous('year', $marchEnd);
        $this->assertNotNull($previous);
        $this->assertSame('2024-04-01 00:00:00', $previous['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-01 00:00:00', $previous['end']->format('Y-m-d H:i:s'));
    }

    public function testUserAndClientWatchTimeKeepsExactSecondsUntilFormatting(): void
    {
        $service = new PlaybackStatisticsService();
        $users = new ReflectionMethod($service, 'users');
        $clients = new ReflectionMethod($service, 'clients');
        $rows = [
            $this->statisticsRow('Alice', 'Client A', 119),
            $this->statisticsRow('Alice', 'Client A', 119),
            $this->statisticsRow('Bob', 'Client B', 60),
        ];

        $userRows = $users->invoke($service, $rows);
        $this->assertIsArray($userRows);
        $this->assertSame('3m', $userRows[0]['watch']);
        $this->assertSame('80%', $userRows[0]['share']);
        $this->assertSame('1m', $userRows[1]['watch']);
        $this->assertSame('20%', $userRows[1]['share']);

        $clientRows = $clients->invoke($service, $rows);
        $this->assertIsArray($clientRows);
        $this->assertSame('3m', $clientRows['usage'][0]['watch']);
        $this->assertSame('1m', $clientRows['usage'][1]['watch']);
    }

    public function testUserAverageUsesExactTotalSeconds(): void
    {
        $service = new PlaybackStatisticsService();
        $users = new ReflectionMethod($service, 'users');
        $rows = [
            $this->statisticsRow('Alice', 'Client A', 59),
            $this->statisticsRow('Alice', 'Client A', 59),
        ];

        $userRows = $users->invoke($service, $rows);
        $this->assertIsArray($userRows);
        $this->assertSame('1m', $userRows[0]['watch']);
        $this->assertSame('1m', $userRows[0]['avg']);
    }

    public function testDirectnessAndClientPercentagesAlwaysReconcileToOneHundred(): void
    {
        $service = new PlaybackStatisticsService();
        $directness = new ReflectionMethod($service, 'directness');
        $clients = new ReflectionMethod($service, 'clients');
        $rows = [
            ...array_fill(0, 11, $this->statisticsRow('Viewer', 'Direct Play', 60, 'DirectPlay')),
            ...array_fill(0, 102, $this->statisticsRow('Viewer', 'Direct Stream', 60, 'DirectStream')),
            ...array_fill(0, 15, $this->statisticsRow('Viewer', 'Transcode', 60, 'Transcode')),
        ];

        $mix = $directness->invoke($service, $rows);
        $this->assertIsArray($mix);
        $this->assertSame(['8%', '80%', '12%'], array_column($mix['legend'], 'pct'));
        $this->assertSame(12, $mix['transcode_pct']);

        $clientMix = $clients->invoke($service, [
            $this->statisticsRow('Viewer', 'A', 60),
            $this->statisticsRow('Viewer', 'B', 60),
            $this->statisticsRow('Viewer', 'C', 60),
        ]);
        $this->assertIsArray($clientMix);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['pct'], '%'),
            $clientMix['breakdown'],
        )));
        $this->assertStringContainsString('100.00%', $clientMix['conic']);

        $users = new ReflectionMethod($service, 'users');
        $userMix = $users->invoke($service, [
            $this->statisticsRow('A', 'Client', 60),
            $this->statisticsRow('B', 'Client', 60),
            $this->statisticsRow('C', 'Client', 60),
        ]);
        $this->assertIsArray($userMix);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['share'], '%'),
            $userMix,
        )));
    }

    public function testTrendBarsRenderZeroHonestlyAndExposeTheirValues(): void
    {
        $service = new PlaybackStatisticsService();
        $trendBars = new ReflectionMethod($service, 'trendBars');
        $bars = $trendBars->invoke($service, [
            'empty' => ['label' => 'Empty', 'sec' => 0],
            'tiny' => ['label' => 'Tiny', 'sec' => 42],
            'small' => ['label' => 'Small', 'sec' => 60],
            'largest' => ['label' => 'Largest', 'sec' => 600],
        ]);

        $this->assertIsArray($bars);
        $this->assertSame('0%', $bars[0]['h']);
        $this->assertSame('0m', $bars[0]['value']);
        $this->assertSame('7%', $bars[1]['h']);
        $this->assertSame('<1m', $bars[1]['value']);
        $this->assertSame('10%', $bars[2]['h']);
        $this->assertSame('1m', $bars[2]['value']);
        $this->assertSame('100%', $bars[3]['h']);
        $this->assertSame('10m', $bars[3]['value']);
    }

    public function testEmptyPeriodUsesNeutralCopyAndZeroTrendBars(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $service = new PlaybackStatisticsService($repository);
        $stats = $service->data('week', new DateTimeImmutable('2026-08-22 12:00:00'));

        $this->assertSame('no activity this period', $stats['kpis'][0]['delta']);
        $this->assertSame('no activity this period', $stats['kpis'][1]['delta']);
        $this->assertSame(['0%', '0%', '0%', '0%', '0%', '0%', '0%'], array_column($stats['trend'], 'h'));
        $this->assertSame('conic-gradient(rgba(255,255,255,.08) 0% 100%)', $stats['directnessConic']);
        $this->assertSame('no session data', $stats['codecCoverage']);
        $this->assertSame('no transcodes', $stats['reasonCoverage']);
    }

    public function testLongDurationsKeepTheirRemainingMinutes(): void
    {
        $service = new PlaybackStatisticsService();
        $duration = new ReflectionMethod($service, 'duration');

        $this->assertSame('245h 34m', $duration->invoke($service, (245 * 3600) + (34 * 60) + 8));
    }

    public function testLongCategoryListsEndWithAnExplicitOtherBucket(): void
    {
        $service = new PlaybackStatisticsService();
        $bars = new ReflectionMethod($service, 'bars');
        $result = $bars->invoke($service, [
            'A' => 9,
            'B' => 8,
            'C' => 7,
            'D' => 6,
            'E' => 5,
            'F' => 4,
            'G' => 3,
            'H' => 2,
            'I' => 1,
        ], 'Other reasons');

        $this->assertIsArray($result);
        $this->assertCount(7, $result);
        $this->assertSame('Other reasons', $result[6]['name']);
        $this->assertSame(6, $result[6]['count']);
        $this->assertSame(100, array_sum(array_map(
            static fn (array $row): int => (int) rtrim($row['pct'], '%'),
            $result,
        )));
    }

    public function testStatisticsReportsMetadataCoverage(): void
    {
        $database = \Mk\Framework\Database::sqlite(':memory:');
        $repository = new \Mk\Framework\Jellyfin\PlayHistoryRepository($database);
        $now = new DateTimeImmutable('2026-08-22 12:00:00');
        $repository->logActiveStreams([
            [
                'id' => 'covered',
                'itemId' => 'channel-covered',
                'itemType' => 'TvChannel',
                'itemName' => 'Covered channel',
                'user' => 'Viewer',
                'client' => 'Client',
                'playMethod' => 'Transcode',
                'watchedSec' => 120,
                'runtimeSec' => 0,
                'sourceVideoCodec' => 'H.264',
                'transcodeReasons' => ['Video codec not supported'],
            ],
            [
                'id' => 'missing',
                'itemId' => 'channel-missing',
                'itemType' => 'TvChannel',
                'itemName' => 'Missing channel',
                'user' => 'Viewer',
                'client' => 'Client',
                'playMethod' => 'Transcode',
                'watchedSec' => 120,
                'runtimeSec' => 0,
            ],
        ], $now);

        $stats = (new PlaybackStatisticsService($repository))->data('week', $now);

        $this->assertSame('1 of 2 sessions with codec data', $stats['codecCoverage']);
        $this->assertSame('1 of 2 transcodes with reason data', $stats['reasonCoverage']);
    }

    public function testConfirmedLibrariesAreFilteredWithoutJellyfinLookups(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryNames');
        $locationCalls = 0;
        $pathCalls = 0;

        $cards = $filter->invoke(
            $service,
            [
                $this->titleCard('blocked', 'WRESTLING (CURRENT)', true),
                $this->titleCard('included', 'Movies', true),
            ],
            ['wrestling (current)'],
            static function () use (&$locationCalls): array {
                $locationCalls++;

                return ['wrestling (current)' => ['/media/wrestling']];
            },
            static function (string $itemId) use (&$pathCalls): string {
                $pathCalls++;

                return '/media/' . $itemId;
            },
        );

        $this->assertIsArray($cards);
        $this->assertSame(['included'], array_column($cards, 'title'));
        $this->assertSame(0, $locationCalls);
        $this->assertSame(0, $pathCalls);
        $this->assertArrayNotHasKey('_library', $cards[0]);
        $this->assertArrayNotHasKey('_libraryConfirmed', $cards[0]);
    }

    public function testLatestRepresentativeRowCarriesItsConfirmedLibrary(): void
    {
        $service = new PlaybackStatisticsService();
        $groupTitles = new ReflectionMethod($service, 'groupTitles');
        $titleCards = new ReflectionMethod($service, 'titleCards');
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryNames');
        $rows = [
            $this->titleRow('2026-08-20 10:00:00', 'old-item', 'Blocked', '2026-08-20 10:00:00'),
            $this->titleRow('2026-08-21 10:00:00', 'new-item', 'Movies', '2026-08-21 10:00:00'),
        ];

        $groups = $groupTitles->invoke($service, $rows);
        $this->assertIsArray($groups);
        $cards = $titleCards->invoke($service, $groups);
        $this->assertIsArray($cards);
        $filtered = $filter->invoke(
            $service,
            $cards,
            ['blocked'],
            static fn (): never => throw new RuntimeException('Confirmed cards must not load locations.'),
            static fn (string $itemId): never => throw new RuntimeException('Confirmed cards must not load paths: ' . $itemId),
        );

        $this->assertIsArray($filtered);
        $this->assertCount(1, $filtered);
        $this->assertSame('new-item', $filtered[0]['itemId']);
    }

    public function testUnresolvedLibrariesRetainPathFallbackAndDeletedItemHandling(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryNames');
        $locationCalls = 0;
        $pathCalls = [];
        $paths = [
            'excluded' => '/media/wrestling/show.mkv',
            'deleted' => '',
            'included' => '/media/movies/film.mkv',
        ];

        $cards = $filter->invoke(
            $service,
            [
                $this->titleCard('excluded'),
                $this->titleCard('deleted'),
                $this->titleCard('included'),
            ],
            ['wrestling'],
            static function () use (&$locationCalls): array {
                $locationCalls++;

                return ['wrestling' => ['/media/wrestling']];
            },
            static function (string $itemId) use (&$pathCalls, $paths): string {
                $pathCalls[] = $itemId;

                return $paths[$itemId];
            },
        );

        $this->assertIsArray($cards);
        $this->assertSame(['included'], array_column($cards, 'title'));
        $this->assertSame(1, $locationCalls);
        $this->assertSame(['excluded', 'deleted', 'included'], $pathCalls);
    }

    public function testUnresolvedLibraryLookupStillFailsOpen(): void
    {
        $service = new PlaybackStatisticsService();
        $filter = new ReflectionMethod($service, 'withoutExcludedLibraryNames');
        $pathCalls = 0;

        $cards = $filter->invoke(
            $service,
            [$this->titleCard('first'), $this->titleCard('second')],
            ['wrestling'],
            static fn (): never => throw new RuntimeException('Jellyfin unavailable'),
            static function (string $itemId) use (&$pathCalls): string {
                $pathCalls++;

                return '/media/' . $itemId;
            },
        );

        $this->assertIsArray($cards);
        $this->assertSame(['first', 'second'], array_column($cards, 'title'));
        $this->assertSame(0, $pathCalls);
    }

    /** @return array<string, mixed> */
    private function titleCard(string $itemId, string $library = '', bool $confirmed = false): array
    {
        return [
            'title' => $itemId,
            'itemId' => $itemId,
            '_library' => $library,
            '_libraryConfirmed' => $confirmed,
        ];
    }

    private function titleRow(string $startedAt, string $itemId, string $library, string $resolvedAt): \Dibi\Row
    {
        return new \Dibi\Row([
            'item_type' => 'Movie',
            'series_name' => '',
            'item_name' => 'Shared title',
            'watched_sec' => 60,
            'user_name' => 'Viewer',
            'started_at' => $startedAt,
            'item_id' => $itemId,
            'library' => $library,
            'library_resolved_at' => $resolvedAt,
        ]);
    }

    private function statisticsRow(
        string $user,
        string $client,
        int $watchedSec,
        string $playMethod = 'DirectPlay',
    ): \Dibi\Row {
        return new \Dibi\Row([
            'user_name' => $user,
            'user_id' => strtolower(str_replace(' ', '-', $user)),
            'client' => $client,
            'watched_sec' => $watchedSec,
            'play_method' => $playMethod,
        ]);
    }
}
