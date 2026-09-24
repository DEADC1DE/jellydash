<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads\Providers;

use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloaderHttpClient;
use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\HistoryRefresh;
use Mk\Framework\Downloads\JsonRpcHistoryStream;
use Mk\Framework\Downloads\Provider;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Integrations\Connection;

/** Read-only NZBGet JSON-RPC adapter. */
final class NzbgetProvider implements Provider
{
    private const MAX_CURRENT = 1000;
    private const MAX_RECENT = 100;
    private const UINT32 = 4294967296;

    public function __construct(private readonly DownloaderTransport $transport = new DownloaderHttpClient())
    {
    }

    public function testConnection(Connection $connection, array $credentials): array
    {
        $version = $this->rpc($connection, $credentials, 'version');
        if (!is_string($version) || !mb_check_encoding($version, 'UTF-8') || strlen($version) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $version) === 1) {
            throw new ProviderException('invalid_response');
        }
        if (preg_match('/^([0-9]+)(?:\.|\b)/', $version, $match) !== 1
            || (int) $match[1] < 21 || (int) $match[1] > 26) {
            throw new ProviderException('unsupported_version');
        }
        $config = $this->rows($this->rpc($connection, $credentials, 'config'));
        $categories = [''];
        foreach ($config as $row) {
            $name = $row['Name'] ?? null;
            if (!is_string($name) || preg_match('/^Category[1-9][0-9]*\.Name$/D', $name) !== 1) {
                continue;
            }
            $category = $this->identity($row['Value'] ?? null);
            if ($category !== '') {
                $categories[] = $category;
            }
        }
        $categories = array_values(array_unique($categories));
        sort($categories, SORT_STRING);
        return ['version' => $version, 'categories' => array_slice($categories, 0, 250), 'tags' => []];
    }

    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
    {
        $status = $this->rpc($connection, $credentials, 'status');
        if (!is_array($status) || array_is_list($status) && $status !== []) {
            throw new ProviderException('invalid_response');
        }
        $queue = $this->rows($this->rpc($connection, $credentials, 'listgroups', [0]));
        $items = [];
        $activeIds = [];
        $currentCount = 0;
        foreach ($queue as $position => $row) {
            if (!in_array($row['Kind'] ?? null, ['NZB', 'URL'], true)) {
                continue;
            }
            $item = $this->queueItem($row, $position);
            if (isset($activeIds[$item['source_id']])) {
                throw new ProviderException('invalid_response');
            }
            $activeIds[$item['source_id']] = true;
            ++$currentCount;
            if (count($items) < self::MAX_CURRENT) {
                $items[] = $item;
            }
        }
        $recent = [];
        $history = is_array($cursor['history'] ?? null) ? $cursor['history'] : [];
        if (HistoryRefresh::due($cursor, time())) {
            try {
                $recent = $this->recentHistory($connection, $credentials, $activeIds);
                $history = HistoryRefresh::success($cursor, time());
            } catch (ProviderException $error) {
                $history = HistoryRefresh::failure($cursor, time(), $error->reason);
            }
        }
        $cursor['history'] = $history;
        $speed = $this->bytes($status, 'DownloadRate', false);
        return new CollectionBatch($items, $recent,
            $speed === null ? null : (float) $speed, $cursor, $currentCount <= self::MAX_CURRENT,
            history: $history);
    }

    /** @param array{username:string,secret:string} $credentials @param array<string,true> $activeIds
     * @return list<array<string,mixed>> */
    private function recentHistory(Connection $connection, array $credentials, array $activeIds): array
    {
        $recent = [];
        $oldestId = null;
        $stream = new JsonRpcHistoryStream(function (array $row) use ($connection, $activeIds, &$recent, &$oldestId): void {
            if (!in_array($row['Kind'] ?? null, ['NZB', 'URL'], true)) {
                return;
            }
            $item = $this->historyItem($row);
            if ($item === null || isset($activeIds[$item['source_id']]) || !$connection->matches($item)) {
                return;
            }
            $id = $item['source_id'];
            if (isset($recent[$id])) {
                if (($item['completed_at'] ?? 0) <= ($recent[$id]['completed_at'] ?? 0)) {
                    return;
                }
            } elseif (count($recent) >= self::MAX_RECENT) {
                if ($oldestId === null || ($item['completed_at'] ?? 0) <= ($recent[$oldestId]['completed_at'] ?? 0)) {
                    return;
                }
                unset($recent[$oldestId]);
            }
            $recent[$id] = $item;
            $oldestId = null;
            foreach ($recent as $key => $candidate) {
                if ($oldestId === null || ($candidate['completed_at'] ?? 0) < ($recent[$oldestId]['completed_at'] ?? 0)) {
                    $oldestId = $key;
                }
            }
        });
        $body = json_encode(['method' => 'history', 'params' => [false], 'id' => 1], JSON_THROW_ON_ERROR);
        $request = new HttpRequest('POST', $this->endpoint($connection->url), [
            'Content-Type: application/json', 'Accept: application/json',
            'Authorization: Basic ' . base64_encode($credentials['username'] . ':' . $credentials['secret']),
        ], $body, $connection->verifyTls, $stream);
        $response = $this->transport->request($request);
        // In-memory transports can supply an ordinary response for the same bounded reader.
        if ($response->status === 200 && $stream->bytesReceived() === 0) {
            $stream->write($response->body);
            $response = new HttpResponse(200, $stream->finish(), $response->headers);
        }
        $this->readResponse($response);
        usort($recent, static fn (array $a, array $b): int => ($b['completed_at'] ?? 0) <=> ($a['completed_at'] ?? 0));
        return $recent;
    }

    /** @param array{username:string,secret:string} $credentials @param list<mixed> $params */
    private function rpc(Connection $connection, array $credentials, string $method, array $params = []): mixed
    {
        $body = json_encode(['method' => $method, 'params' => $params, 'id' => 1], JSON_THROW_ON_ERROR);
        $request = new HttpRequest('POST', $this->endpoint($connection->url), [
            'Content-Type: application/json', 'Accept: application/json',
            'Authorization: Basic ' . base64_encode($credentials['username'] . ':' . $credentials['secret']),
        ], $body, $connection->verifyTls);
        $response = $this->transport->request($request);
        return $this->readResponse($response);
    }

    private function readResponse(HttpResponse $response): mixed
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
        if (!is_array($data) || ($data['id'] ?? null) !== 1 || ($data['error'] ?? null) !== null
            || !array_key_exists('result', $data)) {
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
        return $base . '/jsonrpc';
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $result): array
    {
        if (!is_array($result) || !array_is_list($result)) {
            throw new ProviderException('invalid_response');
        }
        foreach ($result as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ProviderException('invalid_response');
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function queueItem(array $row, int $position): array
    {
        $id = $this->id($row['NZBID'] ?? null);
        $state = match ($row['Status'] ?? null) {
            'DOWNLOADING' => 'downloading',
            'QUEUED', 'PP_QUEUED', 'QS_QUEUED' => 'queued',
            'PAUSED' => 'paused',
            'FETCHING' => 'metadata',
            'LOADING_PARS', 'VERIFYING_SOURCES', 'REPAIRING', 'VERIFYING_REPAIRED',
            'RENAMING', 'UNPACKING', 'MOVING', 'POST_UNPACK_RENAMING',
            'POST_DOWNLOAD_RENAMING', 'EXECUTING_SCRIPT', 'PP_FINISHED', 'QS_EXECUTING' => 'processing',
            default => throw new ProviderException('invalid_response'),
        };
        $size = $this->bytes($row, 'FileSize', true);
        $remaining = $this->bytes($row, 'RemainingSize', true);
        $downloaded = $size !== null && $remaining !== null && $remaining <= $size ? $size - $remaining : null;
        $progress = $size !== null && $size > 0 && $downloaded !== null ? $downloaded * 100.0 / $size : null;
        if ($state === 'processing') {
            $stage = $this->integer($row['PostStageProgress'] ?? null, 0, 1000);
            if ($stage !== null) {
                $progress = $stage / 10.0;
            }
        }
        return $this->item($id, $row['NZBName'] ?? null, $row['Category'] ?? null, $state,
            $progress, $size, $downloaded, null, null, $position, null);
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function historyItem(array $row): ?array
    {
        $status = $row['Status'] ?? null;
        if (!is_string($status) || str_starts_with($status, 'DELETED/')) {
            return null;
        }
        $state = match (true) {
            str_starts_with($status, 'SUCCESS/') => 'completed',
            str_starts_with($status, 'FAILURE/') => 'failed',
            str_starts_with($status, 'WARNING/') => 'warning',
            default => null,
        };
        if ($state === null) {
            return null;
        }
        $size = $this->bytes($row, 'FileSize', true);
        $completedAt = $this->integer($row['HistoryTime'] ?? null, 1);
        return $this->item($this->id($row['NZBID'] ?? null), $row['Name'] ?? $row['NZBName'] ?? null,
            $row['Category'] ?? null, $state, $state === 'completed' ? 100.0 : null,
            $size, $state === 'completed' ? $size : null, null, null, null, $completedAt);
    }

    /** @return array<string,mixed> */
    private function item(string $id, mixed $title, mixed $category, string $state, ?float $progress,
        ?int $size, ?int $downloaded, ?float $speed, ?int $eta, ?int $position, ?int $completedAt): array
    {
        if (!is_string($title) || !mb_check_encoding($title, 'UTF-8')) {
            throw new ProviderException('invalid_response');
        }
        return [
            'source_id' => $id, 'title' => mb_strcut($title, 0, 1024, 'UTF-8'),
            'category' => $this->identity($category), 'tags' => [], 'state' => $state,
            'progress' => $progress, 'size' => $size, 'downloaded' => $downloaded,
            'speed' => $speed, 'eta' => $eta, 'position' => $position, 'completed_at' => $completedAt,
        ];
    }

    private function id(mixed $value): string
    {
        $id = $this->integer($value, 0);
        if ($id === null) {
            throw new ProviderException('invalid_response');
        }
        return (string) $id;
    }

    private function identity(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 256
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ProviderException('invalid_response');
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function bytes(array $row, string $field, bool $allowMb): ?int
    {
        $hiKey = $field . 'Hi';
        $loKey = $field . 'Lo';
        if (array_key_exists($hiKey, $row) || array_key_exists($loKey, $row)) {
            $hi = $this->integer($row[$hiKey] ?? null, 0, self::UINT32 - 1);
            $lo = $this->integer($row[$loKey] ?? null, 0, self::UINT32 - 1);
            if ($hi === null || $lo === null || $hi > intdiv(PHP_INT_MAX - $lo, self::UINT32)) {
                return null;
            }
            return $hi * self::UINT32 + $lo;
        }
        if (!$allowMb) {
            return $this->integer($row[$field] ?? null, 0);
        }
        $mb = $this->integer($row[$field . 'MB'] ?? null, 0);
        return $mb !== null && $mb <= intdiv(PHP_INT_MAX, 1048576) ? $mb * 1048576 : null;
    }

    private function integer(mixed $value, int $minimum, ?int $maximum = null): ?int
    {
        return is_int($value) && $value >= $minimum && ($maximum === null || $value <= $maximum) ? $value : null;
    }
}
