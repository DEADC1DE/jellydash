<?php

declare(strict_types=1);

/**
 * Shared guard for the JSON endpoints under public/api/.
 *
 * When optional authentication is on (AUTH_ENABLED), every API call must carry
 * a logged-in session; the dashboard pages that consume these endpoints are
 * behind the same gate, so a valid browser session satisfies this transparently.
 *
 * Must be included AFTER utils/@settings.php (needs the session started) and
 * BEFORE any session_write_close() call.
 */

use Mk\Framework\Authorization;
use Mk\Framework\Config;

if (Config::bool('AUTH_ENABLED', false) && !(new Authorization())->isUserLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
