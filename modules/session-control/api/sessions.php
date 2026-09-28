<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Modules\SessionControl\SessionActionsService;

header('Content-Type: application/json; charset=utf-8');

(new Authorization())->requireRole(Authorization::ROLE_ADMIN);
Csrf::checkHeader();

$action = (string) ($_POST['action'] ?? '');
$sessionId = (string) ($_POST['sessionId'] ?? '');

if (!in_array($action, ['stop', 'kick'], true) || $sessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or unknown action.']);
    exit;
}

try {
    $service = new SessionActionsService();
    $ok = $action === 'stop' ? $service->stop($sessionId) : $service->kick($sessionId);
    echo json_encode(['ok' => $ok]);
} catch (\Throwable $e) {
    http_response_code(502);
    Log::logException($e);
    echo json_encode(['error' => 'Could not reach Jellyfin.']);
}
