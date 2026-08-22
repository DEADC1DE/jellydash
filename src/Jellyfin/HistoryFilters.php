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

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $range = self::queryString($query, 'range', '30');
        if (!in_array($range, ['7', '30', 'all'], true)) {
            $range = '30';
        }

        return new self(
            search: trim(self::queryString($query, 'search')),
            user: trim(self::queryString($query, 'user')),
            library: trim(self::queryString($query, 'library')),
            range: $range,
        );
    }

    public function rangeDays(): ?int
    {
        return match ($this->range) {
            '7' => 7,
            '30' => 30,
            default => null,
        };
    }

    /** @return array<string, string> */
    public function queryParameters(): array
    {
        return array_filter([
            'search' => $this->search,
            'user' => $this->user,
            'library' => $this->library,
            'range' => $this->range !== '30' ? $this->range : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}
