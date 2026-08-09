<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

final class PushSubscriptionValidator
{
    private const MAX_ENDPOINT_LENGTH = 4096;
    private const PUBLIC_KEY_BYTES = 65;
    private const AUTH_SECRET_BYTES = 16;

    public static function isValid(string $endpoint, string $p256dh, string $auth): bool
    {
        return self::isValidEndpoint($endpoint)
            && self::hasDecodedLength($p256dh, self::PUBLIC_KEY_BYTES)
            && self::hasDecodedLength($auth, self::AUTH_SECRET_BYTES);
    }

    public static function isValidEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            return false;
        }

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (string) ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private static function hasDecodedLength(string $value, int $expectedBytes): bool
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return false;
        }

        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === $expectedBytes;
    }
}
