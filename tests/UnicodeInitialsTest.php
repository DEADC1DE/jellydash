<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinSessionMapper;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Pages\HistoryController;
use PHPUnit\Framework\TestCase;

final class UnicodeInitialsTest extends TestCase
{
    public function testSessionMapperKeepsUnicodeInitialsIntact(): void
    {
        $stream = (new JellyfinSessionMapper())->map([[
            'Id' => 'unicode-session',
            'UserId' => 'unicode-user',
            'UserName' => 'Čestmír Novák',
            'PlayState' => ['PositionTicks' => 1, 'PlayMethod' => 'DirectPlay'],
            'NowPlayingItem' => ['Id' => 'unicode-item', 'Type' => 'Movie', 'Name' => 'Unicode Film'],
        ]])['streams'][0];

        $this->assertSame('ČN', $stream['initials']);
    }

    public function testStatisticsAndHistoryKeepUnicodeInitialsIntact(): void
    {
        $history = (new ReflectionClass(HistoryController::class))->newInstanceWithoutConstructor();

        foreach ([new PlaybackStatisticsService(), $history] as $service) {
            $initials = new ReflectionMethod($service, 'initials');

            $this->assertSame('ČN', $initials->invoke($service, 'Čestmír Novák'));
        }
    }
}
