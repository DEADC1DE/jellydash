<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\DeviceActivityService;
use Mk\Framework\Jellyfin\JellyfinClient;
use Mk\Framework\Jellyfin\RecentlyAddedService;
use Mk\Framework\Jellyfin\ServerActivityCache;
use Mk\Framework\Jellyfin\ServerActivityClient;
use Mk\Framework\Jellyfin\ServerActivityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JellyfinLinkUrlTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jellydash-links-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        putenv('JELLYFIN_LINK_URL=');
    }

    protected function tearDown(): void
    {
        putenv('JELLYFIN_LINK_URL=');
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[DataProvider('browserBases')]
    public function testAllBrowserLinksUseTheOverrideOrExistingFallback(string $setting, string $expected): void
    {
        putenv('JELLYFIN_LINK_URL=' . $setting);
        $client = $this->client();
        $devices = (new DeviceActivityService($client))->payload('week');
        self::assertSame($expected . '/web/#/dashboard/devices', $devices['manageUrl']);
        $activity = new ServerActivityClient($client->baseUrl(), 'test-token');
        self::assertSame($expected . '/web/', $activity->dashboardUrl());
        self::assertSame($expected . '/web/#/details?id=movie-1&serverId=server-1', $this->recent($client)->refreshCache()['items'][0]['jellyfinUrl']);
        self::assertSame('http://jellyfin:8096/internal', $client->baseUrl());
    }

    public static function browserBases(): array
    {
        return [
            'empty' => ['', 'http://jellyfin:8096/internal'],
            'whitespace' => ['   ', 'http://jellyfin:8096/internal'],
            'public' => ['https://media.example.com', 'https://media.example.com'],
            'base path and slash' => [' https://media.example.com/jellyfin/ ', 'https://media.example.com/jellyfin'],
        ];
    }

    #[DataProvider('invalidBrowserBases')]
    public function testInvalidBrowserUrlHidesLinksWithoutDisablingApi(string $setting): void
    {
        putenv('JELLYFIN_LINK_URL=' . $setting);
        $client = $this->client();
        self::assertNull((new DeviceActivityService($client))->payload('week')['manageUrl']);
        self::assertSame('', $this->recent($client)->refreshCache()['items'][0]['jellyfinUrl']);
        $activity = new ServerActivityClient($client->baseUrl(), 'test-token', true, static fn (): array => ['ServerName' => 'Fixture']);
        self::assertNull($activity->dashboardUrl());
        self::assertTrue($activity->configured());
        self::assertSame(['ServerName' => 'Fixture'], $activity->get('/System/Info'));
    }

    public static function invalidBrowserBases(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'javascript:alert(1)', '//media.example.com', 'https://user:password@media.example.com',
            'https://media.example.com?token=secret', 'https://media.example.com/#/home',
            'https://media.example.com\\@other.example.com', "https://media.example.com/path\nother",
        ]);
    }

    public function testPublicUrlCannotMakeAnInvalidApiUrlConfigured(): void
    {
        putenv('JELLYFIN_LINK_URL=https://media.example.com');
        self::assertFalse((new ServerActivityClient('', 'test-token'))->configured());
    }

    public function testRecentCacheTracksUrlChangesAndRecoversAfterInvalidSetting(): void
    {
        $client = $this->client();
        $service = $this->recent($client);
        $original = $service->refreshCache();
        foreach (['https://media.example.com/jellyfin', 'javascript:bad', 'https://second.example.com', ''] as $setting) {
            putenv('JELLYFIN_LINK_URL=' . $setting);
            $cached = $service->cachedPayload();
            self::assertTrue($cached['cached']);
            self::assertSame($original['generated_at'], $cached['generated_at']);
            $base = $setting === '' ? $client->baseUrl() : $setting;
            self::assertSame($setting === 'javascript:bad' ? '' : $base . '/web/#/details?id=movie-1&serverId=server-1', $cached['items'][0]['jellyfinUrl']);
        }
        putenv('JELLYFIN_LINK_URL=javascript:bad');
        $service->refreshCache();
        putenv('JELLYFIN_LINK_URL=https://fixed.example.com');
        self::assertSame('https://fixed.example.com/web/#/details?id=movie-1&serverId=server-1', $service->cachedPayload()['items'][0]['jellyfinUrl']);
    }

    public function testLegacyRecentCacheRebasesLinksWhenUpstreamIsUnavailable(): void
    {
        file_put_contents($this->directory . '/recent.json', json_encode([
            'schemaVersion' => 2, 'generated_at' => time() - 600, 'items' => [[
                'title' => 'Movie', 'addedAt' => '2026-10-11T12:00:00Z',
                'jellyfinUrl' => 'http://old-internal:8096/web/#/details?id=movie-1&serverId=server-1',
            ]],
        ], JSON_THROW_ON_ERROR));
        putenv('JELLYFIN_LINK_URL=https://media.example.com/jellyfin');
        $client = new JellyfinClient('http://jellyfin:8096/internal', 'token', true, static function (): never {
            throw new RuntimeException('Offline fixture');
        });
        $cached = $this->recent($client)->cachedPayload();
        self::assertTrue($cached['stale']);
        self::assertSame('https://media.example.com/jellyfin/web/#/details?id=movie-1&serverId=server-1', $cached['items'][0]['jellyfinUrl']);
    }

    public function testServerActivityRebasesFreshAndStaleCachedLinksWithoutChangingSource(): void
    {
        $now = 1000;
        $offline = false;
        $client = new ServerActivityClient('http://jellyfin:8096/internal', 'token', true, static function (string $path) use (&$offline): array {
            if ($offline) {
                throw new RuntimeException('Offline fixture');
            }
            return $path === '/ScheduledTasks' ? [] : ['ServerName' => 'Fixture', 'Version' => '10.11.0'];
        });
        $clock = static function () use (&$now): int {
            return $now;
        };
        $service = new ServerActivityService($client, new ServerActivityCache($this->directory, $clock), $clock);
        $sourceKey = $client->sourceKey();
        $service->overview();
        putenv('JELLYFIN_LINK_URL=https://media.example.com/jellyfin');
        self::assertSame($sourceKey, $client->sourceKey());
        self::assertSame('https://media.example.com/jellyfin/web/', $service->overview()['server']['data']['url']);
        $now += 20;
        $offline = true;
        putenv('JELLYFIN_LINK_URL=https://second.example.com');
        $server = $service->overview()['server'];
        self::assertTrue($server['stale']);
        self::assertSame('https://second.example.com/web/', $server['data']['url']);
    }

    private function client(): JellyfinClient
    {
        return new JellyfinClient('http://jellyfin:8096/internal', 'test-token', true, static function (string $path): array {
            if ($path === '/System/Info/Public') {
                return ['Id' => 'server-1'];
            }
            if (str_starts_with($path, '/Items?')) {
                return ['Items' => [['Id' => 'movie-1', 'Name' => 'Movie', 'Type' => 'Movie', 'DateCreated' => '2026-10-11T12:00:00Z']], 'TotalRecordCount' => 1];
            }
            return [];
        });
    }

    private function recent(JellyfinClient $client): RecentlyAddedService
    {
        return new RecentlyAddedService($client, new DateTimeImmutable('2026-10-11T13:00:00Z'), $this->directory . '/recent.json');
    }
}
