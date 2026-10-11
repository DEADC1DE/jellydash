<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;

/** Keeps movie identity evidence and repairs verified replacements in bounded batches. */
final class MovieRecoveryService
{
    private const PAGE_SIZE = 100;
    private const RECOVERY_BATCH = 100;
    private const RESCAN_SECONDS = 300;
    private const DISPLAY_PAGE_SIZE = 10;
    private Database $database;
    private JellyfinClient $client;
    private MovieIdentityRepository $identities;
    private MovieReplacements $replacements;
    private MovieIdentityMatcher $matcher;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        ?Database $database = null,
        ?JellyfinClient $client = null,
        ?MovieIdentityRepository $identities = null,
        ?MovieReplacements $replacements = null,
        ?\Closure $clock = null,
    ) {
        $this->database = $database ?? Container::db();
        $this->client = $client ?? new JellyfinClient();
        $this->identities = $identities ?? new MovieIdentityRepository($this->database);
        $this->replacements = $replacements ?? new MovieReplacements($this->database, $this->identities->serverKey());
        $this->matcher = new MovieIdentityMatcher();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string, mixed> */
    public function runBatch(): array
    {
        $now = ($this->clock)();
        $lease = $this->identities->acquireLease($now);
        if ($lease === null) {
            return ['state' => 'busy', 'processed' => 0];
        }
        $state = null;
        try {
            $state = $this->currentState();
            if ($state['blocked']) {
                return ['state' => 'blocked', 'processed' => 0];
            }
            if ($now < (int) $state['retryAfter'] || ($state['phase'] === 'idle' && $now < (int) $state['nextScanAt'])) {
                return ['state' => (string) $state['state'], 'processed' => 0];
            }
            $this->verifyServer($state);
            if ($state['phase'] === 'idle') {
                $state['phase'] = 'inventory';
                $state['offset'] = 0;
                $state['total'] = null;
                $state['lastId'] = '';
                $state['scan'] = (int) $state['scan'] + 1;
                $state['inventoryCompletedAt'] = 0;
                $state['seenIds'] = [];
                $state['scanEvidence'] = [];
            }
            if ($state['phase'] === 'inventory') {
                $processed = $this->inventoryPage($state, $now);
            } else {
                $processed = $this->recoverBatch($state, $now);
            }
            $state['lastChecked'] = $now;
            $state['retryAfter'] = 0;
            $state['state'] = $state['phase'] === 'idle' ? 'ready' : 'checking';
            $state['message'] = $state['phase'] === 'idle' ? 'Movie recovery is up to date.' : 'Checking movie metadata.';
            $this->identities->saveState($state);

            return ['state' => $state['state'], 'processed' => $processed];
        } catch (\Throwable $error) {
            if ($state === null) {
                throw $error;
            }
            if (!$state['blocked']) {
                $state['state'] = 'unavailable';
                $state['message'] = 'Movie metadata is unavailable. Recovery will retry.';
                $state['retryAfter'] = $now + 60;
                // A failed inventory never becomes evidence of absence or uniqueness.
                if ($state['phase'] === 'inventory') {
                    $state['offset'] = 0;
                    $state['total'] = null;
                    $state['lastId'] = '';
                    $state['scan'] = (int) $state['scan'] + 1;
                    $state['inventoryCompletedAt'] = 0;
                    $state['seenIds'] = [];
                    $state['scanEvidence'] = [];
                }
            }
            $this->identities->saveState($state);

            return ['state' => $state['state'], 'processed' => 0];
        } finally {
            $this->identities->releaseLease($lease);
        }
    }

    /** @param array<int, array<string, mixed>> $sessions */
    public function captureSessions(array $sessions): void
    {
        $lease = $this->identities->acquireLease(($this->clock)());
        if ($lease === null) {
            return;
        }
        try {
            $state = $this->currentState();
            if ($state['blocked']) {
                return;
            }
            foreach ($sessions as $session) {
                $item = $session['NowPlayingItem'] ?? null;
                if (!is_array($item) || ($item['Type'] ?? null) !== 'Movie') {
                    continue;
                }
                $serverId = $item['ServerId'] ?? $session['ServerId'] ?? '';
                if (!is_string($serverId) || $serverId === ''
                    || ($state['serverId'] !== '' && $serverId !== $state['serverId'])) {
                    continue;
                }
                $id = $this->itemId($item['Id'] ?? null);
                $this->remember($id, $this->identity($item, $serverId, $state));
            }
        } finally {
            $this->identities->releaseLease($lease);
        }
    }

    /** @return array<string, mixed> */
    public function status(int $page = 1): array
    {
        $state = $this->currentState();
        $items = $this->identities->items();
        $suggestions = [];
        $repairs = [];
        $dismissed = [];
        $aliases = $this->replacements->all();
        foreach ($items as $id => $item) {
            $id = (string) $id;
            if (!empty($item['suppressed'])) {
                $dismissed[] = ['sourceId' => $id, 'sourceTitle' => (string) ($item['title'] ?? '')];
            }
            if (!empty($item['candidateIds']) && empty($item['suppressed']) && !isset($aliases[$id])) {
                $candidates = [];
                foreach ($item['candidateIds'] as $candidateId) {
                    $candidate = $items[$candidateId] ?? null;
                    if ($candidate !== null) {
                        $candidates[] = ['id' => (string) $candidateId, 'title' => (string) ($candidate['title'] ?? ''),
                            'year' => $candidate['year'] ?? null];
                    }
                }
                if ($candidates !== []) {
                    $suggestions[] = ['sourceId' => $id, 'sourceTitle' => (string) ($item['title'] ?? ''),
                        'plays' => (int) ($item['sourcePlays'] ?? 0), 'candidates' => $candidates];
                }
            }
            if (isset($aliases[$id])) {
                $journalIsCurrent = true;
                foreach (($item['repairAfter'] ?? []) as $affectedId => $expectedTarget) {
                    if (($aliases[$affectedId] ?? null) !== $expectedTarget) {
                        $journalIsCurrent = false;
                        break;
                    }
                }
                if (!$journalIsCurrent) {
                    continue;
                }
                $target = $items[$aliases[$id]] ?? [];
                $repairs[] = ['sourceId' => $id, 'sourceTitle' => (string) ($item['title'] ?? ''),
                    'targetId' => $aliases[$id], 'targetTitle' => (string) ($target['title'] ?? ''),
                    'automatic' => (bool) ($item['repairAutomatic'] ?? false)];
            }
        }
        foreach ($aliases as $id => $targetId) {
            if (isset($items[$id])) {
                continue;
            }
            $evidence = $this->historyEvidence([(string) $id]);
            $target = $items[$targetId] ?? [];
            $repairs[] = ['sourceId' => (string) $id, 'sourceTitle' => $evidence['title'], 'targetId' => $targetId,
                'targetTitle' => (string) ($target['title'] ?? ''), 'automatic' => false];
        }
        $pages = max(1, (int) ceil(max(count($suggestions), count($repairs), count($dismissed)) / self::DISPLAY_PAGE_SIZE));
        $page = max(1, min($pages, $page));

        return ['state' => $state['state'], 'lastChecked' => $state['lastChecked'], 'message' => $state['message'],
            'suggestions' => array_slice($suggestions, ($page - 1) * self::DISPLAY_PAGE_SIZE, self::DISPLAY_PAGE_SIZE),
            'suggestionCount' => count($suggestions), 'page' => $page, 'pages' => $pages,
            'repairs' => array_slice($repairs, ($page - 1) * self::DISPLAY_PAGE_SIZE, self::DISPLAY_PAGE_SIZE),
            'repairCount' => count($repairs), 'dismissedCount' => count($dismissed),
            'dismissed' => array_slice($dismissed, ($page - 1) * self::DISPLAY_PAGE_SIZE, self::DISPLAY_PAGE_SIZE)];
    }

    public function confirm(string $sourceId, string $targetId): void
    {
        $this->uiMutation(function (array &$state) use ($sourceId, $targetId): void {
            $source = $this->identities->get($sourceId);
            if ($source === null || !empty($source['suppressed']) || !in_array($targetId, $source['candidateIds'] ?? [], true)) {
                throw new \RuntimeException('This replacement is no longer offered. Refresh the recovery list.');
            }
            $this->verifyServer($state);
            $this->requireFreshInventory($state, ($this->clock)());
            if ($this->lookup($sourceId) !== null) {
                throw new \RuntimeException('The old movie still exists. It cannot be replaced.');
            }
            $target = $this->lookup($targetId);
            if ($target === null || ($target['Type'] ?? '') !== 'Movie' || $this->itemId($target['Id'] ?? null) !== $targetId) {
                throw new \RuntimeException('The selected movie is no longer available. Refresh the recovery list.');
            }
            $targetIdentity = $this->identity($target, (string) $state['serverId'], $state);
            if ($this->matcher->match($source, $targetIdentity) === null || $this->hasNonMovieHistory($targetId)
                || $this->incomingConflicts($sourceId, $targetIdentity)) {
                throw new \RuntimeException('The movie metadata no longer matches this suggestion.');
            }
            $this->remember($targetId, $targetIdentity, true);
            $this->link($sourceId, $targetId, false);
        });
    }

    public function dismiss(string $sourceId): void
    {
        $this->uiMutation(function (array &$state) use ($sourceId): void {
            $item = $this->identities->get($sourceId);
            if ($item === null) {
                throw new \RuntimeException('This movie recovery entry is no longer available.');
            }
            $this->replacements->atomic(function () use ($sourceId, $item): void {
                $this->identities->assertLease(($this->clock)());
                $item['suppressed'] = true;
                $item['candidateIds'] = [];
                $this->identities->put($sourceId, $item);
            });
        });
    }

    public function undo(string $sourceId): void
    {
        $this->uiMutation(function (array &$state) use ($sourceId): void {
            $item = $this->identities->get($sourceId) ?? ['title' => '', 'providers' => [], 'serverId' => $state['serverId']];
            $this->replacements->atomic(function () use ($sourceId, $item): void {
                $this->identities->assertLease(($this->clock)());
                $current = $this->replacements->all();
                $after = $item['repairAfter'] ?? [$sourceId => $current[$sourceId] ?? null];
                $before = $item['repairBefore'] ?? [$sourceId => null];
                foreach ($after as $id => $target) {
                    if (($current[$id] ?? null) !== $target) {
                        throw new \RuntimeException('A later repair changed this movie group. Refresh the recovery list.');
                    }
                }
                foreach ($after as $id => $target) {
                    $this->replacements->unlink((string) $id);
                }
                foreach ($before as $id => $target) {
                    if ($target !== null) {
                        $this->replacements->link((string) $id, $target);
                    }
                }
                foreach (array_keys($after) as $id) {
                    $id = (string) $id;
                    $affected = $this->identities->get($id) ?? [];
                    $affected['suppressed'] = true;
                    $affected['candidateIds'] = [];
                    $affected['repairAutomatic'] = false;
                    unset($affected['repairAfter'], $affected['repairBefore']);
                    $this->identities->put($id, $affected);
                }
            });
        });
    }

    public function resume(string $sourceId): void
    {
        $this->uiMutation(function (array &$state) use ($sourceId): void {
            $item = $this->identities->get($sourceId);
            if ($item === null || empty($item['suppressed'])) {
                throw new \RuntimeException('This movie is not in the kept-separate list.');
            }
            $this->replacements->atomic(function () use ($sourceId, &$state): void {
                $this->identities->assertLease(($this->clock)());
                foreach ($this->replacements->members($sourceId) as $memberId) {
                    $member = $this->identities->get($memberId) ?? [];
                    $member['suppressed'] = false;
                    $member['candidateIds'] = [];
                    $this->identities->put($memberId, $member);
                }
                $state['nextScanAt'] = 0;
                $state['retryAfter'] = 0;
                $this->identities->saveState($state);
            });
        });
    }

    /** @param array<string, mixed> $state */
    private function inventoryPage(array &$state, int $now): int
    {
        if ((int) $state['offset'] === 0) {
            $folders = $this->client->getJson('/Library/VirtualFolders', 4);
            if (!is_array($folders) || !array_is_list($folders)) {
                throw new \RuntimeException('Invalid library metadata.');
            }
            $roots = [];
            foreach ($folders as $folder) {
                if (!is_array($folder) || !is_string($folder['Name'] ?? null) || !is_array($folder['Locations'] ?? null)) {
                    throw new \RuntimeException('Invalid library metadata.');
                }
                foreach ($folder['Locations'] as $location) {
                    if (!is_string($location)) {
                        throw new \RuntimeException('Invalid library path.');
                    }
                    $roots[] = ['path' => rtrim(str_replace('\\', '/', $location), '/'), 'name' => $folder['Name']];
                }
            }
            $state['roots'] = $roots;
        }
        $payload = $this->client->getJson('/Items?' . http_build_query([
            'Recursive' => 'true', 'IncludeItemTypes' => 'Movie', 'SortBy' => 'SortName', 'SortOrder' => 'Ascending',
            'StartIndex' => $state['offset'], 'Limit' => self::PAGE_SIZE,
            'Fields' => 'ProviderIds,OriginalTitle,Path,DateLastSaved', 'EnableTotalRecordCount' => 'true',
        ], '', '&', PHP_QUERY_RFC3986), 6);
        $page = $this->validatedItems($payload);
        $total = $payload['TotalRecordCount'] ?? null;
        if (!is_int($total) || $total < 0 || ($state['total'] !== null && $state['total'] !== $total)
            || count($page) > self::PAGE_SIZE || (int) $state['offset'] + count($page) > $total
            || (count($page) < self::PAGE_SIZE && (int) $state['offset'] + count($page) !== $total)) {
            throw new \RuntimeException('Incomplete movie inventory.');
        }
        $state['total'] = $total;
        foreach ($page as $item) {
            $id = $this->itemId($item['Id']);
            if ($item['Type'] !== 'Movie' || isset($state['seenIds'][$id])) {
                throw new \RuntimeException('Unordered or duplicate movie inventory.');
            }
            $identity = $this->identity($item, (string) $state['serverId'], $state);
            $this->remember($id, $identity
                + ['seenScan' => $state['scan'], 'absent' => false], true);
            $state['seenIds'][$id] = true;
            $state['scanEvidence'][$id] = $identity;
        }
        $state['offset'] = (int) $state['offset'] + count($page);
        if ($state['offset'] === $total) {
            $state['inventoryCompletedAt'] = $now;
            ksort($state['scanEvidence']);
            $fingerprint = hash('sha256', json_encode($state['scanEvidence'], JSON_THROW_ON_ERROR));
            $state['inventoryStable'] = $state['previousFingerprint'] === $fingerprint;
            $state['previousFingerprint'] = $fingerprint;
            $state['phase'] = 'recover';
            $resumeId = $state['recoveryIds'][(int) $state['recoveryOffset']] ?? null;
            $state['recoveryIds'] = $this->recordedMovieIds();
            $resumeOffset = $resumeId !== null ? array_search($resumeId, $state['recoveryIds'], true) : false;
            $state['recoveryOffset'] = $resumeOffset !== false ? $resumeOffset : 0;
        }

        return count($page);
    }

    /** @param array<string, mixed> $state */
    private function recoverBatch(array &$state, int $now): int
    {
        if ($now - (int) $state['inventoryCompletedAt'] >= self::RESCAN_SECONDS) {
            $state['phase'] = 'idle';
            $state['nextScanAt'] = $now;
            return 0;
        }
        $processed = 0;
        $ids = $state['recoveryIds'];
        $aliases = $this->replacements->all();
        foreach (array_slice($ids, (int) $state['recoveryOffset'], self::RECOVERY_BATCH) as $id) {
            $observed = $this->identities->get($id);
            $missing = ($observed['seenScan'] ?? null) !== $state['scan'];
            $this->recover($id, $state, $aliases);
            ++$processed;
            ++$state['recoveryOffset'];
            if ($missing) {
                break;
            }
        }
        if ((int) $state['recoveryOffset'] >= count($ids)) {
            $state['phase'] = 'idle';
            $state['nextScanAt'] = $now + ($state['inventoryStable'] ? self::RESCAN_SECONDS : 0);
        }

        return $processed;
    }

    /** @param array<string, mixed> $state @param array<string, string> $aliases */
    private function recover(string $id, array $state, array $aliases): void
    {
        if (isset($aliases[$id])) {
            return;
        }
        $members = [$id];
        foreach ($aliases as $memberId => $targetId) {
            if ($targetId === $id) {
                $members[] = (string) $memberId;
            }
        }
        $history = $this->historyEvidence($members);
        if ($history['plays'] === 0 || $history['conflict']) {
            return;
        }
        $source = $this->identities->get($id) ?? ['title' => $history['title'], 'providers' => [],
            'year' => null, 'library' => $history['library'], 'serverId' => $state['serverId']];
        if (empty($source['title'])) {
            $source['title'] = $history['title'];
        }
        if ($history['library'] !== '') {
            if (!empty($source['library']) && $source['library'] !== $history['library']) {
                $source['conflicted'] = true;
            }
            $source['library'] = $history['library'];
        }
        $source['sourcePlays'] = $history['plays'];
        $incomingEvidence = [];
        foreach ($members as $memberId) {
            $member = $this->identities->get($memberId);
            if (!empty($member['suppressed'])) {
                return;
            }
            if ($member !== null) {
                $incomingEvidence[] = $member;
                if (!empty($member['conflicted'])) {
                    $source['conflicted'] = true;
                }
                foreach (['serverId', 'year', 'library'] as $field) {
                    if (($source[$field] ?? null) !== null && ($source[$field] ?? '') !== ''
                        && ($member[$field] ?? null) !== null && ($member[$field] ?? '') !== ''
                        && $source[$field] !== $member[$field]) {
                        $source['conflicted'] = true;
                    }
                }
                foreach (($member['providers'] ?? []) as $provider => $value) {
                    if (isset($source['providers'][$provider]) && $source['providers'][$provider] !== $value) {
                        $source['conflicted'] = true;
                    }
                }
            }
        }
        if (!empty($source['suppressed'])) {
            return;
        }
        $live = ($source['seenScan'] ?? null) === $state['scan'] ? ['Type' => 'Movie'] : $this->lookup($id);
        if ($live !== null) {
            if ($live['Type'] === 'Movie') {
                if (isset($live['Id'])) {
                    $this->remember($id, $this->identity($live, (string) $state['serverId'], $state)
                        + ['sourcePlays' => $history['plays'], 'candidateIds' => [], 'absent' => false], true);
                } else {
                    $source['candidateIds'] = [];
                    $source['absent'] = false;
                    $this->identities->put($id, $source);
                }
            }
            return;
        }
        $source['absent'] = true;
        $source['candidateIds'] = [];
        $candidates = [];
        $matchingLiveItems = 0;
        foreach ($this->identities->items() as $targetId => $target) {
            $targetId = (string) $targetId;
            if ($targetId === $id || ($target['seenScan'] ?? null) !== $state['scan']
                || isset($aliases[$targetId])) {
                continue;
            }
            $match = $this->matcher->match($source, $target);
            if ($match !== null && $this->evidenceConflicts($incomingEvidence, $target)) {
                $match = null;
            }
            if ($match !== null) {
                ++$matchingLiveItems;
                if (!$this->hasNonMovieHistory($targetId)) {
                    $candidates[$targetId] = $match;
                }
            }
        }
        $source['candidateIds'] = array_map('strval', array_keys($candidates));
        $this->identities->put($id, $source);
        if ($state['inventoryStable'] && $matchingLiveItems === 1 && count($candidates) === 1
            && array_values($candidates)[0] === 'automatic') {
            $targetId = (string) array_key_first($candidates);
            $target = $this->lookup($targetId);
            if ($target === null || $target['Type'] !== 'Movie' || $this->itemId($target['Id']) !== $targetId) {
                return;
            }
            $previous = $this->identities->get($targetId) ?? [];
            $current = $this->identity($target, (string) $state['serverId'], $state);
            if (($previous['providers'] ?? []) !== $current['providers'] || ($previous['year'] ?? null) !== $current['year']
                || ($previous['library'] ?? '') !== $current['library'] || $this->matcher->match($source, $current) !== 'automatic'
                || $this->evidenceConflicts($incomingEvidence, $current)) {
                return;
            }
            $this->link($id, $targetId, true);
        }
    }

    private function link(string $sourceId, string $targetId, bool $automatic): void
    {
        $this->replacements->atomic(function () use ($sourceId, $targetId, $automatic): void {
            $this->identities->assertLease(($this->clock)());
            $source = $this->identities->get($sourceId) ?? [];
            $before = $this->replacements->all();
            $this->replacements->link($sourceId, $targetId);
            $after = $this->replacements->all();
            $changedBefore = [];
            $changedAfter = [];
            foreach ($after as $id => $target) {
                if (($before[$id] ?? null) !== $target) {
                    $changedBefore[$id] = $before[$id] ?? null;
                    $changedAfter[$id] = $target;
                }
            }
            $source['candidateIds'] = [];
            $source['repairAutomatic'] = $automatic;
            $source['repairBefore'] = $changedBefore;
            $source['repairAfter'] = $changedAfter;
            $this->identities->put($sourceId, $source);
        });
    }

    /** @param array<string, mixed> $state */
    private function verifyServer(array &$state): void
    {
        if ($state['blocked']) {
            throw new \RuntimeException('The configured Jellyfin server has changed. Movie recovery is blocked.');
        }
        $info = $this->client->getJson('/System/Info/Public', 4);
        if (!is_array($info) || !is_string($info['Id'] ?? null) || $info['Id'] === '') {
            throw new \RuntimeException('Could not verify the Jellyfin server identity.');
        }
        $mismatch = $state['serverId'] !== '' && $state['serverId'] !== $info['Id'];
        if ($state['serverId'] === '') {
            foreach ($this->identities->items() as $item) {
                if (($item['serverId'] ?? '') !== '' && $item['serverId'] !== $info['Id']) {
                    $mismatch = true;
                    break;
                }
            }
        }
        if ($mismatch) {
            $state['blocked'] = true;
            $state['serverMismatch'] = true;
            $state['state'] = 'blocked';
            $state['message'] = 'The configured Jellyfin server has changed. Movie recovery is paused.';
            $this->identities->saveState($state);
            throw new \RuntimeException($state['message']);
        }
        $state['serverId'] = $info['Id'];
    }

    /** @param array<string, mixed> $state */
    private function requireFreshInventory(array $state, int $now): void
    {
        if ((int) $state['inventoryCompletedAt'] === 0 || $now - (int) $state['inventoryCompletedAt'] >= self::RESCAN_SECONDS) {
            throw new \RuntimeException('The movie list needs to refresh before this replacement can be saved.');
        }
    }

    /** @param \Closure(array<string, mixed>&): void $operation */
    private function uiMutation(\Closure $operation): void
    {
        $lease = $this->identities->acquireLease(($this->clock)());
        if ($lease === null) {
            throw new \RuntimeException('Movie recovery is busy. Please try again shortly.');
        }
        try {
            $state = $this->currentState();
            $operation($state);
        } finally {
            $this->identities->releaseLease($lease);
        }
    }

    /** @return array<string, mixed> */
    private function currentState(): array
    {
        $state = array_replace(['serverId' => '', 'blocked' => false, 'state' => 'checking', 'message' => 'Checking movie metadata.',
            'phase' => 'inventory', 'scan' => 1, 'offset' => 0, 'total' => null, 'lastId' => '', 'roots' => [],
            'inventoryCompletedAt' => 0, 'lastChecked' => 0, 'retryAfter' => 0, 'nextScanAt' => 0,
            'recoveryIds' => [], 'recoveryOffset' => 0, 'seenIds' => [], 'scanEvidence' => [],
            'previousFingerprint' => '', 'inventoryStable' => false, 'serverMismatch' => false], $this->identities->state());
        $state['blocked'] = $state['blocked'] || $state['serverMismatch'];

        return $state;
    }

    /** @return list<string> */
    private function recordedMovieIds(): array
    {
        new PlayHistoryRepository($this->database);
        $ids = [];
        $aliases = $this->replacements->all();
        $column = $this->database->getPlatform()->isSqlite()
            ? 'DISTINCT item_id COLLATE BINARY AS item_id' : 'DISTINCT BINARY item_id AS item_id';
        foreach ($this->database->getDibi()->select($column)->from('play_history')->where('item_type = %s', 'Movie')->fetchAll() as $row) {
            $id = (string) $row['item_id'];
            $ids[$aliases[$id] ?? $id] = true;
        }

        return array_map('strval', array_keys($ids));
    }

    /** @param list<string> $ids @return array{title: string, plays: int, library: string, conflict: bool} */
    private function historyEvidence(array $ids): array
    {
        $rows = $this->database->getDibi()->select('item_type, item_name, library, library_resolved_at, COUNT(*) AS plays')
            ->from('play_history')->where($this->database->getPlatform()->isSqlite()
                ? 'item_id COLLATE BINARY IN %in' : 'BINARY item_id IN %in', $ids)
            ->groupBy('item_type, item_name, library, library_resolved_at')->fetchAll();
        $plays = 0;
        $title = '';
        $libraries = [];
        $conflict = false;
        foreach ($rows as $row) {
            $conflict = $conflict || (string) $row['item_type'] !== 'Movie';
            $plays += (int) $row['plays'];
            $title = $title !== '' ? $title : (string) ($row['item_name'] ?? '');
            if (trim((string) ($row['library_resolved_at'] ?? '')) !== '' && trim((string) ($row['library'] ?? '')) !== '') {
                $libraries[(string) $row['library']] = true;
            }
        }

        return ['title' => $title, 'plays' => $plays, 'library' => count($libraries) === 1 ? (string) array_key_first($libraries) : '',
            'conflict' => $conflict || count($libraries) > 1];
    }

    private function hasNonMovieHistory(string $id): bool
    {
        $rows = $this->database->getDibi()->select('item_type')->from('play_history')
            ->where($this->database->getPlatform()->isSqlite() ? 'item_id COLLATE BINARY = %s' : 'BINARY item_id = %s', $id)->fetchAll();
        foreach ($rows as $row) {
            if ((string) $row['item_type'] !== 'Movie') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $target */
    private function incomingConflicts(string $id, array $target): bool
    {
        $evidence = [];
        foreach ($this->replacements->members($id) as $memberId) {
            $member = $this->identities->get($memberId);
            if ($member !== null) {
                $evidence[] = $member;
            }
        }

        return $this->evidenceConflicts($evidence, $target);
    }

    /** @param list<array<string, mixed>> $evidence @param array<string, mixed> $target */
    private function evidenceConflicts(array $evidence, array $target): bool
    {
        foreach ($evidence as $member) {
            if ($this->matcher->conflicts($member, $target)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function lookup(string $id): ?array
    {
        $payload = $this->client->getJson('/Items?' . http_build_query(['Ids' => $id, 'Limit' => 2,
            'Fields' => 'ProviderIds,OriginalTitle,Path,DateLastSaved'], '', '&', PHP_QUERY_RFC3986), 6);
        $items = $this->validatedItems($payload);
        if (count($items) > 1 || (isset($payload['TotalRecordCount']) && $payload['TotalRecordCount'] !== count($items))) {
            throw new \RuntimeException('Ambiguous movie metadata.');
        }
        if ($items === []) {
            return null;
        }
        if (ThemePlaybackExclusions::canonicalItemId($items[0]['Id']) !== ThemePlaybackExclusions::canonicalItemId($id)) {
            throw new \RuntimeException('Unexpected movie identity.');
        }

        return $items[0];
    }

    /** @return list<array<string, mixed>> */
    private function validatedItems(mixed $payload): array
    {
        if (!is_array($payload) || !is_array($payload['Items'] ?? null) || !array_is_list($payload['Items'])) {
            throw new \RuntimeException('Invalid movie metadata response.');
        }
        foreach ($payload['Items'] as $item) {
            if (!is_array($item) || !is_string($item['Type'] ?? null) || !is_string($item['Name'] ?? null)) {
                throw new \RuntimeException('Invalid movie metadata.');
            }
            $this->itemId($item['Id'] ?? null);
            if (isset($item['ProviderIds']) && !is_array($item['ProviderIds'])) {
                throw new \RuntimeException('Invalid movie provider metadata.');
            }
        }

        return $payload['Items'];
    }

    private function itemId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) !== 1) {
            throw new \RuntimeException('Invalid movie ID.');
        }

        return $value;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $state @return array<string, mixed> */
    private function identity(array $item, string $serverId, array $state): array
    {
        foreach (['Name', 'OriginalTitle', 'Path'] as $field) {
            if (isset($item[$field]) && !is_string($item[$field])) {
                throw new \RuntimeException('Invalid movie descriptive metadata.');
            }
        }
        $providers = [];
        foreach (($item['ProviderIds'] ?? []) as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value))) {
                throw new \RuntimeException('Invalid movie provider metadata.');
            }
            $key = mb_strtolower(trim($key));
            $value = trim((string) $value);
            if ($key === 'imdb') {
                $value = strtolower($value);
            }
            if ($key !== '' && $value !== '') {
                if (in_array($key, ['tmdb', 'tvdb'], true) && ctype_digit($value)) {
                    $value = ltrim($value, '0') ?: '0';
                }
                if (isset($providers[$key]) && $providers[$key] !== $value) {
                    throw new \RuntimeException('Conflicting movie provider metadata.');
                }
                $providers[$key] = $value;
            }
        }
        ksort($providers);
        $year = ($item['ProductionYear'] ?? null) === 0 ? null : ($item['ProductionYear'] ?? null);
        if ($year !== null && (!is_int($year) || $year < 1800 || $year > 3000)) {
            throw new \RuntimeException('Invalid movie year.');
        }
        $library = '';
        $path = str_replace('\\', '/', (string) ($item['Path'] ?? ''));
        $length = 0;
        foreach ($state['roots'] as $root) {
            if ($root['path'] !== '' && ($path === $root['path'] || str_starts_with($path, $root['path'] . '/'))
                && strlen($root['path']) > $length) {
                $library = $root['name'];
                $length = strlen($root['path']);
            }
        }

        return ['serverId' => $serverId, 'title' => (string) ($item['Name'] ?? ''),
            'originalTitle' => (string) ($item['OriginalTitle'] ?? ''), 'year' => $year, 'providers' => $providers, 'library' => $library];
    }

    /** @param array<string, mixed> $incoming */
    private function remember(string $id, array $incoming, bool $authoritative = false): void
    {
        $previous = $this->identities->get($id) ?? [];
        if (($previous['serverId'] ?? '') !== '' && ($previous['serverId'] ?? '') !== ($incoming['serverId'] ?? '')) {
            throw new \RuntimeException('Movie identity belongs to a different Jellyfin server.');
        }
        $conflict = (bool) ($previous['conflicted'] ?? false);
        $providers = $previous['providers'] ?? [];
        foreach (($incoming['providers'] ?? []) as $key => $value) {
            if (isset($providers[$key]) && $providers[$key] !== $value) {
                $conflict = true;
            }
        }
        if ($authoritative) {
            foreach ($providers as $key => $value) {
                if (!isset($incoming['providers'][$key])) {
                    $conflict = true;
                }
            }
            $providers = $incoming['providers'] ?? [];
        } elseif ($conflict && !empty($incoming['providers'])) {
            $providers = $incoming['providers'];
        } elseif (!$conflict) {
            $providers = array_replace($providers, $incoming['providers'] ?? []);
        }
        if (($previous['year'] ?? null) !== null && ($incoming['year'] ?? null) !== null
            && $previous['year'] !== $incoming['year']) {
            $conflict = true;
        }
        $incoming['providers'] = $providers;
        foreach (['title', 'originalTitle', 'library', 'year'] as $field) {
            if (($incoming[$field] ?? null) === null || ($incoming[$field] ?? '') === '') {
                $incoming[$field] = $previous[$field] ?? ($field === 'year' ? null : '');
            }
        }
        $incoming['conflicted'] = $conflict;
        $this->identities->put($id, array_replace($previous, $incoming));
    }
}
