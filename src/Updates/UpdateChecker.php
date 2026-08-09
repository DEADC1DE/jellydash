<?php

declare(strict_types=1);

namespace Mk\Framework\Updates;

final class UpdateChecker
{
    private const API_URL = 'https://api.github.com/repos/themartz90/jellydash/releases/latest';
    private const CACHE_TTL = 43200;
    private const FAILURE_TTL = 3600;
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var \Closure(?string): array{status: int, body: string, etag: ?string} */
    private \Closure $fetcher;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        ?callable $fetcher = null,
        ?string $cacheFile = null,
        ?callable $clock = null,
        private int $cacheTtl = self::CACHE_TTL,
        private int $failureTtl = self::FAILURE_TTL,
    ) {
        $this->fetcher = $fetcher === null
            ? \Closure::fromCallable([$this, 'fetchLatest'])
            : \Closure::fromCallable($fetcher);
        $this->cacheFile = $cacheFile ?? ROOT_DIR . '/var/cache/update-release.json';
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
        $this->cacheTtl = max(1, $this->cacheTtl);
        $this->failureTtl = max(1, $this->failureTtl);
    }

    private string $cacheFile;

    /**
     * @return array{
     *     checked: bool,
     *     fresh: bool,
     *     current_version: string,
     *     latest_version: ?string,
     *     release_url: ?string,
     *     update_available: bool,
     *     checked_at: ?int
     * }
     */
    public function status(string $currentVersion): array
    {
        $currentVersion = ltrim(trim($currentVersion), 'vV');
        $release = $this->latestRelease();

        if ($release === null) {
            return [
                'checked' => false,
                'fresh' => false,
                'current_version' => $currentVersion,
                'latest_version' => null,
                'release_url' => null,
                'update_available' => false,
                'checked_at' => null,
            ];
        }

        $currentComparable = $this->normalizeVersion($currentVersion);

        return [
            'checked' => true,
            'fresh' => $this->isFresh($release['checked_at']),
            'current_version' => $currentVersion,
            'latest_version' => $release['version'],
            'release_url' => $release['url'],
            'update_available' => $currentComparable !== null
                && version_compare($release['version'], $currentComparable, '>'),
            'checked_at' => $release['checked_at'],
        ];
    }

    private function isFresh(int $checkedAt): bool
    {
        $now = ($this->clock)();

        return $checkedAt <= $now && ($now - $checkedAt) < $this->cacheTtl;
    }

    /**
     * @return array{version: string, url: string, checked_at: int}|null
     */
    private function latestRelease(): ?array
    {
        $now = ($this->clock)();
        $cached = $this->readCache();

        if ($this->canUseCache($cached, $now)) {
            return $this->releaseFromCache($cached);
        }

        $lock = $this->openLock();
        if ($lock === null) {
            return $this->releaseFromCache($cached);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return $this->releaseFromCache($cached);
            }

            $cached = $this->readCache();
            if ($this->canUseCache($cached, $now)) {
                return $this->releaseFromCache($cached);
            }

            try {
                $response = ($this->fetcher)($this->etagFromCache($cached));
            } catch (\Throwable) {
                $this->rememberFailure($cached, $now);

                return $this->releaseFromCache($cached);
            }

            if ($response['status'] === 304 && $this->releaseFromCache($cached) !== null) {
                $cached['checked_at'] = $now;
                $cached['retry_after'] = 0;
                $this->writeCache($cached);

                return $this->releaseFromCache($cached);
            }

            if ($response['status'] !== 200) {
                $this->rememberFailure($cached, $now);

                return $this->releaseFromCache($cached);
            }

            $release = $this->decodeRelease($response['body']);
            if ($release === null) {
                $this->rememberFailure($cached, $now);

                return $this->releaseFromCache($cached);
            }

            $cache = [
                'version' => $release['version'],
                'url' => $release['url'],
                'etag' => $this->cleanEtag($response['etag']),
                'checked_at' => $now,
                'retry_after' => 0,
            ];
            $this->writeCache($cache);

            return $this->releaseFromCache($cache);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, mixed>|null $cache
     */
    private function canUseCache(?array $cache, int $now): bool
    {
        if ($cache === null) {
            return false;
        }

        $retryAfter = (int) ($cache['retry_after'] ?? 0);
        if ($retryAfter > $now) {
            return true;
        }

        if ($this->releaseFromCache($cache) === null) {
            return false;
        }

        $checkedAt = (int) ($cache['checked_at'] ?? 0);

        return $checkedAt > 0 && ($now - $checkedAt) < $this->cacheTtl;
    }

    /**
     * @param array<string, mixed>|null $cache
     * @return array{version: string, url: string, checked_at: int}|null
     */
    private function releaseFromCache(?array $cache): ?array
    {
        if ($cache === null) {
            return null;
        }

        $version = $this->normalizeVersion((string) ($cache['version'] ?? ''));
        $url = (string) ($cache['url'] ?? '');
        $checkedAt = (int) ($cache['checked_at'] ?? 0);

        if ($version === null || !$this->isReleaseUrl($url, $version) || $checkedAt <= 0) {
            return null;
        }

        return [
            'version' => $version,
            'url' => $url,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * @param array<string, mixed>|null $cache
     */
    private function etagFromCache(?array $cache): ?string
    {
        return $this->cleanEtag(is_array($cache) ? ($cache['etag'] ?? null) : null);
    }

    /**
     * @return array{version: string, url: string}|null
     */
    private function decodeRelease(string $body): ?array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $version = $this->normalizeVersion((string) ($payload['tag_name'] ?? ''));
        $url = (string) ($payload['html_url'] ?? '');

        if ($version === null || !$this->isReleaseUrl($url, $version)) {
            return null;
        }

        return ['version' => $version, 'url' => $url];
    }

    private function normalizeVersion(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?(?:\+[0-9A-Za-z][0-9A-Za-z.-]*)?$/', $version) === 1
            ? $version
            : null;
    }

    private function isReleaseUrl(string $url, string $version): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        $prefix = '/themartz90/jellydash/releases/tag/';
        $path = (string) ($parts['path'] ?? '');
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $urlVersion = $this->normalizeVersion(rawurldecode(substr($path, strlen($prefix))));

        return $urlVersion !== null && $urlVersion === $version;
    }

    private function cleanEtag(mixed $etag): ?string
    {
        if (!is_string($etag)) {
            return null;
        }

        $etag = trim($etag);

        return $etag !== '' && strlen($etag) <= 255 && !str_contains($etag, "\r") && !str_contains($etag, "\n")
            ? $etag
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        try {
            $payload = json_decode((string) file_get_contents($this->cacheFile), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed>|null $cache
     */
    private function rememberFailure(?array $cache, int $now): void
    {
        $cache = $cache ?? [];
        $cache['retry_after'] = $now + $this->failureTtl;
        $this->writeCache($cache);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        if (!$this->ensureCacheDirectory()) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($this->cacheFile, $encoded, LOCK_EX) !== false) {
                @chmod($this->cacheFile, 0666);
            }
        } catch (\Throwable) {
            return;
        }
    }

    /** @return resource|null */
    private function openLock()
    {
        if (!$this->ensureCacheDirectory()) {
            return null;
        }

        $lock = @fopen($this->cacheFile . '.lock', 'c+');

        return is_resource($lock) ? $lock : null;
    }

    private function ensureCacheDirectory(): bool
    {
        $directory = dirname($this->cacheFile);

        return is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory);
    }

    /**
     * @return array{status: int, body: string, etag: ?string}
     */
    private function fetchLatest(?string $etag): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2026-03-10',
        ];
        if ($etag !== null) {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $responseEtag = null;
        $handle = curl_init(self::API_URL);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Jellydash-Update-Checker (+https://github.com/themartz90/jellydash)',
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseEtag): int {
                if (stripos($line, 'etag:') === 0) {
                    $responseEtag = trim(substr($line, 5));
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException('GitHub release request failed: ' . $error);
        }

        $body = (string) $body;
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('GitHub release response was too large.');
        }

        return [
            'status' => $status,
            'body' => $body,
            'etag' => $this->cleanEtag($responseEtag),
        ];
    }
}
