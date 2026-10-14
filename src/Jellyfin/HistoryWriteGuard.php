<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Database;

/** Serializes local History evidence and replacement decisions before transactions start. */
final class HistoryWriteGuard
{
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $owners = null;

    public function __construct(private readonly Database $database) {}

    /**
     * MariaDB waits at most five seconds, then refuses the operation. Acquisition is
     * reentrant on one connection. SQLite writers use their existing transactions;
     * replacement decisions read inside BEGIN IMMEDIATE. Never wrap network calls.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $db = $this->database->getDibi();
        self::$owners ??= new \WeakMap();
        if ($this->database->getPlatform()->isSqlite() || isset(self::$owners[$db])) {
            return $operation();
        }
        $name = 'jellydash:history:' . substr(hash('sha256', (string) $db->getConfig('database')), 0, 46);
        if ((int) $db->query('SELECT GET_LOCK(%s, 5)', $name)->fetchSingle() !== 1) {
            throw new \RuntimeException('History is being updated. Please try again shortly.');
        }
        self::$owners[$db] = true;
        try {
            return $operation();
        } finally {
            unset(self::$owners[$db]);
            $db->query('SELECT RELEASE_LOCK(%s)', $name);
        }
    }
}
