<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

final readonly class HttpRequest
{
    /** @param list<string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public bool $verifyTls = true,
        public ?JsonRpcHistoryStream $historyStream = null,
        public int $timeoutMs = 5000,
    ) {
    }
}
