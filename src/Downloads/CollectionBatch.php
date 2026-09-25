<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

final readonly class CollectionBatch
{
    /**
     * @param list<array<string,mixed>> $items Normalized active, processing, waiting and problem items.
     * @param list<array<string,mixed>> $completions Authoritative terminal outcomes: completed or failed.
     * @param array<string,mixed> $cursor Provider continuation state, without credentials.
     * @param array<string,mixed> $session Server-only session state, persisted in the application database.
     * @param array<string,mixed> $history Independent recent-activity refresh metadata.
     */
    public function __construct(
        public array $items = [],
        public array $completions = [],
        public ?float $speed = null,
        public array $cursor = [],
        public bool $complete = true,
        public array $session = [],
        public array $history = [],
    ) {
    }
}
