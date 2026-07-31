<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$stats = [
    'available' => false,
    'status' => 'Unavailable',
    'cpu_pct' => null,
    'cpu_label' => 'N/A',
    'ram_pct' => null,
    'ram_label' => 'N/A',
];

$load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
$cores = 0;

if (is_readable('/proc/cpuinfo')) {
    $cpuInfo = (string) file_get_contents('/proc/cpuinfo');
    $cores = preg_match_all('/^processor\s*:/m', $cpuInfo);
}

if ($load !== false && isset($load[0]) && $cores > 0) {
    $cpuPct = min(100, max(0, (int) round(((float) $load[0] / $cores) * 100)));
    $stats['available'] = true;
    $stats['status'] = 'Online';
    $stats['cpu_pct'] = $cpuPct;
    $stats['cpu_label'] = $cpuPct . '%';
}

if (is_readable('/proc/meminfo')) {
    $meminfo = (string) file_get_contents('/proc/meminfo');
    if (
        preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $totalMatch)
        && preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $availableMatch)
    ) {
        $totalKb = (int) $totalMatch[1];
        $availableKb = (int) $availableMatch[1];
        $usedKb = max(0, $totalKb - $availableKb);
        $ramPct = $totalKb > 0 ? min(100, max(0, (int) round(($usedKb / $totalKb) * 100))) : 0;

        $stats['available'] = true;
        $stats['status'] = 'Online';
        $stats['ram_pct'] = $ramPct;
        $stats['ram_label'] = number_format($usedKb / 1048576, 1) . ' / ' . number_format($totalKb / 1048576, 1) . ' GB';
    }
}

echo json_encode($stats, JSON_THROW_ON_ERROR);
