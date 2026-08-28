<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/session-control/src/SessionActionsService.php';

use Mk\Modules\SessionControl\SessionActionsService;
use PHPUnit\Framework\TestCase;

final class SessionControlActionsServiceTest extends TestCase
{
    public function testKickLooksUpUserIdBeforeRemoving(): void
    {
        // kick() needs real Jellyfin config because its DELETE step is a raw
        // cURL call (core JellyfinClient has no DELETE) — point it at a local
        // port nothing listens on so the DELETE fails fast without a live
        // server, while still exercising the real GET-then-DELETE order.
        putenv('JELLYFIN_URL=http://127.0.0.1:1');
        putenv('JELLYFIN_API_TOKEN=test-token');

        try {
            $calls = [];
            $client = new class ($calls) {
                public array $calls;
                public function __construct(array &$calls) { $this->calls = &$calls; }
                public function getJson(string $path): mixed
                {
                    $this->calls[] = ['GET', $path];
                    return [['Id' => 'sess-1', 'UserId' => 'user-9']];
                }
                public function postJson(string $path, array $payload): mixed
                {
                    $this->calls[] = ['POST', $path, $payload];
                    return null;
                }
            };

            $service = new SessionActionsService($client);
            $service->kick('sess-1');

            $this->assertSame('GET', $client->calls[0][0]);
            $this->assertSame('/Sessions', $client->calls[0][1]);
        } finally {
            putenv('JELLYFIN_URL');
            putenv('JELLYFIN_API_TOKEN');
        }
    }

    public function testStopSendsVerifiedPlaystatePath(): void
    {
        $calls = [];
        $client = new class ($calls) {
            public array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function getJson(string $path): mixed
            {
                $this->calls[] = ['GET', $path];
                return [];
            }
            public function postJson(string $path, array $payload): mixed
            {
                $this->calls[] = ['POST', $path, $payload];
                return null;
            }
        };

        $service = new SessionActionsService($client);
        $result = $service->stop('sess-1');

        $this->assertTrue($result);
        $this->assertSame('POST', $client->calls[0][0]);
        $this->assertSame('/Sessions/sess-1/Playing/Stop', $client->calls[0][1]);
    }
}
