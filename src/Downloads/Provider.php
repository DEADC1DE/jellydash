<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Integrations\Connection;

interface Provider
{
    /** @param array{username:string,secret:string} $credentials @return array{version:string,categories:list<string>,tags:list<string>} */
    public function testConnection(Connection $connection, array $credentials): array;

    /**
     * @param array{username:string,secret:string} $credentials
     * @param array<string,mixed> $cursor
     * @param array<string,mixed> $session
     */
    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch;
}
