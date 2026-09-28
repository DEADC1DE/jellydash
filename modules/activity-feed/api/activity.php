<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Modules\ActivityFeed\GhostUserResolver;

header('Content-Type: application/json; charset=utf-8');

(new Authorization())->requireRole(Authorization::ROLE_ADMIN);
Csrf::checkHeader();

$action = (string) ($_POST['action'] ?? '');

if ($action !== 'resolve-ghosts') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

try {
    $resolved = (new GhostUserResolver())->resolve();
    echo json_encode(['ok' => true, 'resolved' => $resolved]);
} catch (\Throwable $e) {
    http_response_code(502);
    Log::logException($e);
    echo json_encode(['error' => 'Could not resolve ghost users.']);
}
