<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Modules;

/**
 * Streams static assets (js/css/images/fonts) from a module's assets/ folder.
 * Modules live outside the web root, so their assets need this passthrough.
 * Equivalent to the core's public assets: served without an auth check.
 */

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

$module = (string) ($_GET['m'] ?? '');
$file = (string) ($_GET['f'] ?? '');

$path = Modules::assetPath($module, $file);

$types = [
    'js' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'woff2' => 'font/woff2',
];

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if ($path === null || !isset($types[$extension])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $types[$extension]);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
