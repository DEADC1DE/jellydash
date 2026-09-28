<?php

declare(strict_types=1);

use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Modules\ServerHealth\ServerHealthService;

header('Content-Type: application/json; charset=utf-8');

(new Authorization())->requireRole(Authorization::ROLE_ADMIN);
Csrf::checkHeader();

$action = (string) ($_POST['action'] ?? '');
$id = (string) ($_POST['id'] ?? '');

if (!in_array($action, ['trigger', 'stop'], true) || $id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or unknown action.']);
    exit;
}

try {
    $service = new ServerHealthService();
    $ok = $action === 'trigger' ? $service->triggerTask($id) : $service->stopTask($id);
    echo json_encode(['ok' => $ok]);
} catch (\Throwable $e) {
    http_response_code(502);
    Log::logException($e);
    echo json_encode(['error' => 'Could not reach Jellyfin.']);
}
