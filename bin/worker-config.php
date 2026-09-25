<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Mk\Framework\Config;

echo implode(' ', [
    Config::bool('POLLER_ENABLED', true) ? 1 : 0,
    Config::interval('POLL_INTERVAL', 30),
    Config::interval('LIBRARIES_CACHE_TTL', 300),
    Config::interval('SEERR_POLL_INTERVAL', 120),
    Mk\Framework\Downloads\Collector::INTERVAL,
]), PHP_EOL;
