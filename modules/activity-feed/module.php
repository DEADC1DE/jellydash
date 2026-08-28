<?php

declare(strict_types=1);

return [
    'name' => 'activity-feed',
    'label' => 'Activity',
    'nav' => [
        'label' => 'Activity',
        'route' => 'activity',
        'order' => 45,
        'icon' => '<path d="M3 12h4l3 8l4 -16l3 8h4"></path><circle cx="12" cy="12" r="9"></circle>',
    ],
    'routes' => [
        'activity' => \Mk\Modules\ActivityFeed\ActivityFeedController::class,
    ],
    'autoload' => [
        'Mk\\Modules\\ActivityFeed\\' => 'src/',
    ],
    'api' => 'api/activity.php',
    'styles' => ['activity-feed.css'],
];
