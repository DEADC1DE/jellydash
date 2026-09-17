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

    public function testNotificationFailureIsLoggedWithoutStoppingTheHistoryTick(): void
    {
        $logged = [];

        (new HistoryPoll(
            static fn (): int => 0,
            static function (): never {
                throw new RuntimeException('Notification database is unavailable');
            },
            static function (Throwable $error) use (&$logged): void {
                $logged[] = $error->getMessage();
            },
            static function (string $message): void {
            },
        ))->run();

        self::assertSame(['Notification database is unavailable'], $logged);
    }
}
