<?php

declare(strict_types=1);

// Disposable CI data only. This script is excluded from the public image.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_NAME') !== (getenv('DB_DRIVER') === 'sqlite3'
    ? '/var/www/html/var/data/ci-downloads.sqlite' : 'ci_downloads')) {
    throw new RuntimeException('Expected the isolated Downloads persistence database.');
}

define('ROOT_DIR', '/var/www/html');
require ROOT_DIR . '/vendor/autoload.php';
define('DATABASE_DRIVER_DIBI', getenv('DB_DRIVER'));
define('DATABASE_NAME', getenv('DB_NAME'));
define('DATABASE_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DATABASE_USERNAME', getenv('DB_USER') ?: 'root');
define('DATABASE_PASSWORD', getenv('DB_PASS') ?: '');

use Mk\Framework\Database;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Downloads\OverviewService;
use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionRepository;

$database = new Database();
$connections = new ConnectionRepository($database);
$downloads = new DownloadRepository($database);
$mode = $argv[1] ?? '';
$secret = 'ci-downloader-password';
$session = ['sid' => 'ci-downloader-session'];

if ($mode === 'seed') {
    $connection = $connections->save(new Connection('ci-qb', 'qbittorrent', 'CI client',
        'http://127.0.0.1:9', 'ci-user', filterMode: 'selected', categories: ['movies']), $secret);
    $now = time();
    $token = $downloads->claim($connection, $now);
    if ($token === null || !$downloads->succeed($connection, $token, new CollectionBatch(
        completions: [['source_id' => 'ci-result', 'title' => 'CI sample', 'category' => 'movies',
            'tags' => [], 'state' => 'completed', 'size' => 1000, 'completed_at' => $now]],
        session: $session,
    ), $now)) {
        throw new RuntimeException('Could not save CI download activity.');
    }
} elseif ($mode === 'verify') {
    $connection = $connections->find('ci-qb');
    if ($connection === null || $connection->categories !== ['movies']
        || $connections->credentials($connection) !== ['username' => 'ci-user', 'secret' => $secret]
        || $downloads->session($connection) !== $session
        || array_column($downloads->recent(['ci-qb']), 'source_id') !== ['ci-result']) {
        throw new RuntimeException('Saved Downloads data did not survive container replacement.');
    }
    $overview = (new OverviewService($connections, $downloads))->snapshot();
    if ($overview['feature_enabled'] !== true || $overview['configured'] !== true) {
        throw new RuntimeException('The saved client was not available to the monitor.');
    }
    $public = json_encode([$overview, $connection->managementData()], JSON_THROW_ON_ERROR);
    if (str_contains($public, $secret) || str_contains($public, $session['sid'])) {
        throw new RuntimeException('A server-side credential was exposed by the public data.');
    }
} else {
    throw new RuntimeException('Expected seed or verify.');
}

if (file_exists(ROOT_DIR . '/var/data/integration-key') || file_exists(ROOT_DIR . '/var/data/integration-key.lock')) {
    throw new RuntimeException('Downloads unexpectedly depends on a key file.');
}
echo 'Downloads persistence ' . $mode . " passed.\n";
