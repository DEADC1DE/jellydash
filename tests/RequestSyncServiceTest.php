<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\RequestSyncService;
use PHPUnit\Framework\TestCase;

final class RequestSyncServiceTest extends TestCase
{
    public function testRequestedAtUsesConfiguredAppTimezone(): void
    {
        $this->withTimezoneEnvironment('America/New_York', 'Europe/London', function (): void {
            date_default_timezone_set('UTC');

            $requestedAt = (new \ReflectionClass(RequestSyncService::class))
                ->getMethod('requestedAt')
                ->invoke(
                    new RequestSyncService(),
                    ['createdAt' => '2026-08-09T12:00:00Z'],
                    'fallback'
                );

            $this->assertSame('2026-08-09 08:00:00', $requestedAt);
        });
    }

    public function testRequestedAtUsesDockerTimezoneWhenAppTimezoneIsMissing(): void
    {
        $this->withTimezoneEnvironment(null, 'America/New_York', function (): void {
            date_default_timezone_set('UTC');

            $requestedAt = (new \ReflectionClass(RequestSyncService::class))
                ->getMethod('requestedAt')
                ->invoke(
                    new RequestSyncService(),
                    ['createdAt' => '2026-08-09T12:00:00Z'],
                    'fallback'
                );

            $this->assertSame('2026-08-09 08:00:00', $requestedAt);
        });
    }

    private function withTimezoneEnvironment(?string $appTimezone, ?string $dockerTimezone, callable $assertion): void
    {
        $originalTimezone = date_default_timezone_get();
        $snapshot = [];

        foreach (['APP_TIMEZONE', 'TZ'] as $key) {
            $snapshot[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            if ($appTimezone !== null) {
                putenv('APP_TIMEZONE=' . $appTimezone);
            }
            if ($dockerTimezone !== null) {
                putenv('TZ=' . $dockerTimezone);
            }

            $assertion();
        } finally {
            date_default_timezone_set($originalTimezone);

            foreach ($snapshot as $key => $values) {
                $process = $values['process'];
                putenv($process === false ? $key : $key . '=' . $process);

                if ($values['env_exists']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($values['server_exists']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
