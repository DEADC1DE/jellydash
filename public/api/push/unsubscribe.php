<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Framework\Push\PushSubscriptionRepository;
use Mk\Framework\Push\PushSubscriptionValidator;

define('ROOT_DIR', dirname(__DIR__, 3));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

    return;
}

Csrf::checkHeader();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $endpoint = is_array($body) ? (string) ($body['endpoint'] ?? '') : '';

    if (!PushSubscriptionValidator::isValidEndpoint($endpoint)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid subscription']);

        return;
    }

    (new PushSubscriptionRepository())->delete($endpoint);

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not remove subscription.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ]);
}
