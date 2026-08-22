<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class LiveLibraryResolver
{
    /** @var \Closure(array<int, string>): array<string, array{runtime_sec: int, library: string}> */
    private \Closure $metaLoader;

    /**
     * @param null|callable(array<int, string>): array<string, array{runtime_sec: int, library: string}> $metaLoader
     */
    public function __construct(?callable $metaLoader = null)
    {
        if ($metaLoader !== null) {
            $this->metaLoader = \Closure::fromCallable($metaLoader);

            return;
        }

        $client = new JellyfinClient();
        $this->metaLoader = static fn (array $ids): array => $client->itemImportMeta($ids);
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     * @param array<int, string> $knownLibraries Libraries already confirmed for each stream index.
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $streams, array $knownLibraries = []): array
    {
        $unresolved = [];

        foreach ($streams as $index => &$stream) {
            $known = trim((string) ($knownLibraries[$index] ?? ''));
            if ($known !== '') {
                $stream['library'] = $known;
                $stream['libraryResolved'] = true;
                continue;
            }

            if (($stream['isLive'] ?? false) === true) {
                $stream['library'] = 'Live TV';
                $stream['libraryResolved'] = true;
                continue;
            }

            if (($stream['libraryResolved'] ?? false) === true) {
                continue;
            }

            $itemId = $this->normalizedItemId((string) ($stream['itemId'] ?? ''));
            if ($itemId !== '') {
                $unresolved[$itemId] = $itemId;
            }
        }
        unset($stream);

        if ($unresolved === []) {
            return $streams;
        }

        try {
            $meta = ($this->metaLoader)(array_values($unresolved));
        } catch (\Throwable) {
            return $streams;
        }

        $libraries = [];
        foreach ($meta as $itemId => $info) {
            $library = trim($info['library']);
            $itemId = $this->normalizedItemId((string) $itemId);
            if ($itemId !== '' && $library !== '') {
                $libraries[$itemId] = $library;
            }
        }

        foreach ($streams as &$stream) {
            if (($stream['isLive'] ?? false) === true || ($stream['libraryResolved'] ?? false) === true) {
                continue;
            }

            $itemId = $this->normalizedItemId((string) ($stream['itemId'] ?? ''));
            if (isset($libraries[$itemId])) {
                $stream['library'] = $libraries[$itemId];
                $stream['libraryResolved'] = true;
            }
        }
        unset($stream);

        return $streams;
    }

    private function normalizedItemId(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }
}
