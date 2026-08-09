<?php

declare(strict_types=1);

namespace Mk\Framework\Http;

final class ImageContentType
{
    private const ALLOWED = [
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function normalize(string $contentType): ?string
    {
        $normalized = strtolower(trim(explode(';', $contentType, 2)[0]));

        return in_array($normalized, self::ALLOWED, true) ? $normalized : null;
    }
}
