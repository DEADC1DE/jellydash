<?php

declare(strict_types=1);

return [
    'name' => 'session-control',
    'label' => 'Session Control',
    'nav' => [
        'label' => 'Stream Rules',
        'route' => 'session-rules',
        'order' => 46,
        'icon' => '<path d="M4 4l9 11"></path><path d="M13 4l-9 11"></path><circle cx="12" cy="12" r="9"></circle>',
    ],
    'routes' => [
        'session-rules' => \Mk\Modules\SessionControl\StreamRulesController::class,
    ],
    'autoload' => [
        'Mk\\Modules\\SessionControl\\' => 'src/',
    ],
    'api' => 'api/sessions.php',
    'styles' => ['sessions.css'],
    'scripts' => ['sessions.js'],
];
