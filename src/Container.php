<?php

declare(strict_types=1);

namespace Mk\Framework;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Minimal service container.
 *
 * Holds the shared, per-request services so they're built once, most
 * importantly the database connection (previously a new connection was opened
 * by nearly every object). Services are created lazily on first access.
 */
final class Container
{
    /** @var array<string, object> */
    private static array $services = [];

    public static function db(): Database
    {
        return self::$services['db'] ??= new Database();
    }

    public static function logger(): LoggerInterface
    {
        return self::$services['logger'] ??= self::makeLogger();
    }

    // Override a service (used by tests to inject fakes).
    public static function set(string $id, object $service): void
    {
        self::$services[$id] = $service;
    }

    // Forget all services (used by tests for isolation).
    public static function reset(): void
    {
        self::$services = [];
    }

    private static function makeLogger(): LoggerInterface
    {
        $logDir = ROOT_DIR . '/var/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $level = Config::isDebug() ? Level::Debug : Level::Info;

        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler($logDir . '/app.log', $level));

        return $logger;
    }
}
