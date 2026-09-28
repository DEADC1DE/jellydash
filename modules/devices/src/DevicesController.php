<?php

declare(strict_types=1);

namespace Mk\Modules\Devices;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;

final class DevicesController extends Controller
{
    public function handle(): void
    {
        (new Authorization())->requireRole(Authorization::ROLE_ADMIN);

        $this->render('@devices/index', [
            'layout' => $this->layout([
                'title' => 'Devices',
                'page' => 'devices',
            ]),
            'devices' => (new DeviceService())->list(),
        ]);
    }
}
