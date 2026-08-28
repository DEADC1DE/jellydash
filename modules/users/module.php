<?php

declare(strict_types=1);

return [
    'name' => 'users',
    'label' => 'Users',
    'nav' => [
        'label' => 'Users',
        'route' => 'users',
        'order' => 25,
        'icon' => '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"></path><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"></path>',
    ],
    'routes' => [
        'users' => \Mk\Modules\Users\UsersController::class,
    ],
    'autoload' => [
        'Mk\\Modules\\Users\\' => 'src/',
    ],
    'styles' => ['users.css'],
];
