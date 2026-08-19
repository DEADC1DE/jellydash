<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class DeviceActivityService
{
    private const VALID_RANGES = ['week', 'month', 'year', 'all'];

    public function __construct(private ?JellyfinClient $client = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $range): array
    {
        $client = $this->client ?? new JellyfinClient();

        $payload = $this->fromApiPayloads(
            $client->getJson('/Devices', 5),
            $client->sessions(),
            $range,
        );
        $payload['manageUrl'] = $this->devicesDashboardUrl($client->baseUrl());

        return $payload;
    }

    public function devicesDashboardUrl(string $baseUrl): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return $baseUrl . '/web/#/dashboard/devices';
    }

    /**
     * Turn Jellyfin's device and session responses into the small, safe shape
     * used by Statistics. DeviceInfoDto can contain an access token and other
     * internal fields, so only this explicit allowlist may leave the backend.
     *
     * @return array<string, mixed>
     */
    public function fromApiPayloads(
        mixed $devicePayload,
        mixed $sessionPayload,
        string $range,
        ?\DateTimeImmutable $now = null,
    ): array {
        $range = in_array($range, self::VALID_RANGES, true) ? $range : 'week';
        $now ??= new \DateTimeImmutable('now');
        $devices = $this->safeDevices($devicePayload);
        $activeIds = $this->activeDeviceIds($sessionPayload);
        $knownIds = array_fill_keys(array_column($devices, 'id'), true);
        $activeIds = array_intersect_key($activeIds, $knownIds);
        $cutoff = $this->cutoff($range, $now);

        $seen = array_values(array_filter(
            $devices,
            static fn (array $device): bool => $cutoff === null
                || ($device['lastActivity'] instanceof \DateTimeImmutable && $device['lastActivity'] >= $cutoff),
        ));

        usort($seen, static function (array $a, array $b): int {
            $aTime = $a['lastActivity'] instanceof \DateTimeImmutable ? $a['lastActivity']->getTimestamp() : 0;
            $bTime = $b['lastActivity'] instanceof \DateTimeImmutable ? $b['lastActivity']->getTimestamp() : 0;

            return $bTime <=> $aTime;
        });

        $items = [];
        foreach (array_slice($seen, 0, 6) as $device) {
            $active = isset($activeIds[$device['id']]);
            $lastActivity = $device['lastActivity'] instanceof \DateTimeImmutable ? $device['lastActivity'] : null;
            $items[] = [
                'name' => $device['customName'] !== '' ? $device['customName'] : $device['name'],
                'app' => $device['appName'],
                'version' => $device['appVersion'],
                'icon' => $this->iconKind($device['appName'], $device['name']),
                'user' => $device['lastUserName'],
                'lastSeen' => $active ? 'Active now' : $this->relativeTime($lastActivity, $now),
                'lastSeenExact' => $lastActivity?->format('M j, Y, H:i') ?? 'Last activity unavailable',
                'status' => $active ? 'active' : $this->status($lastActivity, $now),
            ];
        }

        return [
            'range' => $range,
            'rangeLabel' => $this->rangeLabel($range),
            'known' => count($devices),
            'seen' => count($seen),
            'active' => count($activeIds),
            'items' => $items,
            'hasMore' => count($seen) > count($items),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function safeDevices(mixed $payload): array
    {
        $rawItems = is_array($payload) && is_array($payload['Items'] ?? null) ? $payload['Items'] : [];
        $devices = [];

        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $id = trim((string) ($raw['Id'] ?? ''));
            if ($id === '' || isset($devices[$id])) {
                continue;
            }

            $devices[$id] = [
                'id' => $id,
                'name' => $this->label($raw['Name'] ?? null, 'Unknown device'),
                'customName' => $this->label($raw['CustomName'] ?? null),
                'appName' => $this->label($raw['AppName'] ?? null, 'Unknown client'),
                'appVersion' => $this->label($raw['AppVersion'] ?? null),
                'lastUserName' => $this->label($raw['LastUserName'] ?? null, 'Unknown user'),
                'lastActivity' => $this->date($raw['DateLastActivity'] ?? null),
            ];
        }

        return array_values($devices);
    }

    /**
     * @return array<string, true>
     */
    private function activeDeviceIds(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $ids = [];
        foreach ($payload as $session) {
            if (!is_array($session) || !is_array($session['NowPlayingItem'] ?? null)) {
                continue;
            }

            $id = trim((string) ($session['DeviceId'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function cutoff(string $range, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $today = $now->setTime(0, 0);

        return match ($range) {
            'week' => $today->modify('-6 days'),
            'month' => $today->modify('-29 days'),
            'year' => $today->modify('first day of this month')->modify('-11 months'),
            default => null,
        };
    }

    private function rangeLabel(string $range): string
    {
        return match ($range) {
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
            'year' => 'Last 12 months',
            default => 'All time',
        };
    }

    private function relativeTime(?\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        if ($date === null) {
            return 'Last seen unknown';
        }

        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ago';
        }
        if ($seconds < 172800) {
            return 'Yesterday';
        }
        if ($seconds < 2592000) {
            return intdiv($seconds, 86400) . 'd ago';
        }

        return $date->format('M j');
    }

    private function status(?\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        if ($date !== null && $date >= $now->modify('-7 days')) {
            return 'recent';
        }

        return 'older';
    }

    private function iconKind(string $appName, string $deviceName): string
    {
        $app = strtolower($appName);
        $device = strtolower($deviceName);
        $identity = $app . ' ' . $device;

        if (
            str_contains($app, 'jellyseerr')
            || str_contains($app, 'seerr')
            || str_contains($app, 'jellywatch')
        ) {
            return 'integration';
        }
        if (str_contains($identity, 'home assistant')) {
            return 'home';
        }
        if (str_contains($identity, 'finamp') || str_contains($identity, 'mopidy')) {
            return 'audio';
        }
        if (
            str_contains($identity, 'infuse')
            || str_contains($identity, 'swiftfin')
            || str_contains($identity, 'apple tv')
            || str_contains($identity, 'iphone')
            || str_contains($identity, 'ipad')
            || str_contains($identity, 'ipados')
            || str_contains($identity, 'tvos')
            || str_contains($identity, 'ios')
            || str_contains($identity, 'macos')
            || str_contains($identity, 'macintosh')
        ) {
            return 'apple';
        }
        if (str_contains($identity, 'android') || str_contains($identity, 'findroid')) {
            return 'android';
        }
        if (
            str_contains($identity, 'webos')
            || str_contains($identity, 'lg tv')
            || str_contains($identity, 'smart tv')
            || str_contains($identity, 'tizen')
            || str_contains($identity, 'roku')
            || str_contains($identity, 'fire tv')
            || str_contains($identity, 'kodi')
            || str_contains($identity, 'jellycon')
        ) {
            return 'tv';
        }
        if (str_contains($identity, 'xbox')) {
            return 'xbox';
        }
        if (
            str_contains($identity, 'playstation')
            || str_contains($identity, 'ps4')
            || str_contains($identity, 'ps5')
        ) {
            return 'console';
        }
        if (
            str_contains($app, 'jellyfin media player')
            || str_contains($app, 'jellyfin desktop')
            || str_contains($app, 'mpv shim')
        ) {
            return 'desktop';
        }
        if (str_contains($identity, 'windows')) {
            return 'windows';
        }
        if (str_contains($identity, 'firefox')) {
            return 'firefox';
        }
        if (str_contains($identity, 'chrome') || str_contains($identity, 'chromium')) {
            return 'chrome';
        }
        if (str_contains($identity, 'safari')) {
            return 'safari';
        }
        if (str_contains($identity, 'edge')) {
            return 'edge';
        }
        if (str_contains($identity, 'opera')) {
            return 'opera';
        }
        if (str_contains($app, 'web') || str_contains($app, 'browser')) {
            return 'browser';
        }

        return 'device';
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(mixed $value, string $fallback = ''): string
    {
        $label = is_string($value) ? trim($value) : '';

        return $label !== '' ? $label : $fallback;
    }
}
