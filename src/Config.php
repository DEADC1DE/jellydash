<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Environment-backed configuration.
 *
 * Values come from the process environment / `.env` (loaded via phpdotenv in
 * index.php). Everything has a sensible default so the app still boots if a key
 * is missing.
 */
class Config
{
    // Raw string lookup with fallback.
    public static function get(string $key, ?string $default = null): ?string
    {
        // The ?? chain already eliminates null; getenv() yields false when unset.
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    // Boolean lookup (accepts true/false/1/0/yes/no/on/off).
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    // Current application environment, e.g. "local" or "production".
    public static function env(): string
    {
        return self::get('APP_ENV', 'production');
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }
}
