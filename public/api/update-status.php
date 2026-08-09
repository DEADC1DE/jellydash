<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Log;
use Mk\Framework\Updates\UpdateChecker;
use Mk\Framework\View;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// The first check can take a few seconds when GitHub is unreachable. It must
// never hold up navigation or another request from this browser.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    echo json_encode((new UpdateChecker())->status(View::version()), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Log::logException($e);

    http_response_code(502);
    echo json_encode(['error' => 'Could not check for updates.'], JSON_THROW_ON_ERROR);
}
