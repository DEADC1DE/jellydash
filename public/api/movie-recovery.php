<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Authorization;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\MovieRecoveryService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'Method not allowed.']);
}
if (!(new Authorization())->can(Authorization::CAPABILITY_MANAGE_GLOBAL)) {
    $respond(403, ['error' => 'Only a manager can review movie replacements.']);
}
if ($method === 'POST' && !Csrf::validateHeader()) {
    $respond(419, ['error' => 'Refresh the page and try again.']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $page = max(1, min(1000000, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1)));
    $input = [];
    if ($method === 'POST') {
        $body = file_get_contents('php://input', length: 4097);
        if ($body === false || strlen($body) > 4096) {
            $respond(413, ['error' => 'The request is too large.']);
        }
        $input = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['action'] ?? null)) {
            $respond(400, ['error' => 'Choose a movie replacement action.']);
        }
        $action = $input['action'];
        if (!in_array($action, ['check', 'confirm', 'dismiss', 'undo', 'resume'], true)) {
            $respond(400, ['error' => 'Unknown movie replacement action.']);
        }
        foreach ($action === 'confirm' ? ['sourceId', 'targetId'] : ($action === 'check' ? [] : ['sourceId']) as $key) {
            if (!is_string($input[$key] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $input[$key]) !== 1) {
                $respond(400, ['error' => 'This movie selection is invalid. Refresh the page and try again.']);
            }
        }
    }
    $service = new MovieRecoveryService();
    if ($method === 'POST') {
        match ($input['action']) {
            'check' => $service->runBatch(),
            'confirm' => $service->confirm($input['sourceId'], $input['targetId']),
            'dismiss' => $service->dismiss($input['sourceId']),
            'undo' => $service->undo($input['sourceId']),
            'resume' => $service->resume($input['sourceId']),
        };
    }
    $respond(200, $service->status($page));
} catch (\JsonException | \InvalidArgumentException $error) {
    $respond(400, ['error' => 'This movie selection could not be read. Refresh the page and try again.']);
} catch (\Throwable $error) {
    Log::logException($error);
    $respond(409, ['error' => 'The movie could not be updated. It may have changed in Jellyfin, or another check is running. Refresh and try again.']);
}
