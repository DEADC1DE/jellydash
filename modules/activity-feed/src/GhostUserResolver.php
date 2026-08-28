<?php

declare(strict_types=1);

namespace Mk\Modules\ActivityFeed;

use Mk\Framework\Container;
use Mk\Framework\Database;

final class GhostUserResolver
{
    private const MAX_PAGES = 50; // 50 * 200 = 10k activity log rows scanned per run, bounded on purpose
    private const PAGE_SIZE = 200;

    private \Dibi\Connection $dibi;

    // Typed `object`, not `ActivityLogClient`, because ActivityLogClient is
    // final and tests inject a plain anonymous-class fixture in its place
    // (same reasoning as ActivityLogClient's own `object $client` parameter).
    public function __construct(
        private readonly object $logClient = new ActivityLogClient(),
        ?Database $database = null,
    ) {
        $this->dibi = ($database ?? Container::db())->getDibi();
    }

    /**
     * @return array<string, string> user_id (dashed) => resolved name
     */
    public function resolve(): array
    {
        $ghostIds = $this->dibi->select('DISTINCT user_id')
            ->from('play_history')
            ->where('user_name IS NULL OR user_name = %s', '')
            ->where('user_id IS NOT NULL AND user_id != %s', '')
            ->fetchPairs('user_id', 'user_id');

        if ($ghostIds === []) {
            return [];
        }

        // Jellyfin's ActivityLog UserId sometimes has no dashes; normalize both
        // sides the same way the manual debugging session found necessary.
        $undashed = [];
        foreach ($ghostIds as $id) {
            $undashed[str_replace('-', '', (string) $id)] = (string) $id;
        }

        $resolved = [];
        for ($startIndex = 0; $startIndex < self::MAX_PAGES * self::PAGE_SIZE; $startIndex += self::PAGE_SIZE) {
            if (count($resolved) === count($undashed)) {
                break; // found every ghost id already
            }

            $page = $this->logClient->page($startIndex, self::PAGE_SIZE);
            if ($page['items'] === []) {
                break;
            }

            foreach ($page['items'] as $entry) {
                $entryUserId = $entry['userId'] !== null ? str_replace('-', '', $entry['userId']) : null;
                if ($entryUserId === null || !isset($undashed[$entryUserId]) || isset($resolved[$undashed[$entryUserId]])) {
                    continue;
                }

                $name = $this->extractName($entry['name']);
                if ($name !== null) {
                    $resolved[$undashed[$entryUserId]] = $name;
                }
            }
        }

        foreach ($resolved as $userId => $name) {
            $this->dibi->update('play_history', ['user_name' => $name])
                ->where('user_id = %s', $userId)
                ->where('user_name IS NULL OR user_name = %s', '')
                ->execute();
        }

        return $resolved;
    }

    private function extractName(string $activityLogLine): ?string
    {
        $space = strpos($activityLogLine, ' ');
        if ($space === false) {
            return null;
        }

        $name = trim(substr($activityLogLine, 0, $space));
        return $name !== '' ? $name : null;
    }
}
