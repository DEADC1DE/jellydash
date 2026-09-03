<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

interface LibraryHistorySource
{
    /**
     * @return array<int, \Dibi\Row>
     */
    public function itemPlaySummaries(): array;
}
