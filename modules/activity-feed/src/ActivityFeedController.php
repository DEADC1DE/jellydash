<?php

declare(strict_types=1);

namespace Mk\Modules\ActivityFeed;

use Mk\Framework\Controller;

final class ActivityFeedController extends Controller
{
    private const PAGE_SIZE = 50;

    public function handle(): void
    {
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $startIndex = ($page - 1) * self::PAGE_SIZE;

        $result = (new ActivityLogClient())->page($startIndex, self::PAGE_SIZE);
        $totalPages = max(1, (int) ceil($result['total'] / self::PAGE_SIZE));

        $this->render('@activity-feed/index', [
            'layout' => $this->layout(['title' => 'Activity', 'page' => 'activity']),
            'entries' => $result['items'],
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
