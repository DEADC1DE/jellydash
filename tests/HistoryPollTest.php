<?php

declare(strict_types=1);

use Mk\Framework\Console\HistoryPoll;
use PHPUnit\Framework\TestCase;

final class HistoryPollTest extends TestCase
{
    public function testDispatchesNotificationsWhenRecordingActiveHistoryFails(): void
    {
        $dispatched = false;
        $logged = [];

        (new HistoryPoll(
            static function (): int {
                throw new RuntimeException('History database is unavailable');
            },
            static function () use (&$dispatched): int {
                $dispatched = true;

                return 1;
            },
            static function (Throwable $error) use (&$logged): void {
                $logged[] = $error->getMessage();
            },
            static function (string $message): void {
            },
        ))->run();

        $this->assertTrue($dispatched);
        $this->assertSame(['History database is unavailable'], $logged);
    }
}
