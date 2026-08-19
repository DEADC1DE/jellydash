<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Jellyfin\DeviceActivityService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$range = isset($_GET['range']) && is_string($_GET['range']) ? $_GET['range'] : 'week';

try {
    echo json_encode((new DeviceActivityService())->payload($range), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Log::logException($e);
    http_response_code(502);

    echo json_encode([
        'error' => 'Could not load Jellyfin devices.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
