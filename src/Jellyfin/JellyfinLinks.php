<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Config;

final class JellyfinLinks
{
    public static function baseUrl(string $apiUrl): ?string
    {
        $override = trim((string) Config::get('JELLYFIN_LINK_URL', ''));

        return self::validatedBaseUrl($override !== '' ? $override : $apiUrl);
    }

    public static function validatedBaseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url)
        ) {
            return null;
        }

        return $url;
    }
}
