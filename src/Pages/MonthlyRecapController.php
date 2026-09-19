<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\MonthlyRecapService;
use Mk\Framework\Main;

final class MonthlyRecapController extends Controller
{
    public function handle(): void
    {
        $data = (new MonthlyRecapService())->data(
            Main::captureGetString('month'),
            Main::captureGetString('viewer'),
        );
        $this->render('statistics/recap', array_merge($data, [
            'layout' => $this->layout([
                'title' => 'Monthly recap',
                'page' => 'statistics',
                'hide_footer' => true,
            ]),
        ]));
    }
}
