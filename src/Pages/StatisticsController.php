<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\AppSettings;
use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\StatisticsPeriod;
use Mk\Framework\Main;

final class StatisticsController extends Controller
{
    public function handle(): void
    {
        $defaultRange = StatisticsPeriod::normalizeRange(AppSettings::get('statistics_default_range'));
        $range = StatisticsPeriod::normalizeRange(Main::captureGetString('range'), $defaultRange);

        $stats = (new PlaybackStatisticsService())->cachedData($range);

        $this->render('statistics/index', [
            'layout' => $this->layout([
                'title' => 'Statistics',
                'page' => 'statistics',
                'hide_footer' => true,
            ]),
            'stats' => $stats,
            'default_range' => $defaultRange,
        ]);
    }
}
