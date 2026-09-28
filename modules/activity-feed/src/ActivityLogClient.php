<?php

declare(strict_types=1);

namespace Mk\Modules\ActivityFeed;

use Mk\Framework\Jellyfin\JellyfinClient;

final class ActivityLogClient
{
    /** Jellyfin has no server-side text search for the activity log, so text
     *  filtering walks the log newest-first in batches up to this window. */
    public const SEARCH_WINDOW = 3000;
    private const FETCH_BATCH = 250;

    public function __construct(private readonly object $client = new JellyfinClient())
    {
    }

    /**
     * Native Jellyfin paging — cheap and total-count aware. Used when no
     * search text narrows the view.
     *
     * @return array{items: array<int, array{date: string, name: string, userId: ?string}>, total: int}
     */
    public function page(int $startIndex, int $limit, ?\DateTimeImmutable $minDate = null): array
    {
        $payload = $this->fetch($startIndex, $limit, $minDate);

        $items = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];
        $total = is_array($payload) ? (int) ($payload['TotalRecordCount'] ?? 0) : 0;

        $mapped = [];
        foreach ($items as $entry) {
            if (is_array($entry)) {
                $mapped[] = $this->mapEntry($entry);
            }
        }

        return ['items' => $mapped, 'total' => $total];
    }

    /**
     * Text search over a bounded window of the log (newest first). Jellyfin
     * cannot filter by content server-side, so batches are walked until the
     * window cap is reached; the caller gets a truncated flag for that case.
     *
     * @return array{items: array<int, array{date: string, name: string, userId: ?string}>, total: int, truncated: bool}
     */
    public function searchPage(string $query, ?\DateTimeImmutable $minDate, int $offset, int $limit): array
    {
        $needle = mb_strtolower(trim($query));
        $matched = [];
        $fetched = 0;
        $truncated = false;

        while ($fetched < self::SEARCH_WINDOW) {
            $batchSize = min(self::FETCH_BATCH, self::SEARCH_WINDOW - $fetched);
            $entries = $this->entries($this->fetch($fetched, $batchSize, $minDate));
            if ($entries === []) {
                break;
            }

            foreach ($entries as $entry) {
                if ($needle === '' || mb_stripos($entry['name'], $needle) !== false) {
                    $matched[] = $entry;
                }
            }

            $fetched += count($entries);
            if (count($entries) < $batchSize) {
                break;
            }
            if ($fetched >= self::SEARCH_WINDOW) {
                $truncated = true;
            }
        }

        return [
            'items' => array_slice($matched, $offset, $limit),
            'total' => count($matched),
            'truncated' => $truncated,
        ];
    }

    private function fetch(int $startIndex, int $limit, ?\DateTimeImmutable $minDate): mixed
    {
        $path = '/System/ActivityLog/Entries?startIndex=' . $startIndex . '&limit=' . $limit;

        if ($minDate !== null) {
            $path .= '&minDate=' . rawurlencode($minDate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'));
        }

        return $this->client->getJson($path);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{date: string, name: string, userId: ?string}
     */
    private function mapEntry(array $entry): array
    {
        $userId = $entry['UserId'] ?? null;

        return [
            'date' => (string) ($entry['Date'] ?? ''),
            'name' => (string) ($entry['Name'] ?? ''),
            'userId' => is_string($userId) && $userId !== '' ? $userId : null,
        ];
    }

    /**
     * @param mixed $payload
     * @return array<int, array{date: string, name: string, userId: ?string}>
     */
    private function entries(mixed $payload): array
    {
        $items = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];

        $mapped = [];
        foreach ($items as $entry) {
            if (is_array($entry)) {
                $mapped[] = $this->mapEntry($entry);
            }
        }

        return $mapped;
    }
}
