<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Downloads\DownloadRepository;
use Mk\Framework\Downloads\OverviewService;
use Mk\Framework\Integrations\ConnectionRepository;

define('ROOT_DIR', dirname(__DIR__, 3));
require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';
Dotenv::createImmutable(ROOT_DIR)->safeLoad();
include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
session_write_close();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    return;
}
try {
    $service = new OverviewService(new ConnectionRepository(), new DownloadRepository());
    echo json_encode($service->snapshot(summary: ($_GET['summary'] ?? '') === '1'), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\Throwable) {
    http_response_code(503);
    echo json_encode(['error' => 'Download status is unavailable. Try again shortly.']);
}
