<?php

declare(strict_types=1);

use Mk\Framework\Jellyseerr\RequestSyncService;
use PHPUnit\Framework\TestCase;

final class RequestSyncServiceTest extends TestCase
{
    public function testRequestedAtUsesConfiguredAppTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        $hadEnv = array_key_exists('APP_TIMEZONE', $_ENV);
        $originalEnv = $_ENV['APP_TIMEZONE'] ?? null;

        try {
            $_ENV['APP_TIMEZONE'] = 'America/New_York';
            date_default_timezone_set('UTC');

            $requestedAt = (new \ReflectionClass(RequestSyncService::class))
                ->getMethod('requestedAt')
                ->invoke(
                    new RequestSyncService(),
                    ['createdAt' => '2026-08-09T12:00:00Z'],
                    'fallback'
                );

            $this->assertSame('2026-08-09 08:00:00', $requestedAt);
        } finally {
            date_default_timezone_set($originalTimezone);
            if ($hadEnv) {
                $_ENV['APP_TIMEZONE'] = $originalEnv;
            } else {
                unset($_ENV['APP_TIMEZONE']);
            }
        }
    }
}
