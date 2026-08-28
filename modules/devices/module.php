<?php

declare(strict_types=1);

return [
    'name' => 'devices',
    'label' => 'Devices',
    'nav' => [
        'label' => 'Devices',
        'route' => 'devices',
        'order' => 30,
        'icon' => '<path d="M3 5m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"></path><path d="M7 20l10 0"></path><path d="M9 16l0 4"></path><path d="M15 16l0 4"></path>',
    ],
    'routes' => [
        'devices' => \Mk\Modules\Devices\DevicesController::class,
    ],
    'autoload' => [
        'Mk\\Modules\\Devices\\' => 'src/',
    ],
    'api' => 'api/devices.php',
];
