<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final readonly class HistoryFilters
{
    public function __construct(
        public string $search = '',
        public string $user = '',
        public string $library = '',
        public string $range = '30',
        public int $limit = 100,
        public int $offset = 0,
    ) {
    }

    public function rangeDays(): ?int
    {
        return match ($this->range) {
            '7' => 7,
            '30' => 30,
            default => null,
        };
    }
}
