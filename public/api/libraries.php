<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Jellyfin\LibraryOverviewService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// Read-only endpoint: release the PHP session lock right away so this (slow)
// library scan doesn't block other requests from the same browser; page
// navigation and the Now Playing poll would otherwise queue on the session
// lock, freezing the whole UI until the scan finishes.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Caching (TTL + stale fallback) lives in the service so the background warmer
// (bin/console.php libraries:warm) and this endpoint stay in sync. A warm cache
// means this normally returns instantly.
try {
    echo json_encode((new LibraryOverviewService())->cachedPayload(), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Log::logException($e);

    http_response_code(502);
    echo json_encode([
        'error' => 'Could not load Jellyfin libraries.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
