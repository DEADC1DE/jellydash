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
}
