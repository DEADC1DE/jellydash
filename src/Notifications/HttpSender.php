<?php

declare(strict_types=1);

namespace Mk\Framework\Notifications;

/**
 * Minimal HTTP POST helper for the notification channels. Short timeouts so a
 * slow notification service can never stall the poller.
 */
final class HttpSender
{
    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string}
     */
    public static function postForm(string $url, array $fields): array
    {
        return self::post($url, http_build_query($fields), 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function postJson(string $url, array $payload, array $headers = []): array
    {
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 0, 'body' => 'JSON encoding failed'];
        }

        return self::post($url, $body, 'application/json', $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private static function post(string $url, string $body, string $contentType, array $headers = []): array
    {
        $requestHeaders = ['Content-Type: ' . $contentType, 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
                || in_array(strtolower($name), ['content-type', 'accept', 'host', 'content-length'], true)) {
                return ['status' => 0, 'body' => 'Invalid request header'];
            }
            $requestHeaders[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => 'curl init failed'];
        }

        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) {
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
        ]);

        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($completed === false) {
            return ['status' => 0, 'body' => $error];
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
