<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Main;

final class StatisticsController extends Controller
{
    public function handle(): void
    {
        $range = Main::captureGetString('range') ?? 'week';
        if (!in_array($range, ['week', 'month', 'year', 'all'], true)) {
            $range = 'week';
        }

        $stats = (new PlaybackStatisticsService())->data($range);

        $this->render('statistics/index', [
            'layout' => $this->layout([
                'title' => 'Statistics',
                'page' => 'statistics',
                'hide_footer' => true,
            ]),
            'stats' => $stats,
        ]);
    }
}
