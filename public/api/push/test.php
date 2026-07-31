<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Log;
use Mk\Framework\Push\PlaybackNotifier;

define('ROOT_DIR', dirname(__DIR__, 3));

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

    return;
}

try {
    echo json_encode((new PlaybackNotifier())->sendTest());
} catch (\Throwable $e) {
    http_response_code(500);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not send test notification.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
}
