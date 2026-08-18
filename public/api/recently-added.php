<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Jellyfin\RecentlyAddedService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// Read-only and independently cached, so poster discovery never holds the
// session lock or joins the five-second Now Playing polling loop.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    echo json_encode((new RecentlyAddedService())->cachedPayload(), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Log::logException($e);
    http_response_code(502);

    echo json_encode([
        'error' => 'Could not load recently added media.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
