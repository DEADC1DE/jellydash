<?php

declare(strict_types=1);

namespace Mk\Modules\ServerHealth;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;

final class ServerHealthController extends Controller
{
    public function handle(): void
    {
        (new Authorization())->requireRole(Authorization::ROLE_ADMIN);

        $service = new ServerHealthService();

        $this->render('@server-health/index', [
            'layout' => $this->layout([
                'title' => 'Server Health',
                'page' => 'server-health',
            ]),
            'info' => $service->systemInfo(),
            'tasks' => $service->tasks(),
        ]);
    }
}
