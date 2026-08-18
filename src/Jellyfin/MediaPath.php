<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class MediaPath
{
    public static function isWithin(string $path, string $directory): bool
    {
        $path = self::normalize($path);
        $directory = self::normalize($directory);
        if ($path === '' || $directory === '') {
            return false;
        }

        if (self::isWindows($path) && self::isWindows($directory)) {
            $path = mb_strtolower($path);
            $directory = mb_strtolower($directory);
        }

        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    private static function isWindows(string $path): bool
    {
        return preg_match('~^[A-Za-z]:(?:/|$)~', $path) === 1 || str_starts_with($path, '//');
    }
}
