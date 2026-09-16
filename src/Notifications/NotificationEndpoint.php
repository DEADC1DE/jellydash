<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

use Mk\Framework\Config;

/** Validation and text limits shared by the self-hosted notification channels. */
final class NotificationEndpoint
{
    public static function setting(string $key): string
    {
        return trim((string) Config::get($key, ''), ' ');
    }

    public static function baseUrl(string $url): ?string
    {
        if (!self::validUrl($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (preg_match('~[\x00-\x20\x7f\\\\]|(?:^|/)\.{1,2}(?:/|$)~', $path) === 1) {
            return null;
        }

        return rtrim($url, '/') . '/';
    }

    public static function validUrl(string $url): bool
    {
        if ($url === '' || preg_match('~[\x00-\x20\x7f\\\\]~', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && (!isset($parts['port']) || $parts['port'] >= 1)
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/[^\x21-\x7e]/', $token) !== 1;
    }

    public static function text(string $value, int $maxBytes): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return strlen($value) <= $maxBytes ? $value : mb_strcut($value, 0, $maxBytes - 3, 'UTF-8') . '…';
    }
}
