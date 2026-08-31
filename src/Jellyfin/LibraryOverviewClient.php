<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

interface LibraryOverviewClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function mediaFolders(): array;

    /**
     * @param array<string, string|int|bool> $query
     */
    public function itemCount(array $query): int;
}
