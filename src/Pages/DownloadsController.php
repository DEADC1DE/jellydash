<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Downloads\Feature;

final class DownloadsController extends Controller
{
    public function handle(): void
    {
        if (!Feature::enabled()) {
            http_response_code(404);
            $this->render('_404');
            return;
        }

        $this->render('downloads/index', [
            'layout' => $this->layout(['title' => 'Downloads', 'page' => 'downloads']),
            'downloads_error' => null,
        ]);
    }
}
