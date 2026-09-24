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

/** Transmission 4's classic read-only RPC protocol, retained by Transmission 4.1. */
final class TransmissionProvider implements Provider
{
    private const MAX_CURRENT = 1000;
    private const MAX_RECENT = 100;
    private const TORRENT_FIELDS = [
        'hashString', 'name', 'status', 'percentDone', 'sizeWhenDone', 'leftUntilDone',
        'rateDownload', 'eta', 'queuePosition', 'doneDate', 'labels', 'error',
        'errorString', 'isStalled', 'isFinished',
    ];

    public function __construct(private readonly DownloaderTransport $transport = new DownloaderHttpClient())
    {
    }

    public function testConnection(Connection $connection, array $credentials): array
    {
        $endpoint = $this->endpoint($connection->url);
        $sid = null;
        $session = $this->rpc($connection, $credentials, $endpoint, $sid, 'session-get', ['fields' => ['version', 'rpc-version']]);
        $version = $session['version'] ?? null;
        $rpcVersion = $this->integer($session['rpc-version'] ?? null, 0);
        if (!is_string($version) || !mb_check_encoding($version, 'UTF-8') || strlen($version) > 80 || preg_match('/[\x00-\x1f\x7f]/', $version) === 1) {
            throw new ProviderException('invalid_response');
        }
        if (preg_match('/^4\.[0-9]+(?:\.[0-9]+)?(?:\b|\s|-)/', $version) !== 1 || $rpcVersion === null || $rpcVersion < 17) {
            throw new ProviderException('unsupported_version');
        }
        $torrents = $this->torrentRows($this->rpc($connection, $credentials, $endpoint, $sid, 'torrent-get', ['fields' => ['labels']]));
        $labels = [''];
        foreach ($torrents as $row) {
            foreach ($this->labels($row['labels'] ?? null) as $label) {
                $labels[] = $label;
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);

        return ['version' => $version, 'categories' => array_slice($labels, 0, 250), 'tags' => []];
    }

    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
    {
        $endpoint = $this->endpoint($connection->url);
        $sid = $this->cachedSession($session, $endpoint);
        $stats = $this->rpc($connection, $credentials, $endpoint, $sid, 'session-stats');
        $rows = $this->torrentRows($this->rpc($connection, $credentials, $endpoint, $sid, 'torrent-get', ['fields' => self::TORRENT_FIELDS]));
        $items = [];
        $completions = [];
        $seen = [];
        $currentCount = 0;
        foreach ($rows as $row) {
            $item = $this->torrent($row);
            if (isset($seen[$item['source_id']])) {
                throw new ProviderException('invalid_response');
            }
            $seen[$item['source_id']] = true;
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
        $speed = $this->number($stats['downloadSpeed'] ?? null, 0);
        return new CollectionBatch(
            $items,
            array_slice($completions, 0, self::MAX_RECENT),
            $speed,
            [],
            $currentCount <= self::MAX_CURRENT,
            $sid === null ? [] : ['sid' => $sid, 'endpoint_hash' => hash('sha256', $endpoint)],
        );
    }

    /** @param array{username:string,secret:string} $credentials @param array<string,mixed> $arguments @return array<string,mixed> */
    private function rpc(Connection $connection, array $credentials, string $endpoint, ?string &$sid, string $method, array $arguments = []): array
    {
        $body = json_encode(['method' => $method, 'arguments' => (object) $arguments], JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if ($credentials['username'] !== '' || $credentials['secret'] !== '') {
                $headers[] = 'Authorization: Basic ' . base64_encode($credentials['username'] . ':' . $credentials['secret']);
            }
            if ($sid !== null) {
                $headers[] = 'X-Transmission-Session-Id: ' . $sid;
            }
            $response = $this->transport->request(new HttpRequest('POST', $endpoint, $headers, $body, $connection->verifyTls));
            if ($response->status === 409) {
                $received = $response->header('x-transmission-session-id');
                if (count($received) !== 1 || !$this->validSid($received[0]) || $received[0] === $sid) {
                    throw new ProviderException('invalid_response');
                }
                $sid = $received[0];
                continue;
            }
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
            if (!is_array($data) || ($data['result'] ?? null) !== 'success' || !is_array($data['arguments'] ?? null)) {
                throw new ProviderException('invalid_response');
            }
            return $data['arguments'];
        }
        throw new ProviderException('invalid_response');
    }

    private function endpoint(string $url): string
    {
        $base = rtrim($url, '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProviderException('invalid_response');
        }
        return $base . '/transmission/rpc';
    }

    /** @param array<string,mixed> $session */
    private function cachedSession(array $session, string $endpoint): ?string
    {
        $sid = $session['sid'] ?? null;
        $hash = $session['endpoint_hash'] ?? null;
        return is_string($sid) && $this->validSid($sid) && is_string($hash)
            && hash_equals(hash('sha256', $endpoint), $hash) ? $sid : null;
    }

    private function validSid(string $sid): bool
    {
        return strlen($sid) >= 1 && strlen($sid) <= 256 && preg_match('/^[\x21-\x7e]+$/D', $sid) === 1;
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    private function torrentRows(array $data): array
    {
        $rows = $data['torrents'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new ProviderException('invalid_response');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ProviderException('invalid_response');
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function torrent(array $row): array
    {
        $hash = $row['hashString'] ?? null;
        $status = $this->integer($row['status'] ?? null, 0);
        if (!is_string($hash) || preg_match('/^[a-fA-F0-9]{40}$/D', $hash) !== 1 || $status === null || $status > 6) {
            throw new ProviderException('invalid_response');
        }
        $labels = $this->labels($row['labels'] ?? null);
        $size = $this->integer($row['sizeWhenDone'] ?? null, 0);
        $left = $this->integer($row['leftUntilDone'] ?? null, 0);
        $downloaded = $size !== null && $left !== null && $left <= $size ? $size - $left : null;
        $fraction = $this->number($row['percentDone'] ?? null, 0, 1);
        $doneDate = $this->integer($row['doneDate'] ?? null, 0);
        $finished = $row['isFinished'] ?? null;
        if ($finished !== null && !is_bool($finished)) {
            throw new ProviderException('invalid_response');
        }
        $complete = in_array($status, [5, 6], true) || (($left === 0 || $fraction === 1.0 || $finished === true)
            && $doneDate !== null && $doneDate > 0);
        $error = $this->integer($row['error'] ?? null, 0);
        $state = match (true) {
            $status === 1 || $status === 2 => 'checking',
            $error === 3 => 'error',
            $complete => 'completed',
            $status === 3 => 'queued',
            $status === 0 => 'paused',
            $status === 4 && ($row['isStalled'] ?? false) === true => 'stalled',
            default => 'downloading',
        };
        $eta = $this->integer($row['eta'] ?? null, 0);
        $title = $row['name'] ?? null;
        if (!is_string($title) || !mb_check_encoding($title, 'UTF-8')) {
            throw new ProviderException('invalid_response');
        }
        $title = mb_strcut($title, 0, 1024, 'UTF-8');
        return [
            'source_id' => strtolower($hash), 'title' => $title,
            'category' => $labels[0] ?? '', 'tags' => $labels,
            'state' => $state, 'progress' => $fraction === null ? null : $fraction * 100,
            'size' => $size, 'downloaded' => $downloaded,
            'speed' => $this->number($row['rateDownload'] ?? null, 0),
            'eta' => $eta, 'position' => $this->integer($row['queuePosition'] ?? null, 0),
            'completed_at' => $state === 'completed' && $doneDate !== null && $doneDate > 0 ? $doneDate : null,
        ];
    }

    /** @return list<string> */
    private function labels(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProviderException('invalid_response');
        }
        $labels = [];
        foreach ($value as $label) {
            if (!is_string($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > 256
                || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
                throw new ProviderException('invalid_response');
            }
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return array_values(array_unique($labels));
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
