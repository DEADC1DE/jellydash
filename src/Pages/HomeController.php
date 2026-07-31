<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;

final class HomeController extends Controller
{
    public function handle(): void
    {
        (new NowPlayingController($this->view))->handle();
    }
}
