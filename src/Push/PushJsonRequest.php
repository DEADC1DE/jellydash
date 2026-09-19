<?php

declare(strict_types=1);

namespace Mk\Framework\Push;

final class PushJsonRequest
{
    private const MAX_BYTES = 16_384;

    /** @return array<string|int, mixed> */
    public static function decode(): array
    {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not read the request body.');
        }
        try {
            return self::decodeStream($stream, isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : null);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     * @return array<string|int, mixed>
     */
    public static function decodeStream($stream, ?string $contentLength = null): array
    {
        if ($contentLength !== null && ctype_digit($contentLength)) {
            $declared = ltrim($contentLength, '0');
            $limit = (string) self::MAX_BYTES;
            if (strlen($declared) > strlen($limit)
                || (strlen($declared) === strlen($limit) && strcmp($declared, $limit) > 0)) {
                throw new \LengthException('Push request body is too large.');
            }
        }

        $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw new \RuntimeException('Could not read the request body.');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \LengthException('Push request body is too large.');
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        }

        return $decoded;
    }
}
