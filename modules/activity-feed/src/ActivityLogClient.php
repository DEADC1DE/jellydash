<?php

declare(strict_types=1);

namespace Mk\Modules\ActivityFeed;

use Mk\Framework\Jellyfin\JellyfinClient;

final class ActivityLogClient
{
    public function __construct(private readonly object $client = new JellyfinClient())
    {
    }

    /**
     * @return array{items: array<int, array{date: string, name: string, userId: ?string}>, total: int}
     */
    public function page(int $startIndex, int $limit): array
    {
        $payload = $this->client->getJson(
            '/System/ActivityLog/Entries?startIndex=' . $startIndex . '&limit=' . $limit
        );

        $items = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];
        $total = is_array($payload) ? (int) ($payload['TotalRecordCount'] ?? 0) : 0;

        $mapped = [];
        foreach ($items as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $userId = $entry['UserId'] ?? null;
            $mapped[] = [
                'date' => (string) ($entry['Date'] ?? ''),
                'name' => (string) ($entry['Name'] ?? ''),
                'userId' => is_string($userId) && $userId !== '' ? $userId : null,
            ];
        }

        return ['items' => $mapped, 'total' => $total];
    }
}
