<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

final class DownloaderHttpClient implements DownloaderTransport
{
    private const MAX_RESPONSE_BYTES = 2097152;
    private const MAX_CONCURRENT = 2;

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->requestMany([$request])[0];
    }

    public function requestMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $responses = [];
        foreach (array_chunk($requests, self::MAX_CONCURRENT) as $chunk) {
            foreach ($this->runChunk($chunk) as $response) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /** @param list<HttpRequest> $requests @return list<HttpResponse> */
    private function runChunk(array $requests): array
    {
        $multi = curl_multi_init();
        /** @var array<int,array{handle:\CurlHandle,body:string,headers:array<string,list<string>>,headerBytes:int,overflow:bool,streamError:?ProviderException,stream:?JsonRpcHistoryStream}> $states */
        $states = [];

        try {
            foreach ($requests as $index => $request) {
                $this->validateRequest($request);
                $handle = curl_init();
                if (!$handle instanceof \CurlHandle) {
                    throw new ProviderException();
                }
                $states[$index] = ['handle' => $handle, 'body' => '', 'headers' => [], 'headerBytes' => 0, 'overflow' => false, 'streamError' => null, 'stream' => $request->historyStream];
                $this->configure($handle, $request, $states[$index]);
                if (CURLM_OK !== curl_multi_add_handle($multi, $handle)) {
                    throw new ProviderException();
                }
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    throw new ProviderException();
                }
                if ($running > 0) {
                    $selected = curl_multi_select($multi, 0.25);
                    if ($selected === -1) {
                        usleep(1000);
                    }
                }
            } while ($running > 0);

            $multiErrors = [];
            while (($info = curl_multi_info_read($multi)) !== false) {
                if (($info['handle'] ?? null) instanceof \CurlHandle && is_int($info['result'] ?? null)) {
                    $multiErrors[spl_object_id($info['handle'])] = $info['result'];
                }
            }
            $responses = [];
            foreach ($states as $state) {
                $error = $multiErrors[spl_object_id($state['handle'])] ?? curl_errno($state['handle']);
                if ($state['streamError'] !== null) {
                    throw $state['streamError'];
                }
                if ($state['overflow']) {
                    throw new ProviderException('response_too_large');
                }
                if ($error === CURLE_OPERATION_TIMEDOUT) {
                    throw new ProviderException('timeout');
                }
                if ($error !== CURLE_OK) {
                    throw new ProviderException();
                }
                $responses[] = new HttpResponse(
                    (int) curl_getinfo($state['handle'], CURLINFO_RESPONSE_CODE),
                    $state['stream'] !== null && (int) curl_getinfo($state['handle'], CURLINFO_RESPONSE_CODE) === 200
                        ? $state['stream']->finish() : $state['body'],
                    $state['headers'],
                );
            }

            return $responses;
        } finally {
            foreach ($states as $state) {
                curl_multi_remove_handle($multi, $state['handle']);
                curl_close($state['handle']);
            }
            curl_multi_close($multi);
        }
    }

    /** @param array{handle:\CurlHandle,body:string,headers:array<string,list<string>>,headerBytes:int,overflow:bool,streamError:?ProviderException,stream:?JsonRpcHistoryStream} $state */
    private function configure(\CurlHandle $handle, HttpRequest $request, array &$state): void
    {
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $request->headers,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $request->timeoutMs),
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $request->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $request->verifyTls ? 2 : 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => 'Jellydash/Downloads',
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$state): int {
                if ($state['stream'] !== null && (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200) {
                    try {
                        $state['stream']->write($chunk);
                    } catch (ProviderException $error) {
                        $state['streamError'] = $error;
                        return 0;
                    }
                    return strlen($chunk);
                }
                if (strlen($state['body']) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $state['overflow'] = true;
                    return 0;
                }
                $state['body'] .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $unused, string $line) use (&$state): int {
                $state['headerBytes'] += strlen($line);
                if ($state['headerBytes'] > 65536) {
                    $state['overflow'] = true;
                    return 0;
                }
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    if ($name !== '') {
                        $state['headers'][$name][] = trim(substr($line, $separator + 1));
                    }
                }
                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if (!curl_setopt_array($handle, $options)) {
            throw new ProviderException();
        }
    }

    private function validateRequest(HttpRequest $request): void
    {
        if ($request->timeoutMs < 1 || $request->timeoutMs > 5000
            || !in_array($request->method, ['GET', 'POST'], true)
            || preg_match('/[\x00-\x1f\x7f]/', $request->url) === 1) {
            throw new ProviderException('invalid_response');
        }
        $parts = parse_url($request->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ProviderException('invalid_response');
        }
        foreach ($request->headers as $header) {
            if (preg_match('/[\r\n]/', $header) === 1) {
                throw new ProviderException('invalid_response');
            }
        }
        $this->validateOperation($request, (string) ($parts['path'] ?? ''));
        if ($request->historyStream !== null) {
            $body = json_decode($request->body ?? '', true);
            if ($request->method !== 'POST' || !str_ends_with((string) ($parts['path'] ?? ''), '/jsonrpc')
                || !is_array($body) || ($body['method'] ?? null) !== 'history' || ($body['params'] ?? null) !== [false]) {
                throw new ProviderException('invalid_response');
            }
        }
    }

    private function validateOperation(HttpRequest $request, string $path): void
    {
        $rpcMethods = match (true) {
            str_ends_with($path, '/transmission/rpc') => ['session-get', 'session-stats', 'torrent-get'],
            str_ends_with($path, '/json') => [
                'auth.login', 'web.connected', 'web.get_hosts', 'web.connect', 'web.get_host_status',
                'system.listMethods', 'core.get_torrents_status', 'core.get_session_status',
                'core.get_enabled_plugins', 'label.get_labels',
            ],
            str_ends_with($path, '/jsonrpc') => ['version', 'status', 'listgroups', 'history', 'config'],
            default => null,
        };
        if ($rpcMethods !== null) {
            if ($request->method !== 'POST' || parse_url($request->url, PHP_URL_QUERY) !== null
                || $request->body === null || strlen($request->body) > 131072) {
                throw new ProviderException('invalid_response');
            }
            try {
                $body = json_decode($request->body, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ProviderException('invalid_response');
            }
            if (!$body instanceof \stdClass || !in_array($body->method ?? null, $rpcMethods, true)
                || array_diff(array_keys(get_object_vars($body)), ['method', 'arguments', 'tag', 'params', 'id', 'jsonrpc']) !== []) {
                throw new ProviderException('invalid_response');
            }
            return;
        }

        if (str_ends_with($path, '/api')) {
            if ($request->method !== 'GET') {
                throw new ProviderException('invalid_response');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $allowedKeys = ['mode', 'output', 'apikey', 'start', 'limit', 'archive', 'status', 'last_history_update'];
            if (array_diff(array_keys($query), $allowedKeys) !== []
                || !in_array($query['mode'] ?? '', ['queue', 'history', 'get_cats'], true)) {
                throw new ProviderException('invalid_response');
            }
            return;
        }

        $suffix = preg_replace('#^.*(?=/api/v2/)#', '', $path);
        $readPaths = [
            '/api/v2/app/version', '/api/v2/app/webapiVersion', '/api/v2/transfer/info',
            '/api/v2/torrents/info', '/api/v2/torrents/categories', '/api/v2/torrents/tags',
        ];
        if ($request->method === 'GET' && in_array($suffix, $readPaths, true)) {
            if ($suffix === '/api/v2/torrents/info') {
                parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
                if (array_diff(array_keys($query), ['filter', 'sort', 'reverse', 'limit', 'offset']) !== []) {
                    throw new ProviderException('invalid_response');
                }
            }
            return;
        }
        if ($request->method === 'POST' && $suffix === '/api/v2/auth/login') {
            return;
        }
        throw new ProviderException('invalid_response');
    }
}
