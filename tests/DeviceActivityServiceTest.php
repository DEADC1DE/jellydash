<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\DeviceActivityService;
use PHPUnit\Framework\TestCase;

final class DeviceActivityServiceTest extends TestCase
{
    private DeviceActivityService $service;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->service = new DeviceActivityService();
        $this->now = new \DateTimeImmutable('2026-08-19T12:00:00Z');
    }

    public function testPayloadIsAllowlistedSortedAndMarksOnlyPlayingDevicesActive(): void
    {
        $payload = $this->service->fromApiPayloads([
            'Items' => [
                [
                    'Id' => 'living-room',
                    'Name' => 'LG TV',
                    'CustomName' => 'Living Room',
                    'AppName' => 'Jellyfin Web',
                    'AppVersion' => '10.10.7',
                    'LastUserName' => 'Regina',
                    'DateLastActivity' => '2026-08-19T11:58:00Z',
                    'AccessToken' => 'must-never-leave-the-backend',
                    'Capabilities' => ['SupportsMediaControl' => true],
                    'LastUserId' => 'private-user-id',
                ],
                [
                    'Id' => 'bedroom',
                    'Name' => 'Bedroom Shield',
                    'AppName' => 'Android TV',
                    'AppVersion' => '0.18.0',
                    'LastUserName' => 'Martin',
                    'DateLastActivity' => '2026-08-18T09:00:00Z',
                ],
                [
                    'Id' => 'old-phone',
                    'Name' => 'Old Phone',
                    'DateLastActivity' => '2026-07-01T09:00:00Z',
                ],
            ],
        ], [
            ['DeviceId' => 'living-room', 'NowPlayingItem' => ['Id' => 'movie']],
            ['DeviceId' => 'bedroom'],
            ['DeviceId' => 'unknown-device', 'NowPlayingItem' => ['Id' => 'episode']],
        ], 'week', $this->now);

        $this->assertSame(3, $payload['known']);
        $this->assertSame(2, $payload['seen']);
        $this->assertSame(1, $payload['active']);
        $this->assertSame('Last 7 days', $payload['rangeLabel']);
        $this->assertSame('Living Room', $payload['items'][0]['name']);
        $this->assertSame('Active now', $payload['items'][0]['lastSeen']);
        $this->assertSame('active', $payload['items'][0]['status']);
        $this->assertSame('tv', $payload['items'][0]['icon']);
        $this->assertSame('Bedroom Shield', $payload['items'][1]['name']);
        $this->assertSame('Yesterday', $payload['items'][1]['lastSeen']);
        $this->assertSame('recent', $payload['items'][1]['status']);
        $this->assertSame('android', $payload['items'][1]['icon']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-never-leave-the-backend', $encoded);
        $this->assertStringNotContainsString('Capabilities', $encoded);
        $this->assertStringNotContainsString('private-user-id', $encoded);
        $this->assertStringNotContainsString('living-room', $encoded);
    }

    public function testSelectedRangeFiltersDevicesAndListIsCappedAtSix(): void
    {
        $items = [];
        for ($day = 19; $day >= 12; --$day) {
            $items[] = [
                'Id' => 'device-' . $day,
                'Name' => 'Device ' . $day,
                'AppName' => 'Jellyfin Client',
                'DateLastActivity' => sprintf('2026-08-%02dT10:00:00Z', $day),
            ];
        }

        $payload = $this->service->fromApiPayloads(['Items' => $items], [], 'month', $this->now);

        $this->assertSame(8, $payload['known']);
        $this->assertSame(8, $payload['seen']);
        $this->assertCount(6, $payload['items']);
        $this->assertTrue($payload['hasMore']);
        $this->assertSame('Device 19', $payload['items'][0]['name']);
        $this->assertSame('Device 14', $payload['items'][5]['name']);
    }

    public function testMalformedRowsAreIgnoredAndInvalidRangeFallsBackToWeek(): void
    {
        $payload = $this->service->fromApiPayloads([
            'Items' => [
                'not-an-array',
                ['Name' => 'Missing identifier'],
                ['Id' => 'valid', 'DateLastActivity' => 'not-a-date'],
                ['Id' => 'valid', 'Name' => 'Duplicate'],
            ],
        ], 'not-a-session-list', 'invalid', $this->now);

        $this->assertSame('week', $payload['range']);
        $this->assertSame(1, $payload['known']);
        $this->assertSame(0, $payload['seen']);
        $this->assertSame([], $payload['items']);
        $this->assertFalse($payload['hasMore']);
    }

    public function testAllTimeKeepsDevicesWithoutLastActivityAtTheEnd(): void
    {
        $payload = $this->service->fromApiPayloads([
            'Items' => [
                ['Id' => 'unknown-date', 'Name' => 'Unknown Date'],
                ['Id' => 'dated', 'Name' => 'Dated', 'DateLastActivity' => '2024-01-01T00:00:00Z'],
            ],
        ], [], 'all', $this->now);

        $this->assertSame(2, $payload['seen']);
        $this->assertSame('Dated', $payload['items'][0]['name']);
        $this->assertSame('Unknown Date', $payload['items'][1]['name']);
        $this->assertSame('Last seen unknown', $payload['items'][1]['lastSeen']);
    }

    public function testDevicesDashboardUrlUsesOnlyAValidConfiguredServerRoot(): void
    {
        $this->assertSame(
            'https://jellyfin.example.com/web/#/dashboard/devices',
            $this->service->devicesDashboardUrl('https://jellyfin.example.com/'),
        );
        $this->assertSame(
            'https://media.example.com/jellyfin/web/#/dashboard/devices',
            $this->service->devicesDashboardUrl('https://media.example.com/jellyfin'),
        );
        $this->assertNull($this->service->devicesDashboardUrl('javascript:alert(1)'));
        $this->assertNull($this->service->devicesDashboardUrl('/relative/server'));
        $this->assertNull($this->service->devicesDashboardUrl('https://user:secret@jellyfin.example.com'));
        $this->assertNull($this->service->devicesDashboardUrl('https://jellyfin.example.com/?token=secret'));
    }

    public function testCommonClientsReceiveOnlyKnownLocalIconKinds(): void
    {
        $cases = [
            ['Jellyfin Web', 'Chrome', 'chrome'],
            ['Jellyfin Web', 'Firefox', 'firefox'],
            ['Jellyfin Web', 'Safari', 'safari'],
            ['Jellyfin Web', 'Microsoft Edge', 'edge'],
            ['Jellyfin Web', 'Opera', 'opera'],
            ['Jellyfin iOS', 'iPhone', 'apple'],
            ['Infuse-Direct', 'Apple TV', 'apple'],
            ['Jellyfin Android', 'Pixel 9', 'android'],
            ['Findroid', 'Android Phone', 'android'],
            ['Jellyfin for WebOS', 'LG Smart TV', 'tv'],
            ['Jellyfin Media Player', 'Windows PC', 'desktop'],
            ['Jellyfin for Windows', 'Windows PC', 'windows'],
            ['Jellyfin Web', 'Unknown browser', 'browser'],
            ['Jellyfin Xbox', 'Xbox Series X', 'xbox'],
            ['Jellyfin PlayStation', 'PS5', 'console'],
            ['Finamp', 'Phone', 'audio'],
            ['Home Assistant', 'Server', 'home'],
            ['Jellyseerr', 'Web service', 'integration'],
            ['Unknown client', 'Mystery box', 'device'],
        ];

        foreach ($cases as [$app, $name, $expected]) {
            $payload = $this->service->fromApiPayloads([
                'Items' => [[
                    'Id' => $app . $name,
                    'Name' => $name,
                    'AppName' => $app,
                    'DateLastActivity' => '2026-08-19T11:00:00Z',
                ]],
            ], [], 'week', $this->now);

            $this->assertSame($expected, $payload['items'][0]['icon'], $app . ' on ' . $name);
        }
    }
}
