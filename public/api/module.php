<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Modules;

/**
 * Dispatches /api/module.php?m=<name> to the module's declared API handler.
 * The handler file runs with the standard bootstrap already done (env, session,
 * auth guard) and module classes autoloadable; it echoes its own response.
 */

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

// Read-only by convention: release the session lock so module polling never
// serializes other requests from the same browser.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$handler = Modules::apiHandler((string) ($_GET['m'] ?? ''));

if ($handler === null) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => 'Unknown module.']);
    exit;
}

require $handler;
