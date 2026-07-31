<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Jellyfin\NowPlayingService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// Read-only endpoint: release the session lock so this 5s poll never blocks
// other requests from the same browser on the session lock.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    echo json_encode((new NowPlayingService())->payload(), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(502);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not load Jellyfin sessions.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
