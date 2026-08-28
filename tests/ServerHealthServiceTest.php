<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/server-health/src/ServerHealthService.php';

use Mk\Modules\ServerHealth\ServerHealthService;
use PHPUnit\Framework\TestCase;

final class ServerHealthServiceTest extends TestCase
{
    public function testSystemInfoMapsFields(): void
    {
        $client = new class {
            public function getJson(string $path): mixed
            {
                return match ($path) {
                    '/System/Info' => [
                        'ServerName' => 'boecki-jellyfin',
                        'Version' => '10.10.7',
                        'OperatingSystem' => 'Linux',
                    ],
                    default => null,
                };
            }
        };

        $info = (new ServerHealthService($client))->systemInfo();

        $this->assertSame('boecki-jellyfin', $info['serverName']);
        $this->assertSame('10.10.7', $info['version']);
        $this->assertSame('Linux', $info['operatingSystem']);
    }

    public function testTasksMapsProgressAndLastRunStatus(): void
    {
        $client = new class {
            public function getJson(string $path): mixed
            {
                return [[
                    'Id' => 'task-1',
                    'Name' => 'Scan Media Library',
                    'State' => 'Running',
                    'CurrentProgressPercentage' => 42.5,
                    'LastExecutionResult' => ['Status' => 'Completed'],
                ]];
            }
        };

        $tasks = (new ServerHealthService($client))->tasks();

        $this->assertSame('task-1', $tasks[0]['id']);
        $this->assertSame('Running', $tasks[0]['state']);
        $this->assertSame(42.5, $tasks[0]['progress']);
        $this->assertSame('Completed', $tasks[0]['lastRunStatus']);
        $this->assertNull($tasks[0]['lastRunAt']);
    }

    public function testTasksParsesLastRunTimestampFromRealJellyfinFormat(): void
    {
        $client = new class {
            public function getJson(string $path): mixed
            {
                return [[
                    'Id' => 'task-1',
                    'Name' => 'Clean Activity Log',
                    'State' => 'Idle',
                    // Live-verified Jellyfin format: variable fractional-second digits.
                    'LastExecutionResult' => [
                        'StartTimeUtc' => '2026-08-28T10:25:00.47979Z',
                        'EndTimeUtc' => '2026-08-28T10:25:00.4803611Z',
                        'Status' => 'Completed',
                    ],
                ]];
            }
        };

        $tasks = (new ServerHealthService($client))->tasks();

        $this->assertSame('2026-08-28 10:25:00', $tasks[0]['lastRunAt']);
    }
}
