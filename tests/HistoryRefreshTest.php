<?php

declare(strict_types=1);

use Mk\Framework\Downloads\HistoryRefresh;
use PHPUnit\Framework\TestCase;

final class HistoryRefreshTest extends TestCase
{
    public function testSuccessWaitsOneMinuteWhileClockRollbackCanRecover(): void
    {
        self::assertTrue(HistoryRefresh::due([], 1000));
        $cursor = ['history' => HistoryRefresh::success([], 1000)];
        self::assertFalse(HistoryRefresh::due($cursor, 1015));
        self::assertTrue(HistoryRefresh::due($cursor, 1060));
        self::assertTrue(HistoryRefresh::due($cursor, 100));
    }

    public function testRepeatedFailuresRetainLastSuccessAndBoundRetries(): void
    {
        $cursor = ['history' => HistoryRefresh::success([], 900)];
        foreach ([60, 120, 240, 300, 300] as $delay) {
            $cursor['history'] = HistoryRefresh::failure($cursor, 1000, 'timeout');
            self::assertSame(1000 + $delay, $cursor['history']['next_due_at']);
            self::assertSame(900, $cursor['history']['last_success_at']);
        }
        $recovered = HistoryRefresh::success($cursor, 1100);
        self::assertNull($recovered['error']);
        self::assertSame(0, $recovered['failure_count']);
        self::assertSame(1100, $recovered['last_success_at']);
    }
}
