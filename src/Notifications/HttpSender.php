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
     * @return array{status: int, body: string}
     */
    public static function postJson(string $url, array $payload): array
    {
        return self::post($url, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json');
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function post(string $url, string $body, string $contentType): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => 'curl init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            return ['status' => 0, 'body' => $error];
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
