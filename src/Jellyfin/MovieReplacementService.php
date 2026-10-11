<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;

/** Validates an explicit replacement without changing recorded playback. */
final class MovieReplacementService
{
    private Database $database;
    private JellyfinClient $client;
    private MovieReplacements $replacements;

    public function __construct(
        ?Database $database = null,
        ?JellyfinClient $client = null,
        ?MovieReplacements $replacements = null,
    ) {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->replacements = $replacements ?? new MovieReplacements($this->database);
    }

    /** @return array{sourceId: string, targetId: string, sourceTitles: list<string>, sourcePlays: int, targetTitle: string, targetProviderIds: array<string, string>, existingTarget: ?string} */
    public function preview(string $oldId, string $currentId): array
    {
        $oldId = $this->validId($oldId);
        $currentId = $this->validId($currentId);
        if ($oldId === $currentId) {
            throw new \InvalidArgumentException('The old and current movie IDs must be different.');
        }

        // Schema initialization follows the same fresh-install contract as History.
        new PlayHistoryRepository($this->database);
        $sourceIds = $this->replacements->members($oldId);
        $selection = $this->database->getDibi()->select('item_id, item_type, item_name, COUNT(*) AS plays')
            ->from('play_history');
        $selection->where($this->database->getPlatform()->isSqlite()
            ? 'item_id COLLATE BINARY IN %in' : 'BINARY item_id IN %in', $sourceIds);
        $sourceRows = $selection->groupBy('item_id, item_type, item_name')->fetchAll();
        $titles = [];
        $plays = 0;
        foreach ($sourceRows as $row) {
            if ((string) $row['item_type'] !== 'Movie') {
                throw new \RuntimeException('The old movie group also has non-movie history. No replacement was saved.');
            }
            $plays += (int) $row['plays'];
            $title = trim((string) ($row['item_name'] ?? ''));
            if ($title !== '') {
                $titles[$title] = $title;
            }
        }
        if ($plays === 0) {
            throw new \RuntimeException('No recorded movie plays were found for the old ID.');
        }
        $target = $this->lookup($currentId);
        if ($target === null || ($target['Type'] ?? null) !== 'Movie') {
            throw new \RuntimeException('The current ID must identify an existing Jellyfin movie.');
        }
        $currentId = $this->validId((string) $target['Id']);
        $targetRows = $this->database->getDibi()->select('item_type')->from('play_history')
            ->where($this->database->getPlatform()->isSqlite()
                ? 'item_id COLLATE BINARY = %s' : 'BINARY item_id = %s', $currentId)->fetchAll();
        foreach ($targetRows as $row) {
            if ((string) $row['item_type'] !== 'Movie') {
                throw new \RuntimeException('The current ID also has non-movie history. No replacement was saved.');
            }
        }

        $existing = $this->replacements->resolve($oldId);
        if ($existing !== $oldId && $existing !== $currentId) {
            throw new \RuntimeException('The old ID already points to another movie. Unlink it before changing its replacement.');
        }
        if ($this->replacements->resolve($currentId) !== $currentId) {
            throw new \RuntimeException('The current ID is itself a replaced movie. Use its current replacement ID.');
        }

        if ($this->lookup($oldId) !== null) {
            throw new \RuntimeException('The old ID still exists in Jellyfin. Separate existing items cannot be linked.');
        }

        $providers = [];
        $rawProviders = $target['ProviderIds'] ?? [];
        if (!is_array($rawProviders)) {
            throw new \RuntimeException('Jellyfin returned invalid movie provider metadata.');
        }
        foreach ($rawProviders as $provider => $value) {
            if (is_string($provider) && (is_string($value) || is_int($value))) {
                $providers[$provider] = (string) $value;
            }
        }

        return [
            'sourceId' => $oldId,
            'targetId' => $currentId,
            'sourceTitles' => array_values($titles),
            'sourcePlays' => $plays,
            'targetTitle' => (string) ($target['Name'] ?? ''),
            'targetProviderIds' => $providers,
            'existingTarget' => $existing !== $oldId ? $existing : null,
        ];
    }

    /** @return array{sourceId: string, targetId: string, sourceTitles: list<string>, sourcePlays: int, targetTitle: string, targetProviderIds: array<string, string>, existingTarget: ?string} */
    public function apply(string $oldId, string $currentId, bool $confirmSameMovie = false): array
    {
        if (!$confirmSameMovie) {
            throw new \InvalidArgumentException('Saving requires --confirm-same-movie after checking the preview.');
        }
        $preview = $this->preview($oldId, $currentId);
        $this->replacements->link($preview['sourceId'], $preview['targetId']);

        return $preview;
    }

    /** @return array{sourceId: string, targetId: ?string, applied: bool} */
    public function unlink(string $oldId, bool $apply = false): array
    {
        $oldId = $this->validId($oldId);
        $target = $this->replacements->resolve($oldId);
        if ($apply && $target !== $oldId) {
            $this->replacements->unlink($oldId);
        }

        return ['sourceId' => $oldId, 'targetId' => $target !== $oldId ? $target : null, 'applied' => $apply];
    }

    private function validId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Movie IDs must contain 1 to 64 letters, digits, underscores or hyphens.');
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function lookup(string $id): ?array
    {
        try {
            $payload = $this->client->getJson('/Items?' . http_build_query(
                ['Ids' => $id, 'Fields' => 'ProviderIds', 'Limit' => 2],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ));
        } catch (\Throwable $error) {
            throw new \RuntimeException('Could not verify movie metadata with Jellyfin. No replacement was saved.', previous: $error);
        }
        if (!is_array($payload) || !isset($payload['Items']) || !is_array($payload['Items'])
            || !array_is_list($payload['Items']) || count($payload['Items']) > 1) {
            throw new \RuntimeException('Jellyfin returned invalid or ambiguous movie metadata. No replacement was saved.');
        }
        if (isset($payload['TotalRecordCount'])
            && (!is_int($payload['TotalRecordCount']) || $payload['TotalRecordCount'] !== count($payload['Items']))) {
            throw new \RuntimeException('Jellyfin returned inconsistent movie metadata. No replacement was saved.');
        }
        if ($payload['Items'] === []) {
            return null;
        }
        $item = $payload['Items'][0];
        if (!is_array($item) || !is_string($item['Id'] ?? null) || !is_string($item['Type'] ?? null)
            || ThemePlaybackExclusions::canonicalItemId($item['Id']) !== ThemePlaybackExclusions::canonicalItemId($id)
            || (isset($item['Name']) && !is_string($item['Name']))) {
            throw new \RuntimeException('Jellyfin returned unexpected movie metadata. No replacement was saved.');
        }

        return $item;
    }
}
