<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/session-control/src/SessionActionsService.php';

use Mk\Modules\SessionControl\SessionActionsService;
use PHPUnit\Framework\TestCase;

final class SessionControlActionsServiceTest extends TestCase
{
    /** Anonymous tracking-client mock shared by every test below. */
    private function makeTrackingClient(array &$calls, mixed $getJsonReturn): object
    {
        return new class ($calls, $getJsonReturn) {
            public array $calls;
            private mixed $getJsonReturn;
            public function __construct(array &$calls, mixed $getJsonReturn)
            {
                $this->calls = &$calls;
                $this->getJsonReturn = $getJsonReturn;
            }
            public function getJson(string $path): mixed
            {
                $this->calls[] = ['GET', $path];
                return $this->getJsonReturn;
            }
            public function postJson(string $path, array $payload): mixed
            {
                $this->calls[] = ['POST', $path, $payload];
                return null;
            }
        };
    }

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
            $client = $this->makeTrackingClient($calls, [['Id' => 'sess-1', 'UserId' => 'user-9']]);

            $service = new SessionActionsService($client);
            $result = $service->kick('sess-1');

            // The unreachable port makes the DELETE cURL call fail, so the
            // real-world result here is false — assert it so the return
            // value isn't left unchecked.
            $this->assertFalse($result);
            $this->assertSame('GET', $client->calls[0][0]);
            $this->assertSame('/Sessions', $client->calls[0][1]);
        } finally {
            putenv('JELLYFIN_URL');
            putenv('JELLYFIN_API_TOKEN');
        }
    }

    public function testKickReturnsFalseWhenSessionNotFound(): void
    {
        // Deliberately leave JELLYFIN_URL/JELLYFIN_API_TOKEN unset (no .env
        // in this repo, so they're absent by default). userIdForSession()
        // must return null and kick() must short-circuit *before* ever
        // touching Config::get() for the DELETE step — if a bug (wrong
        // comparison, wrong array key) made it fall through instead, kick()
        // would hit the "Jellyfin URL or API token is missing" RuntimeException
        // and this test would error out instead of passing.
        $calls = [];
        $client = $this->makeTrackingClient($calls, [['Id' => 'sess-other', 'UserId' => 'user-9']]);

        $service = new SessionActionsService($client);
        $result = $service->kick('sess-1');

        $this->assertFalse($result);
        $this->assertCount(1, $client->calls);
        $this->assertSame(['GET', '/Sessions'], $client->calls[0]);
    }

    public function testStopSendsVerifiedPlaystatePath(): void
    {
        // stop() now makes its own raw cURL POST (core JellyfinClient::postJson()
        // json_decode()s the empty-body 204 Jellyfin returns and throws), so it
        // never touches the tracking client — same limitation as kick()'s DELETE
        // step. Point at a local port nothing listens on so the POST fails fast
        // without a live server, and assert the real-world false result.
        putenv('JELLYFIN_URL=http://127.0.0.1:1');
        putenv('JELLYFIN_API_TOKEN=test-token');

        try {
            $calls = [];
            $client = $this->makeTrackingClient($calls, []);

            $service = new SessionActionsService($client);
            $result = $service->stop('sess-1');

            $this->assertFalse($result);
            $this->assertCount(0, $client->calls);
        } finally {
            putenv('JELLYFIN_URL');
            putenv('JELLYFIN_API_TOKEN');
        }
    }
}
