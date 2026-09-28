<?php

declare(strict_types=1);

namespace Mk\Modules\ActivityFeed;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;

final class ActivityFeedController extends Controller
{
    private const PAGE_SIZE = 50;

    private const RANGES = [
        '' => 'All time',
        '1d' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    public function handle(): void
    {
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $startIndex = ($page - 1) * self::PAGE_SIZE;

        $query = trim((string) ($_GET['q'] ?? ''));
        $range = (string) ($_GET['range'] ?? '');
        if (!isset(self::RANGES[$range])) {
            $range = '';
        }
        $minDate = $this->minDate($range);

        $client = new ActivityLogClient();
        if ($query !== '') {
            $result = $client->searchPage($query, $minDate, $startIndex, self::PAGE_SIZE);
            $total = $result['total'];
            $truncated = $result['truncated'];
        } else {
            $result = $client->page($startIndex, self::PAGE_SIZE, $minDate);
            $total = $result['total'];
            $truncated = false;
        }
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        $filterQuery = http_build_query(array_filter([
            'q' => $query,
            'range' => $range,
        ], static fn (string $value): bool => $value !== ''));

        $this->render('@activity-feed/index', [
            'layout' => $this->layout(['title' => 'Activity', 'page' => 'activity']),
            'entries' => $result['items'],
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'query' => $query,
            'range' => $range,
            'ranges' => self::RANGES,
            'filterQuery' => $filterQuery,
            'truncated' => $truncated,
            'searchWindow' => ActivityLogClient::SEARCH_WINDOW,
            'isAdmin' => (new Authorization())->hasRole(Authorization::ROLE_ADMIN),
        ]);
    }

    private function minDate(string $range): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now');

        return match ($range) {
            '1d' => $now->modify('-1 day'),
            '7d' => $now->modify('-7 days'),
            '30d' => $now->modify('-30 days'),
            default => null,
        };
    }
}
