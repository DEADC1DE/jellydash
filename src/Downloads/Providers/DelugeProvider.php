<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads\Providers;

use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloaderHttpClient;
use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\Provider;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Integrations\Connection;

/** Reads a single saved Deluge daemon through the authenticated Web JSON API. */
final class DelugeProvider implements Provider
{
    private const MAX_CURRENT = 1000;
    private const MAX_RECENT = 100;
    private const TORRENT_FIELDS = [
        'name', 'state', 'progress', 'total_wanted', 'total_remaining',
        'download_payload_rate', 'eta', 'queue', 'completed_time', 'is_finished', 'num_pieces',
    ];

    public function __construct(private readonly DownloaderTransport $transport = new DownloaderHttpClient())
    {
    }

    public function testConnection(Connection $connection, array $credentials): array
    {
        $endpoint = $this->endpoint($connection->url);
        $cookie = $this->login($connection, $credentials, $endpoint);
        $version = $this->connectedHost($connection, $endpoint, $cookie);
        $enabled = $this->enabledPlugins($connection, $endpoint, $cookie);
        $labels = [''];
        if ($enabled) {
            $found = $this->rpc($connection, $endpoint, $cookie, 'label.get_labels');
            if (!is_array($found) || !array_is_list($found)) {
                throw new ProviderException('invalid_response');
            }
            foreach ($found as $label) {
                $labels[] = $this->identity($label);
            }
        } elseif ($this->hasSelectedLabel($connection)) {
            throw new ProviderException('labels_unavailable');
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);

        return ['version' => $version, 'categories' => array_slice($labels, 0, 250), 'tags' => []];
    }

    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
    {
        $endpoint = $this->endpoint($connection->url);
        $cookie = $this->cachedCookie($session, $endpoint);
        if ($cookie !== null) {
            try {
                return $this->collectWithCookie($connection, $endpoint, $cookie);
            } catch (ProviderException $exception) {
                if ($exception->reason !== 'authentication_failed') {
                    throw $exception;
                }
            }
        }
        $cookie = $this->login($connection, $credentials, $endpoint);
        return $this->collectWithCookie($connection, $endpoint, $cookie);
    }

    private function collectWithCookie(Connection $connection, string $endpoint, string $cookie): CollectionBatch
    {
        $this->connectedHost($connection, $endpoint, $cookie);
        $labelEnabled = $this->enabledPlugins($connection, $endpoint, $cookie);
        if (!$labelEnabled && $this->hasSelectedLabel($connection)) {
            throw new ProviderException('labels_unavailable');
        }
        $stats = $this->rpc($connection, $endpoint, $cookie, 'core.get_session_status', [['payload_download_rate']]);
        if (!is_array($stats)) {
            throw new ProviderException('invalid_response');
        }
        $fields = self::TORRENT_FIELDS;
        if ($labelEnabled) {
            $fields[] = 'label';
        }
        $rows = $this->rpc($connection, $endpoint, $cookie, 'core.get_torrents_status', [(object) [], $fields]);
        if (!is_array($rows)) {
            throw new ProviderException('invalid_response');
        }
        $items = [];
        $completions = [];
        $currentCount = 0;
        foreach ($rows as $hash => $row) {
            if (!is_string($hash) || !is_array($row)) {
                throw new ProviderException('invalid_response');
            }
            $item = $this->torrent($hash, $row, $labelEnabled);
            if ($item['state'] === 'completed') {
                if ($connection->matches($item)) {
                    $completions[] = $item;
                }
            } else {
                ++$currentCount;
                if (count($items) < self::MAX_CURRENT) {
                    $items[] = $item;
                }
            }
        }
        usort($completions, static fn (array $a, array $b): int => ($b['completed_at'] ?? 0) <=> ($a['completed_at'] ?? 0));
        return new CollectionBatch(
            $items,
            array_slice($completions, 0, self::MAX_RECENT),
            $this->number($stats['payload_download_rate'] ?? null, 0),
            [],
            $currentCount <= self::MAX_CURRENT,
            ['sid' => $cookie, 'endpoint_hash' => hash('sha256', $endpoint)],
        );
    }

    /** @param array{username:string,secret:string} $credentials */
    private function login(Connection $connection, array $credentials, string $endpoint): string
    {
        $body = json_encode(['method' => 'auth.login', 'params' => [$credentials['secret']], 'id' => 1], JSON_THROW_ON_ERROR);
        $response = $this->transport->request(new HttpRequest('POST', $endpoint, ['Content-Type: application/json', 'Accept: application/json'], $body, $connection->verifyTls));
        $result = $this->readResponse($response, 1);
        if ($result !== true) {
            throw new ProviderException('authentication_failed');
        }
        foreach ($response->header('set-cookie') as $header) {
            if (preg_match('/(?:^|;\s*)_session_id=([a-f0-9]{64}[0-9]{4})(?:;|$)/D', $header, $match) === 1) {
                return $match[1];
            }
        }
        throw new ProviderException('authentication_failed');
    }

    private function connectedHost(Connection $connection, string $endpoint, string $cookie): string
    {
        $hosts = $this->rpc($connection, $endpoint, $cookie, 'web.get_hosts');
        if (!is_array($hosts) || !array_is_list($hosts)) {
            throw new ProviderException('invalid_response');
        }
        if (count($hosts) !== 1) {
            throw new ProviderException('ambiguous_host');
        }
        $host = $hosts[0];
        if (!is_array($host) || !array_is_list($host) || count($host) !== 4
            || !is_string($host[0]) || preg_match('/^[a-f0-9]{32}$/Di', $host[0]) !== 1) {
            throw new ProviderException('invalid_response');
        }
        $connected = $this->rpc($connection, $endpoint, $cookie, 'web.connected');
        if (!is_bool($connected)) {
            throw new ProviderException('invalid_response');
        }
        if (!$connected) {
            $methods = $this->rpc($connection, $endpoint, $cookie, 'web.connect', [$host[0]]);
            if (!is_array($methods) || !array_is_list($methods)
                || $this->rpc($connection, $endpoint, $cookie, 'web.connected') !== true) {
                throw new ProviderException('request_failed');
            }
        }
        $status = $this->rpc($connection, $endpoint, $cookie, 'web.get_host_status', [$host[0]]);
        if (!is_array($status) || !array_is_list($status) || count($status) !== 3
            || $status[0] !== $host[0] || $status[1] !== 'Connected' || !is_string($status[2])) {
            throw new ProviderException('invalid_response');
        }
        $version = $status[2];
        if (!mb_check_encoding($version, 'UTF-8') || strlen($version) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $version) === 1) {
            throw new ProviderException('invalid_response');
        }
        if (preg_match('/^2\.[0-9]+(?:\.[0-9]+)?(?:\b|\s|-)/', $version) !== 1) {
            throw new ProviderException('unsupported_version');
        }
        return $version;
    }

    private function enabledPlugins(Connection $connection, string $endpoint, string $cookie): bool
    {
        $plugins = $this->rpc($connection, $endpoint, $cookie, 'core.get_enabled_plugins');
        if (!is_array($plugins) || !array_is_list($plugins)) {
            throw new ProviderException('invalid_response');
        }
        foreach ($plugins as $plugin) {
            if (!is_string($plugin)) {
                throw new ProviderException('invalid_response');
            }
        }
        return in_array('Label', $plugins, true);
    }

    private function hasSelectedLabel(Connection $connection): bool
    {
        return $connection->filterMode === 'selected'
            && array_filter($connection->categories, static fn (string $name): bool => $name !== '') !== [];
    }

    /** @param list<mixed> $params */
    private function rpc(Connection $connection, string $endpoint, string $cookie, string $method, array $params = []): mixed
    {
        $body = json_encode(['method' => $method, 'params' => $params, 'id' => 1], JSON_THROW_ON_ERROR);
        $request = new HttpRequest('POST', $endpoint, [
            'Content-Type: application/json', 'Accept: application/json', 'Cookie: _session_id=' . $cookie,
        ], $body, $connection->verifyTls);
        return $this->readResponse($this->transport->request($request), 1);
    }

    private function readResponse(\Mk\Framework\Downloads\HttpResponse $response, int $id): mixed
    {
        if (in_array($response->status, [401, 403], true)) {
            throw new ProviderException('authentication_failed');
        }
        if ($response->status !== 200) {
            throw new ProviderException();
        }
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_response');
        }
        if (!is_array($data) || ($data['id'] ?? null) !== $id) {
            throw new ProviderException('invalid_response');
        }
        $error = $data['error'] ?? null;
        if ($error !== null) {
            if (is_array($error) && ($error['code'] ?? null) === 1) {
                throw new ProviderException('authentication_failed');
            }
            throw new ProviderException('invalid_response');
        }
        if (!array_key_exists('result', $data)) {
            throw new ProviderException('invalid_response');
        }
        return $data['result'];
    }

    private function endpoint(string $url): string
    {
        $base = rtrim($url, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProviderException('invalid_response');
        }
        return $base . '/json';
    }

    /** @param array<string,mixed> $session */
    private function cachedCookie(array $session, string $endpoint): ?string
    {
        $cookie = $session['sid'] ?? null;
        $hash = $session['endpoint_hash'] ?? null;
        return is_string($cookie) && preg_match('/^[a-f0-9]{64}[0-9]{4}$/Di', $cookie) === 1
            && is_string($hash) && hash_equals(hash('sha256', $endpoint), $hash) ? $cookie : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function torrent(string $hash, array $row, bool $labelEnabled): array
    {
        if (preg_match('/^[a-f0-9]{40}$/Di', $hash) !== 1 || !is_string($row['name'] ?? null)
            || !is_string($row['state'] ?? null) || !mb_check_encoding($row['name'], 'UTF-8')) {
            throw new ProviderException('invalid_response');
        }
        $nativeState = strtolower($row['state']);
        $progress = $this->number($row['progress'] ?? null, 0, 100);
        $size = $this->integer($row['total_wanted'] ?? null, 0);
        $remaining = $this->integer($row['total_remaining'] ?? null, 0);
        $downloaded = $size !== null && $remaining !== null && $remaining <= $size ? $size - $remaining : null;
        $completedAt = $this->integer($row['completed_time'] ?? null, 0);
        $pieces = $this->integer($row['num_pieces'] ?? null, 0);
        $speed = $this->number($row['download_payload_rate'] ?? null, 0);
        $finished = $row['is_finished'] ?? null;
        if ($finished !== null && !is_bool($finished)) {
            throw new ProviderException('invalid_response');
        }
        $isComplete = ($finished === true || ($completedAt !== null && $completedAt > 0
            && $size !== null && $downloaded !== null && $downloaded === $size))
            && ($progress === null || $progress === 100.0);
        $state = match (true) {
            $nativeState === 'checking' => 'checking',
            $nativeState === 'error' => 'error',
            $nativeState === 'moving' || $nativeState === 'allocating' => 'processing',
            $isComplete && in_array($nativeState, ['seeding', 'paused', 'queued'], true) => 'completed',
            $nativeState === 'downloading' && $pieces === 0 => 'metadata',
            $nativeState === 'downloading' && $speed === 0.0 => 'stalled',
            $nativeState === 'downloading' => 'downloading',
            $nativeState === 'queued' => 'queued',
            $nativeState === 'paused' => 'paused',
            $nativeState === 'seeding' => 'completed',
            default => throw new ProviderException('invalid_response'),
        };
        $label = $labelEnabled ? $this->identity($row['label'] ?? '') : '';
        $eta = $this->integer($row['eta'] ?? null, 0);
        return [
            'source_id' => strtolower($hash), 'title' => mb_strcut($row['name'], 0, 1024, 'UTF-8'),
            'category' => $label, 'tags' => [], 'state' => $state, 'progress' => $progress,
            'size' => $size, 'downloaded' => $downloaded,
            'speed' => $speed,
            'eta' => $eta, 'position' => $this->integer($row['queue'] ?? null, 0),
            'completed_at' => $state === 'completed' && $completedAt !== null && $completedAt > 0 ? $completedAt : null,
        ];
    }

    private function identity(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 256
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ProviderException('invalid_response');
        }
        return $value;
    }

    private function number(mixed $value, float $minimum, ?float $maximum = null): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= $minimum && ($maximum === null || $number <= $maximum)
            && $number <= PHP_INT_MAX ? $number : null;
    }

    private function integer(mixed $value, int $minimum): ?int
    {
        if (is_int($value)) {
            return $value >= $minimum ? $value : null;
        }
        $number = $this->number($value, (float) $minimum);
        return $number !== null && $number < PHP_INT_MAX && floor($number) === $number ? (int) $number : null;
    }
}
