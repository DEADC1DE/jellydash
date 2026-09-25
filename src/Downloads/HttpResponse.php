<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

final readonly class HttpResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    /** @return list<string> */
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
