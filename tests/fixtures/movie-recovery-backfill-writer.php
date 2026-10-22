<?php

declare(strict_types=1);

// Only the disposable parent fixture supplies this connection, through stdin.
$input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_array($input['config'] ?? null) || !is_string($input['marker'] ?? null)) {
    exit(2);
}
putenv('DB_DRIVER=' . $input['config']['driver']);
putenv('DB_NAME=' . $input['config']['database']);
require dirname(__DIR__) . '/bootstrap.php';
$database = new \Mk\Framework\Database(new \Dibi\Connection($input['config']));
$service = new \Mk\Framework\Jellyfin\HistoryLibraryBackfillService($database,
    static function (array $ids) use ($input): array {
        file_put_contents($input['marker'] . '.loaded', 'loaded');
        return array_fill_keys($ids, ['runtime_sec' => 3600, 'library' => 'Other Movies']);
    }, $input['marker'] . '.lock');
// Prepare progress before the parent starts its final alias transaction.
(new \ReflectionMethod($service, 'initializeState'))->invoke($service);
file_put_contents($input['marker'] . '.ready', 'ready');
$deadline = microtime(true) + 10;
while (!is_file($input['marker'] . '.go')) {
    if (microtime(true) >= $deadline) {
        exit(3);
    }
    usleep(10000);
    clearstatcache();
}
$service->runBatch();
file_put_contents($input['marker'] . '.done', 'done');
