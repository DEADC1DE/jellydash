<?php

declare(strict_types=1);

namespace Mk\Modules\Users;

use Mk\Framework\Config;

/**
 * Thin client over the Wizarr (v4) HTTP API for the user-management parts of
 * the Users page: accounts with their expiry, invitations, and the account
 * actions Wizarr performs against the media server.
 *
 * Authenticates with an `X-API-Key` header; the key stays server-side and
 * never reaches the browser. Endpoints live under /api on the Wizarr base URL.
 */
class WizarrClient
{
    private string $baseUrl;
    private string $apiKey;
    private bool $verifySsl;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?bool $verifySsl = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?? Config::get('WIZARR_URL', '')), '/');
        $this->apiKey = (string) ($apiKey ?? Config::get('WIZARR_API_TOKEN', ''));
        $this->verifySsl = $verifySsl ?? Config::bool('WIZARR_VERIFY_SSL', true);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * All accounts Wizarr knows, keyed by lowercase username for merging with
     * the Jellyfin user list.
     *
     * @return array<string, array<string, mixed>>
     */
    public function usersByName(): array
    {
        $users = [];
        foreach ($this->request('GET', '/api/users')['users'] ?? [] as $user) {
            if (is_array($user) && isset($user['username'])) {
                $users[mb_strtolower((string) $user['username'])] = $user;
            }
        }

        return $users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function invitations(): array
    {
        $invitations = $this->request('GET', '/api/invitations')['invitations'] ?? [];
        if (!is_array($invitations)) {
            return [];
        }

        usort($invitations, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        return array_values(array_filter($invitations, 'is_array'));
    }

    /**
     * Libraries available for invitations. Wizarr sometimes keeps duplicate
     * sync rows per library (same external_id), which would render repeated
     * checkboxes, so collapse them here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function libraries(): array
    {
        $libraries = $this->request('GET', '/api/libraries')['libraries'] ?? [];
        if (!is_array($libraries)) {
            return [];
        }

        $seen = [];
        $unique = [];
        foreach ($libraries as $library) {
            if (!is_array($library)) {
                continue;
            }
            $key = (string) ($library['external_id'] ?? '') !== ''
                ? 'ext:' . $library['external_id']
                : 'id:' . ($library['id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $library;
        }

        return $unique;
    }

    /**
     * @param array<int, int> $libraryIds
     * @param array<int, int>|null $serverIds Defaults to the first configured server.
     */
    public function createInvitation(
        ?int $linkExpiresInDays,
        ?int $accessDays,
        array $libraryIds,
        bool $allowDownloads = false,
        bool $allowLiveTv = false,
        ?array $serverIds = null,
    ): array {
        $unlimited = $accessDays === null;
        $payload = [
            'server_ids' => $serverIds ?? $this->firstServerIds(),
            'duration' => $unlimited ? 'unlimited' : (string) max(1, $accessDays),
            'unlimited' => $unlimited,
            'library_ids' => $libraryIds,
            'allow_downloads' => $allowDownloads,
            'allow_live_tv' => $allowLiveTv,
        ];
        if ($linkExpiresInDays !== null && $linkExpiresInDays > 0) {
            $payload['expires_in_days'] = $linkExpiresInDays;
        }

        return $this->request('POST', '/api/invitations', $payload);
    }

    public function deleteInvitation(int $id): void
    {
        $this->request('DELETE', '/api/invitations/' . $id);
    }

    public function disableUser(int $id): void
    {
        $this->request('POST', '/api/users/' . $id . '/disable');
    }

    public function enableUser(int $id): void
    {
        $this->request('POST', '/api/users/' . $id . '/enable');
    }

    public function extendUser(int $id, int $days): array
    {
        return $this->request('POST', '/api/users/' . $id . '/extend', ['days' => max(1, $days)]);
    }

    public function resetPassword(int $id): void
    {
        $this->request('POST', '/api/users/' . $id . '/reset-password');
    }

    /**
     * @return array<int, int>
     */
    private function firstServerIds(): array
    {
        $servers = $this->request('GET', '/api/servers')['servers'] ?? [];
        if (is_array($servers) && is_array($servers[0] ?? null) && isset($servers[0]['id'])) {
            return [(int) $servers[0]['id']];
        }

        return [1];
    }

    /**
     * Single cURL entry point so tests can stub the transport.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Wizarr URL or API key is missing.');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is required.');
        }

        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }

        $headers = ['Accept: application/json', 'X-API-Key: ' . $this->apiKey];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new \RuntimeException('Wizarr request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Wizarr request failed with HTTP ' . $status . '.');
        }

        if (trim((string) $response) === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Wizarr returned invalid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Wizarr returned an unexpected payload.');
        }

        return $decoded;
    }
}
