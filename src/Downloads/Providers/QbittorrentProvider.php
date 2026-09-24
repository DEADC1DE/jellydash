<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads\Providers;

use Closure;
use Mk\Framework\Downloads\CollectionBatch;
use Mk\Framework\Downloads\DownloaderHttpClient;
use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\HistoryRefresh;
use Mk\Framework\Downloads\Provider;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Integrations\Connection;

final class QbittorrentProvider implements Provider
{
    private const HISTORY_PAGE_SIZE = 20;
    private const HISTORY_MAX_ROWS = 100;
    private const HISTORY_TARGET = 20;
    private const MAX_BATCH_ROWS = 1000;
    private Closure $clock;

    public function __construct(
        private readonly DownloaderTransport $transport = new DownloaderHttpClient(),
        ?callable $clock = null,
    ) {
        $this->clock = Closure::fromCallable($clock ?? time(...));
    }

    public function testConnection(Connection $connection, array $credentials): array
    {
        $auth = $this->login($connection, $credentials);
        $responses = $this->transport->requestMany([
            $this->readRequest($connection, '/api/v2/app/version', $auth),
            $this->readRequest($connection, '/api/v2/app/webapiVersion', $auth),
            $this->readRequest($connection, '/api/v2/torrents/categories', $auth),
            $this->readRequest($connection, '/api/v2/torrents/tags', $auth),
        ]);
        foreach ($responses as $response) {
            $this->assertReadResponse($response);
        }
        $version = $this->plainText($responses[0], 64);
        $apiVersion = $this->plainText($responses[1], 64);
        if (version_compare($version, '4.6.0', '<') || version_compare($version, '6.0.0', '>=')) {
            throw new ProviderException('unsupported_version');
        }
        $categoryData = $this->json($responses[2]);
        $tagData = $this->json($responses[3]);

        $categories = [''];
        foreach ($categoryData as $key => $value) {
            $name = is_array($value) && is_string($value['name'] ?? null) ? $value['name'] : (is_string($key) ? $key : null);
            if ($name !== null) {
                $categories[] = $this->identity($name);
            }
        }
        $tags = [];
        foreach ($tagData as $tag) {
            if (is_string($tag)) {
                $tags[] = $this->identity($tag);
            }
        }
        $categories = array_values(array_unique($categories));
        $tags = array_values(array_unique($tags));
        sort($categories, SORT_STRING);
        sort($tags, SORT_STRING);
        $categories = array_slice($categories, 0, 250);
        $tags = array_slice($tags, 0, 250);

        return ['version' => $version . ' (Web API ' . $apiVersion . ')', 'categories' => $categories, 'tags' => $tags];
    }

    public function collect(Connection $connection, array $credentials, array $cursor = [], array $session = []): CollectionBatch
    {
        $base = $this->baseUrl($connection->url);
        $now = ($this->clock)();
        $auth = $this->sessionAuth($session, $base, $now);
        $loginUsed = false;
        $sessionExpiresAt = $auth === null ? $now + 1800 : $session['expires_at'];
        if ($auth === null) {
            $auth = $this->login($connection, $credentials);
            $loginUsed = true;
        }

        $buildInventoryRequests = fn (array $activeAuth): array => [
            $this->readRequest($connection, '/api/v2/transfer/info', $activeAuth),
            $this->readRequest($connection, '/api/v2/torrents/info', $activeAuth, [
                'filter' => 'all', 'sort' => 'progress', 'reverse' => 'false',
                'limit' => self::MAX_BATCH_ROWS + 1, 'offset' => 0,
            ]),
        ];

        $inventoryResponses = $this->transport->requestMany($buildInventoryRequests($auth));
        if ($this->authenticationRejected($inventoryResponses)) {
            if ($loginUsed) {
                throw new ProviderException('authentication_failed');
            }
            $auth = $this->login($connection, $credentials);
            $loginUsed = true;
            $sessionExpiresAt = $now + 1800;
            $inventoryResponses = $this->transport->requestMany($buildInventoryRequests($auth));
        }
        foreach ($inventoryResponses as $response) {
            $this->assertReadResponse($response);
        }

        $transfer = $this->json($inventoryResponses[0]);
        $inventory = $this->listJson($inventoryResponses[1]);
        $lastInventory = $inventory === [] ? null : $inventory[array_key_last($inventory)];
        $lastProgress = is_array($lastInventory) ? $this->number($lastInventory['progress'] ?? null, 0.0, 1.0) : null;
        $fullProgressBoundary = count($inventory) > self::MAX_BATCH_ROWS && $lastProgress === 1.0;
        // Version determines whether the live inventory can be considered complete.
        // It must run even when recent history is not due.
        $versionResponse = $fullProgressBoundary
            ? $this->transport->request($this->readRequest($connection, '/api/v2/app/version', $auth)) : null;
        if ($versionResponse !== null && $this->authenticationRejected([$versionResponse])) {
            if ($loginUsed) {
                throw new ProviderException('authentication_failed');
            }
            $auth = $this->login($connection, $credentials);
            $loginUsed = true;
            $sessionExpiresAt = $now + 1800;
            $versionResponse = $this->transport->request($this->readRequest($connection, '/api/v2/app/version', $auth));
        }
        $version = $versionResponse === null ? null : $this->plainText($versionResponse, 64);
        $items = [];
        $seenItems = [];
        $observedCompletions = [];
        foreach ($inventory as $row) {
            $item = $this->torrent($row);
            if ($item === null) {
                continue;
            }
            $completion = $this->completionFromRow($row, $item);
            if ($completion !== null && $connection->matches($completion)
                && (!isset($observedCompletions[$completion['source_id']])
                    || $completion['completed_at'] >= $observedCompletions[$completion['source_id']]['completed_at'])) {
                $observedCompletions[$completion['source_id']] = $completion;
            }
            if ($item['state'] === 'completed' || isset($seenItems[$item['source_id']])) {
                continue;
            }
            $seenItems[$item['source_id']] = true;
            $items[] = $item;
        }

        $inventoryComplete = count($inventory) <= self::MAX_BATCH_ROWS;
        if ($fullProgressBoundary && $version !== null && version_compare($version, '5.2.0', '>=')) {
            $buildCurrentRequests = fn (array $activeAuth): array => array_map(
                fn (string $filter): HttpRequest => $this->readRequest($connection, '/api/v2/torrents/info', $activeAuth, [
                    'filter' => $filter, 'limit' => self::MAX_BATCH_ROWS + 1, 'offset' => 0,
                ]),
                ['checking', 'moving', 'errored'],
            );
            $currentResponses = $this->transport->requestMany($buildCurrentRequests($auth));
            if ($this->authenticationRejected($currentResponses)) {
                if ($loginUsed) {
                    throw new ProviderException('authentication_failed');
                }
                $auth = $this->login($connection, $credentials);
                $loginUsed = true;
                $sessionExpiresAt = $now + 1800;
                $currentResponses = $this->transport->requestMany($buildCurrentRequests($auth));
            }
            $inventoryComplete = true;
            foreach ($currentResponses as $response) {
                $currentRows = $this->listJson($response);
                if (count($currentRows) > self::MAX_BATCH_ROWS) {
                    $inventoryComplete = false;
                }
                foreach ($currentRows as $row) {
                    $item = $this->torrent($row);
                    if ($item === null) {
                        continue;
                    }
                    $completion = $this->completionFromRow($row, $item);
                    if ($completion !== null && $connection->matches($completion)
                        && (!isset($observedCompletions[$completion['source_id']])
                            || $completion['completed_at'] >= $observedCompletions[$completion['source_id']]['completed_at'])) {
                        $observedCompletions[$completion['source_id']] = $completion;
                    }
                    if ($item['state'] === 'completed' || isset($seenItems[$item['source_id']])) {
                        continue;
                    }
                    $seenItems[$item['source_id']] = true;
                    $items[] = $item;
                }
            }
            $inventoryComplete = $inventoryComplete && count($items) <= self::MAX_BATCH_ROWS;
        }
        $completions = [];
        if (HistoryRefresh::due($cursor, $now)) {
            try {
                [$completions, $auth, $sessionExpiresAt] = $this->collectHistory(
                    $connection, $credentials, $auth, $loginUsed, $sessionExpiresAt, $now,
                );
                $cursor = ['history' => HistoryRefresh::success($cursor, $now)];
            } catch (ProviderException $exception) {
                $cursor = ['history' => HistoryRefresh::failure($cursor, $now, $exception->reason)];
            }
        } else {
            $cursor = ['history' => $cursor['history']];
        }
        foreach ($completions as $completion) {
            $id = $completion['source_id'];
            if (!isset($observedCompletions[$id]) || $completion['completed_at'] >= $observedCompletions[$id]['completed_at']) {
                $observedCompletions[$id] = $completion;
            }
        }
        $completions = array_values($observedCompletions);
        usort($completions, static fn (array $left, array $right): int =>
            ($right['completed_at'] <=> $left['completed_at']) ?: strcmp($left['source_id'], $right['source_id']));
        $completions = array_slice($completions, 0, self::HISTORY_MAX_ROWS);
        $items = array_slice($items, 0, self::MAX_BATCH_ROWS);
        $speed = $this->number($transfer['dl_info_speed'] ?? null, 0.0);

        return new CollectionBatch(
            $items,
            $completions,
            $speed,
            $cursor,
            $inventoryComplete,
            [
                'sid' => $auth['value'],
                'cookie_name' => $auth['name'],
                'expires_at' => $sessionExpiresAt,
                'endpoint_hash' => hash('sha256', $base),
            ],
            $cursor['history'],
        );
    }

    /** @param array{name:string,value:string} $auth
     * @return array{list<array<string,mixed>>,array{name:string,value:string},int}
     */
    private function collectHistory(Connection $connection, array $credentials, array $auth, bool $loginUsed, int $sessionExpiresAt, int $now): array
    {
        $completions = [];
        $seen = [];
        $deadline = microtime(true) + 5.0;
        for ($offset = 0; $offset < self::HISTORY_MAX_ROWS && count($completions) < self::HISTORY_TARGET; $offset += self::HISTORY_PAGE_SIZE) {
            $request = fn (array $activeAuth): HttpRequest => $this->readRequest($connection, '/api/v2/torrents/info', $activeAuth, [
                'filter' => 'completed', 'sort' => 'completion_on', 'reverse' => 'true',
                'limit' => self::HISTORY_PAGE_SIZE, 'offset' => $offset,
            ], $this->remainingHistoryMs($deadline));
            $response = $this->transport->request($request($auth));
            if ($this->authenticationRejected([$response])) {
                if ($loginUsed) {
                    throw new ProviderException('authentication_failed');
                }
                $auth = $this->login($connection, $credentials, $this->remainingHistoryMs($deadline));
                $loginUsed = true;
                $sessionExpiresAt = $now + 1800;
                $response = $this->transport->request($request($auth));
            }
            if (microtime(true) >= $deadline) {
                throw new ProviderException('timeout');
            }
            $rows = array_slice($this->listJson($response), 0, self::HISTORY_PAGE_SIZE);
            foreach ($rows as $row) {
                $item = $this->torrent($row);
                if ($item === null) {
                    continue;
                }
                $completion = $this->completionFromRow($row, $item);
                if ($completion === null || !$connection->matches($completion) || isset($seen[$completion['source_id']])) {
                    continue;
                }
                $seen[$completion['source_id']] = true;
                $completions[] = $completion;
                if (count($completions) >= self::HISTORY_TARGET) {
                    break;
                }
            }
            if (count($rows) < self::HISTORY_PAGE_SIZE) {
                break;
            }
        }
        return [$completions, $auth, $sessionExpiresAt];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $item @return array<string,mixed>|null */
    private function completionFromRow(array $row, array $item): ?array
    {
        if (!$this->isAuthoritativeCompletion($row, $item)) {
            return null;
        }
        $item['state'] = 'completed';
        $item['progress'] = 100.0;
        $item['speed'] = null;
        $item['eta'] = null;
        $item['position'] = null;
        $item['completed_at'] = $this->integer($row['completion_on'] ?? null, 1);
        return $item;
    }

    private function remainingHistoryMs(float $deadline): int
    {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            throw new ProviderException('timeout');
        }
        return min(5000, $remainingMs);
    }

    /** @param array{username:string,secret:string} $credentials @return array{name:string,value:string} */
    private function login(Connection $connection, array $credentials, int $timeoutMs = 5000): array
    {
        $base = $this->baseUrl($connection->url);
        $body = http_build_query(['username' => $credentials['username'], 'password' => $credentials['secret']], '', '&', PHP_QUERY_RFC3986);
        $response = $this->transport->request(new HttpRequest(
            'POST',
            $base . '/api/v2/auth/login',
            ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $this->origin($base), 'Referer: ' . $base . '/'],
            $body,
            $connection->verifyTls,
            timeoutMs: $timeoutMs,
        ));
        $accepted = ($response->status === 200 && trim($response->body) === 'Ok.')
            || ($response->status === 204 && $response->body === '');
        if (!$accepted) {
            throw new ProviderException('authentication_failed');
        }
        foreach ($response->header('set-cookie') as $cookie) {
            if (preg_match('/(?:^|;\s*)(SID|QBT_SID(?:_[0-9]{1,5})?)=([^;]{8,256})(?:;|$)/', $cookie, $match) === 1
                && $this->validCookieName($match[1])
                && $this->validSid($match[2])) {
                return ['name' => $match[1], 'value' => $match[2]];
            }
        }
        if ($response->status === 204) {
            return ['name' => '', 'value' => ''];
        }
        throw new ProviderException('authentication_failed');
    }

    /** @param array{name:string,value:string} $auth @param array<string,int|string> $query */
    private function readRequest(Connection $connection, string $path, array $auth, array $query = [], int $timeoutMs = 5000): HttpRequest
    {
        $base = $this->baseUrl($connection->url);
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [
            'Origin: ' . $this->origin($base),
            'Referer: ' . $base . '/',
            'Accept: application/json, text/plain',
        ];
        if ($auth['name'] !== '') {
            array_unshift($headers, 'Cookie: ' . $auth['name'] . '=' . $auth['value']);
        }
        return new HttpRequest('GET', $url, $headers, verifyTls: $connection->verifyTls, timeoutMs: $timeoutMs);
    }

    /** @param list<HttpResponse> $responses */
    private function authenticationRejected(array $responses): bool
    {
        foreach ($responses as $response) {
            if (in_array($response->status, [401, 403], true)) {
                return true;
            }
        }
        return false;
    }

    private function assertReadResponse(HttpResponse $response): void
    {
        if (in_array($response->status, [401, 403], true)) {
            throw new ProviderException('authentication_failed');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ProviderException();
        }
    }

    /** @return array<array-key,mixed> */
    private function json(HttpResponse $response): array
    {
        $this->assertReadResponse($response);
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_response');
        }
        if (!is_array($data)) {
            throw new ProviderException('invalid_response');
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function listJson(HttpResponse $response): array
    {
        $data = $this->json($response);
        if (!array_is_list($data)) {
            throw new ProviderException('invalid_response');
        }
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new ProviderException('invalid_response');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function plainText(HttpResponse $response, int $limit): string
    {
        $this->assertReadResponse($response);
        $value = trim($response->body);
        if ($value === '' || strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ProviderException('invalid_response');
        }
        return $value[0] === 'v' ? substr($value, 1) : $value;
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderException('invalid_response');
        }
        return $url;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[')
            ? '[' . $parts['host'] . ']'
            : $parts['host'];
        $origin = $parts['scheme'] . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /** @param array<string,mixed> $session @return array{name:string,value:string}|null */
    private function sessionAuth(array $session, string $base, int $now): ?array
    {
        $sid = $session['sid'] ?? null;
        $cookieName = $session['cookie_name'] ?? (is_string($sid) && $sid !== '' ? 'SID' : null);
        $expiresAt = $session['expires_at'] ?? null;
        $endpointHash = $session['endpoint_hash'] ?? null;
        $authValid = is_string($sid) && is_string($cookieName)
            && (($sid === '' && $cookieName === '') || ($this->validSid($sid) && $this->validCookieName($cookieName)));
        if (!$authValid
            || !is_int($expiresAt) || $expiresAt <= $now || $expiresAt > $now + 1800
            || !is_string($endpointHash) || !hash_equals(hash('sha256', $base), $endpointHash)) {
            return null;
        }
        return ['name' => $cookieName, 'value' => $sid];
    }

    private function validCookieName(string $name): bool
    {
        return preg_match('/^(?:SID|QBT_SID(?:_[0-9]{1,5})?)$/D', $name) === 1;
    }

    private function validSid(string $sid): bool
    {
        return strlen($sid) >= 8 && strlen($sid) <= 256
            && preg_match('~^[A-Za-z0-9+/_-]+={0,2}$~D', $sid) === 1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function torrent(array $row): ?array
    {
        $hash = $row['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[A-Fa-f0-9]{4,128}$/', $hash) !== 1) {
            return null;
        }
        $size = $this->integer($row['size'] ?? $row['total_size'] ?? null, 0);
        $downloaded = $this->integer($row['completed'] ?? null, 0);
        if ($size !== null && $downloaded !== null) {
            $downloaded = min($size, $downloaded);
        }
        $tags = [];
        if (is_string($row['tags'] ?? null)) {
            foreach (explode(',', $row['tags']) as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $tags[] = $this->identity($tag);
                }
            }
        }
        $tags = array_values(array_unique($tags));
        $eta = $this->integer($row['eta'] ?? null, 0);
        if ($eta !== null && $eta >= 8640000) {
            $eta = null;
        }

        return [
            'source_id' => strtolower($hash),
            'title' => $this->text(is_string($row['name'] ?? null) ? $row['name'] : '', 1024),
            'category' => $this->identity(is_string($row['category'] ?? null) ? $row['category'] : ''),
            'tags' => $tags,
            'state' => $this->state($row['state'] ?? null),
            'progress' => $this->fractionPercent($row['progress'] ?? null),
            'size' => $size,
            'downloaded' => $downloaded,
            'speed' => $this->number($row['dlspeed'] ?? null, 0.0),
            'eta' => $eta,
            'position' => $this->integer($row['priority'] ?? null, 0),
            'completed_at' => null,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $item */
    private function isAuthoritativeCompletion(array $row, array $item): bool
    {
        return ($this->integer($row['completion_on'] ?? null, 1) ?? 0) > 0
            && ($item['progress'] ?? null) === 100.0;
    }

    private function state(mixed $value): string
    {
        $state = strtolower(is_string($value) ? $value : '');
        return match ($state) {
            'downloading', 'forceddl' => 'downloading',
            'metadl', 'forcedmetadl' => 'metadata',
            'stalleddl' => 'stalled',
            'queueddl' => 'queued',
            'pauseddl', 'stoppeddl' => 'paused',
            'checkingdl', 'checkingup', 'checkingresumedata' => 'checking',
            'moving', 'allocating' => 'processing',
            'uploading', 'stalledup', 'queuedup', 'pausedup', 'stoppedup', 'forcedup' => 'completed',
            'error', 'missingfiles', 'unknown' => 'error',
            default => 'error',
        };
    }

    private function fractionPercent(mixed $value): ?float
    {
        $number = $this->number($value, 0.0, 1.0);
        return $number === null ? null : $number * 100;
    }

    private function number(mixed $value, ?float $minimum = null, ?float $maximum = null): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || ($minimum !== null && $number < $minimum)
            || ($maximum !== null && $number > $maximum) || $number > PHP_INT_MAX) {
            return null;
        }
        return $number;
    }

    private function integer(mixed $value, ?int $minimum = null): ?int
    {
        $number = $this->number($value, $minimum === null ? null : (float) $minimum);
        return $number === null ? null : (int) $number;
    }

    private function text(string $value, int $limit): string
    {
        if (preg_match('//u', $value) !== 1) {
            return '';
        }
        if (strlen($value) <= $limit) {
            return $value;
        }
        $value = substr($value, 0, $limit);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function identity(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 256
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProviderException('invalid_response');
        }

        return $value;
    }
}
