<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/devices/src/DeviceService.php';

use Mk\Modules\Devices\DeviceService;
use PHPUnit\Framework\TestCase;

final class DevicesDeviceServiceTest extends TestCase
{
    public function testListMapsJellyfinDevicePayload(): void
    {
        $client = new class {
            public function getJson(string $path): mixed
            {
                self::assertSameStatic('/Devices', $path);
                return ['Items' => [[
                    'Id' => 'dev-1',
                    'Name' => "Patrick's Fire TV",
                    'AppName' => 'Jellyfin Android TV',
                    'LastUserName' => 'jf_deleted_user_2',
                    'DateLastActivity' => '2026-08-11T18:17:14.0000000Z',
                ]]];
            }

            private static function assertSameStatic(string $expected, string $actual): void
            {
                \PHPUnit\Framework\Assert::assertSame($expected, $actual);
            }
        };

        $service = new DeviceService($client);
        $devices = $service->list();

        $this->assertCount(1, $devices);
        $this->assertSame('dev-1', $devices[0]['id']);
        $this->assertSame("Patrick's Fire TV", $devices[0]['name']);
        $this->assertSame('Jellyfin Android TV', $devices[0]['appName']);
        $this->assertSame('jf_deleted_user_2', $devices[0]['lastUserName']);
        $this->assertSame('2026-08-11T18:17:14.0000000Z', $devices[0]['lastActivity']);
    }

    public function testListSkipsEntriesWithoutId(): void
    {
        $client = new class {
            public function getJson(string $path): mixed
            {
                return ['Items' => [['Name' => 'no id here']]];
            }
        };

        $devices = (new DeviceService($client))->list();

        $this->assertSame([], $devices);
    }
}
