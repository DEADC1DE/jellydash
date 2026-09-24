<?php

declare(strict_types=1);

namespace Mk\Framework\Integrations;

use Mk\Framework\Downloads\Providers;

final class ConnectionInput
{
    /** @param array<string,mixed> $input */
    public static function parse(array $input, ?Connection $existing = null): Connection
    {
        $provider = self::string($input, 'provider', 32);
        if (!isset(Providers::NAMES[$provider])
            || ($existing !== null && $existing->provider !== $provider)) {
            throw new \InvalidArgumentException('Choose a supported client. An existing client type cannot be changed.');
        }
        $name = trim(self::string($input, 'name', 80));
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new \InvalidArgumentException('Enter a client name between 1 and 80 characters.');
        }
        $url = self::normalizeUrl(self::string($input, 'url', 2048));
        if ($provider === 'sabnzbd' && str_ends_with($url, '/api')) {
            $url = substr($url, 0, -4);
        } elseif ($provider === 'qbittorrent' && str_ends_with($url, '/api/v2')) {
            $url = substr($url, 0, -7);
        } else {
            $suffix = match ($provider) { 'transmission' => '/transmission/rpc', 'deluge' => '/json', 'nzbget' => '/jsonrpc', default => '' };
            if ($suffix !== '' && str_ends_with($url, $suffix)) {
                $url = substr($url, 0, -strlen($suffix));
            }
        }
        $username = trim(self::string($input, 'username', 256, ''));
        if (Providers::requiresUsername($provider) && (($provider !== 'nzbget' && $username === '')
            || (in_array($provider, ['transmission', 'nzbget'], true) && str_contains($username, ':')))) {
            throw new \InvalidArgumentException('Enter a valid ' . Providers::NAMES[$provider] . ' username.');
        }
        $mode = self::string($input, 'filter_mode', 16, 'all');
        if (!in_array($mode, ['all', 'selected'], true)) {
            throw new \InvalidArgumentException('Choose all downloads or selected categories and tags.');
        }
        $categories = self::values($input['categories'] ?? [], true);
        $tags = self::values($input['tags'] ?? [], false);
        if ($provider !== 'qbittorrent' && $tags !== []) {
            throw new \InvalidArgumentException('This client does not support tags.');
        }
        if ($mode === 'selected' && $categories === [] && $tags === []) {
            throw new \InvalidArgumentException('Select a category or tag, or choose all downloads.');
        }
        foreach (['enabled', 'verify_tls'] as $field) {
            if (isset($input[$field]) && !is_bool($input[$field])) {
                throw new \InvalidArgumentException('Invalid client setting.');
            }
        }
        return new Connection(
            id: $existing->id ?? bin2hex(random_bytes(16)), provider: $provider,
            name: $name, url: $url, username: Providers::requiresUsername($provider) ? $username : '',
            verifyTls: $input['verify_tls'] ?? true, enabled: $input['enabled'] ?? true,
            filterMode: $mode, categories: $categories, tags: $tags,
            revision: $existing->revision ?? 0, source: $existing->source ?? 'database',
            credentialSource: $existing->credentialSource ?? 'stored', hasSecret: $existing->hasSecret ?? false,
        );
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Enter a valid HTTP or HTTPS client URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Use an HTTP or HTTPS URL without credentials, a query, or a fragment.');
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[')) {
            if (!str_ends_with($host, ']') || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Enter a valid client hostname.');
            }
        } elseif (!preg_match('/^[a-z0-9_](?:[a-z0-9_.-]*[a-z0-9_])?$/D', $host)) {
            throw new \InvalidArgumentException('Enter a valid client hostname.');
        }
        $path = $parts['path'] ?? '';
        $decoded = rawurldecode($path);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded)) {
            throw new \InvalidArgumentException('The client URL contains an invalid base path.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if ($port !== null && $port < 1) {
            throw new \InvalidArgumentException('Enter a valid client port.');
        }
        $portText = $port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) ? ':' . $port : '';
        return $scheme . '://' . $host . $portText . rtrim($path, '/');
    }

    /** @param array<string,mixed> $input */
    public static function secret(array $input): ?string
    {
        $secret = self::string($input, 'secret', 4096, '');
        if (strlen($secret) > 4096) {
            throw new \InvalidArgumentException('The client secret is too long.');
        }
        return $secret === '' ? null : $secret;
    }

    /** @param array<string,mixed> $input */
    private static function string(array $input, string $field, int $maximum, ?string $default = null): string
    {
        $value = $input[$field] ?? $default;
        if (!is_string($value) || mb_strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Invalid or missing client field: ' . $field . '.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function values(mixed $values, bool $allowEmpty): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new \InvalidArgumentException('Use at most 50 category or tag names.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || mb_strlen($value, 'UTF-8') > 256 || !mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                throw new \InvalidArgumentException('A category or tag name is invalid.');
            }
            if ((!$allowEmpty && $value === '') || in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }
        return $result;
    }
}
