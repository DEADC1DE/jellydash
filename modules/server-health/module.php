<?php

declare(strict_types=1);

return [
    'name' => 'server-health',
    'label' => 'Server Health',
    'nav' => [
        'label' => 'Server Health',
        'route' => 'server-health',
        'order' => 40,
        'icon' => '<path d="M3 12h4l3 8l4 -16l3 8h4"></path>',
    ],
    'routes' => [
        'server-health' => \Mk\Modules\ServerHealth\ServerHealthController::class,
    ],
    'autoload' => [
        'Mk\\Modules\\ServerHealth\\' => 'src/',
    ],
    'api' => 'api/server-health.php',
    'styles' => ['server-health.css'],
];
