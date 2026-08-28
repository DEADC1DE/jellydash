<?php

declare(strict_types=1);

namespace Mk\Modules\Devices;

use Mk\Framework\Config;
use Mk\Framework\Jellyfin\JellyfinClient;

final class DeviceService
{
    public function __construct(private readonly object $client = new JellyfinClient())
    {
    }

    /**
     * @return array<int, array{id: string, name: string, appName: string, lastUserName: string, lastActivity: string}>
     */
    public function list(): array
    {
        $payload = $this->client->getJson('/Devices');
        $items = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];

        $devices = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['Id'])) {
                continue;
            }

            $devices[] = [
                'id' => (string) $item['Id'],
                'name' => (string) ($item['Name'] ?? 'Unknown device'),
                'appName' => (string) ($item['AppName'] ?? ''),
                'lastUserName' => (string) ($item['LastUserName'] ?? ''),
                'lastActivity' => (string) ($item['DateLastActivity'] ?? ''),
            ];
        }

        return $devices;
    }

    /**
     * DELETE /Devices?id= — JellyfinClient (core) only sends GET/POST, so this
     * module does its own minimal DELETE call rather than touching core code.
     */
    public function delete(string $id): bool
    {
        $baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
        $token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        $handle = curl_init($baseUrl . '/Devices?id=' . rawurlencode($id));
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
}
