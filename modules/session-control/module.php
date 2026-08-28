<?php

declare(strict_types=1);

return [
    'name' => 'session-control',
    'label' => 'Session Control',
    'autoload' => [
        'Mk\\Modules\\SessionControl\\' => 'src/',
    ],
    'api' => 'api/sessions.php',
    'styles' => ['sessions.css'],
    'scripts' => ['sessions.js'],
];
