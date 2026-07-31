<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
final class LibrariesController extends Controller
{
    public function handle(): void
    {
        $this->render('libraries/index', [
            'layout' => $this->layout([
                'title' => 'Libraries',
                'page' => 'libraries',
                'hide_footer' => true,
            ]),
            'refreshedLabel' => 'Loading library stats',
        ]);
    }
}
