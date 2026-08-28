<?php

declare(strict_types=1);

namespace Mk\Modules\SessionControl;

use Mk\Framework\Config;
use Mk\Framework\Jellyfin\JellyfinClient;

final class SessionActionsService
{
    public function __construct(private readonly object $client = new JellyfinClient())
    {
    }

    /**
     * POST /Sessions/{sessionId}/Playing/{command} — verified against the live
     * server's /api-docs/openapi.json in Task 5 Step 1. The brief's placeholder
     * (`/Sessions/{sessionId}/Playstate` with a `Command` body) does not exist;
     * the real endpoint takes the command as a path segment with no body.
     *
     * Jellyfin returns 204 with an empty body on success, and core
     * JellyfinClient::postJson() unconditionally json_decode()s the response
     * (throws on empty string) — same limitation as
     * ServerHealthService::triggerTask(), so this makes its own raw cURL call.
     */
    public function stop(string $sessionId): bool
    {
        $baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
        $token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        $handle = curl_init($baseUrl . '/Sessions/' . rawurlencode($sessionId) . '/Playing/Stop');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: MediaBrowser Token="' . $token . '"'],
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status >= 200 && $status < 300;
    }

    /**
     * DELETE /Sessions/{sessionId}/User/{userId} — core JellyfinClient has no
     * DELETE, so this reads UserId via the existing GET /Sessions call and
     * makes its own minimal DELETE request.
     */
    public function kick(string $sessionId): bool
    {
        $userId = $this->userIdForSession($sessionId);
        if ($userId === null) {
            return false;
        }

        $baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
        $token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        $handle = curl_init($baseUrl . '/Sessions/' . rawurlencode($sessionId) . '/User/' . rawurlencode($userId));
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: MediaBrowser Token="' . $token . '"'],
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status >= 200 && $status < 300;
    }

    private function userIdForSession(string $sessionId): ?string
    {
        $payload = $this->client->getJson('/Sessions');
        foreach (is_array($payload) ? $payload : [] as $session) {
            if (is_array($session) && (string) ($session['Id'] ?? '') === $sessionId) {
                $userId = (string) ($session['UserId'] ?? '');
                return $userId !== '' ? $userId : null;
            }
        }

        return null;
    }
}
