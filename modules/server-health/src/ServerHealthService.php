<?php

declare(strict_types=1);

namespace Mk\Modules\ServerHealth;

use Mk\Framework\Config;
use Mk\Framework\Jellyfin\JellyfinClient;

final class ServerHealthService
{
    public function __construct(private readonly object $client = new JellyfinClient())
    {
    }

    /** @return array{serverName: string, version: string, operatingSystem: string} */
    public function systemInfo(): array
    {
        $payload = $this->client->getJson('/System/Info');
        $info = is_array($payload) ? $payload : [];

        return [
            'serverName' => (string) ($info['ServerName'] ?? ''),
            'version' => (string) ($info['Version'] ?? ''),
            'operatingSystem' => (string) ($info['OperatingSystem'] ?? ''),
        ];
    }

    /** @return array<int, array{id: string, name: string, state: string, progress: ?float, lastRunStatus: string}> */
    public function tasks(): array
    {
        $payload = $this->client->getJson('/ScheduledTasks');
        $items = is_array($payload) ? $payload : [];

        $tasks = [];
        foreach ($items as $task) {
            if (!is_array($task) || !isset($task['Id'])) {
                continue;
            }

            $lastResult = is_array($task['LastExecutionResult'] ?? null) ? $task['LastExecutionResult'] : [];
            $progress = $task['CurrentProgressPercentage'] ?? null;

            $tasks[] = [
                'id' => (string) $task['Id'],
                'name' => (string) ($task['Name'] ?? 'Unnamed task'),
                'state' => (string) ($task['State'] ?? 'Idle'),
                'progress' => is_numeric($progress) ? (float) $progress : null,
                'lastRunStatus' => (string) ($lastResult['Status'] ?? '-'),
            ];
        }

        return $tasks;
    }

    /**
     * POST /ScheduledTasks/Running/{id} — same core limitation as stopTask:
     * JellyfinClient (core) fails on Jellyfin's empty 204 response, so this
     * module does its own call.
     */
    public function triggerTask(string $id): bool
    {
        $baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
        $token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        $handle = curl_init($baseUrl . '/ScheduledTasks/Running/' . rawurlencode($id));
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
     * DELETE /ScheduledTasks/Running/{id} — same core limitation as devices:
     * JellyfinClient (core) has no DELETE, so this module does its own call.
     */
    public function stopTask(string $id): bool
    {
        $baseUrl = rtrim((string) Config::get('JELLYFIN_URL', ''), '/');
        $token = (string) Config::get('JELLYFIN_API_TOKEN', Config::get('JELLYFIN_API_KEY', ''));

        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Jellyfin URL or API token is missing.');
        }

        $handle = curl_init($baseUrl . '/ScheduledTasks/Running/' . rawurlencode($id));
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
