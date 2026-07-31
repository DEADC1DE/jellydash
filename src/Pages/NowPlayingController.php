<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;

final class NowPlayingController extends Controller
{
    public function handle(): void
    {
        $this->render('now_playing/index', [
            'layout' => $this->layout([
                'title' => 'Now Playing',
                'page' => 'now-playing',
            ]),
            'is_loading' => true,
            'streams' => [],
            'hidden_count' => 0,
            'hidden_sources' => '',
            'stats' => [
                'watch_today' => '0m',
                'active_streams' => 0,
                'active_users' => 0,
                'bandwidth_mbps' => '0.0',
                'transcodes' => 0,
            ],
        ]);
    }
}
