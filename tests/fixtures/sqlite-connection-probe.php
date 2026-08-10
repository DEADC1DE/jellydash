<?php

declare(strict_types=1);

use Mk\Framework\Database;

require dirname(__DIR__) . '/bootstrap.php';

$database = new Database();
$dibi = $database->getDibi();
$dibi->query('CREATE TABLE `connection_probe` (`happened_at` TEXT NOT NULL)');
$dibi->insert('connection_probe', [
    'happened_at' => new DateTimeImmutable('2026-08-11 12:34:56'),
])->execute();

echo json_encode([
    'driver' => $dibi->getConfig('driver'),
    'journalMode' => $dibi->query('PRAGMA journal_mode')->fetchSingle(),
    'busyTimeout' => (int) $dibi->query('PRAGMA busy_timeout')->fetchSingle(),
    'dateTime' => (string) $dibi->select('happened_at')->from('connection_probe')->fetchSingle(),
], JSON_THROW_ON_ERROR);
