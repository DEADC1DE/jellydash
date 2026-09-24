<?php

declare(strict_types=1);

use Mk\Framework\Downloads\DownloaderTransport;
use Mk\Framework\Downloads\HttpRequest;
use Mk\Framework\Downloads\HttpResponse;
use Mk\Framework\Downloads\ProviderException;
use Mk\Framework\Downloads\Providers\QbittorrentProvider;
use Mk\Framework\Downloads\Providers\SabnzbdProvider;
use Mk\Framework\Integrations\Connection;
use PHPUnit\Framework\TestCase;

final class DownloadProviderTest extends TestCase
{
    public function testSabConnectionTestUsesAuthenticatedReadsAndReturnsCategories(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            self::assertSame('test-key', $query['apikey']);

            return match ($query['mode'] ?? '') {
                'queue' => self::json(['queue' => ['version' => '4.5.3', 'slots' => []]]),
                'get_cats' => self::json(['categories' => ['*', 'movies', 'tv']]),
                default => self::fail('Unexpected SAB operation'),
            };
        });

        $result = (new SabnzbdProvider($transport))->testConnection(
            $this->connection('sabnzbd', 'http://sab.test/base'),
            ['username' => '', 'secret' => 'test-key'],
        );

        self::assertSame(['version' => '4.5.3', 'categories' => ['', 'movies', 'tv'], 'tags' => []], $result);
        self::assertCount(2, $transport->requests);
    }

    public function testSabCollectNormalizesUnitsStatesAndAuthoritativeCompletions(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                self::assertSame('0', $query['start']);
                self::assertSame('1000', $query['limit']);
                return self::json(['queue' => [
                    'kbpersec' => '12.5', 'noofslots' => 150,
                    'slots' => [
                        ['nzo_id' => 'active-1', 'filename' => 'Active', 'cat' => 'tv', 'status' => 'Downloading', 'mb' => '100', 'mbleft' => '25', 'percentage' => '75', 'timeleft' => '0:01:02', 'index' => 3],
                        ['nzo_id' => 'paused-1', 'filename' => 'Paused', 'cat' => '*', 'status' => 'Paused', 'mb' => '5', 'mbleft' => '5', 'percentage' => '0', 'index' => 4],
                    ],
                ]]);
            }

            $archive = (string) ($query['archive'] ?? '0');
            return self::json(['history' => [
                'noofslots' => $archive === '0' ? 2 : 1,
                'last_history_update' => 40,
                'slots' => $archive === '0' ? [
                    ['nzo_id' => 'processing-1', 'name' => 'Unpacking', 'category' => 'movies', 'status' => 'Extracting', 'downloaded' => 10],
                    ['nzo_id' => 'done-1', 'name' => 'Done', 'category' => 'movies', 'status' => 'Completed', 'downloaded' => 2048, 'completed' => 1234],
                ] : [
                    ['nzo_id' => 'done-1', 'name' => 'Done duplicate', 'category' => 'movies', 'status' => 'Completed', 'downloaded' => 2048, 'completed' => 1234],
                ],
            ]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test/base'),
            ['username' => '', 'secret' => 'test-key'],
        );

        self::assertSame(12800.0, $batch->speed);
        self::assertSame(['downloading', 'paused', 'processing'], array_column($batch->items, 'state'));
        self::assertSame(104857600, $batch->items[0]['size']);
        self::assertSame(78643200, $batch->items[0]['downloaded']);
        self::assertNull($batch->items[0]['speed']);
        self::assertSame(62, $batch->items[0]['eta']);
        self::assertCount(1, $batch->completions);
        self::assertSame(1234, $batch->completions[0]['completed_at']);
        self::assertFalse($batch->complete);
        self::assertArrayNotHasKey('queue_offset', $batch->cursor);
    }

    public function testSabPreservesLongUnicodeCategoryIdentity(): void
    {
        $category = str_repeat('ä', 129);
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use ($category): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 1, 'slots' => [[
                    'nzo_id' => 'active-1', 'filename' => 'Current', 'cat' => $category,
                    'status' => 'Downloading',
                ]]]]);
            }
            return self::json(['history' => ['slots' => []]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'),
            ['username' => '', 'secret' => 'test-key'],
        );
        self::assertSame($category, $batch->items[0]['category']);
    }

    public function testSabLargeHistoryReadsOnlyRecentHeadsDespitePersistedCursor(): void
    {
        $historyQueries = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$historyQueries): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['kbpersec' => '0', 'noofslots' => 0, 'slots' => []]]);
            }
            $historyQueries[] = $query;
            if (isset($query['status'])) {
                return self::json(['history' => ['slots' => []]]);
            }
            $archive = (string) ($query['archive'] ?? '0');
            $start = (int) ($query['start'] ?? 0);
            if ($archive === '1') {
                return self::json(['history' => ['last_history_update' => 40, 'noofslots' => 0]]);
            }
            return self::json(['history' => [
                'last_history_update' => 41, 'noofslots' => 500000,
                'slots' => [[
                    'nzo_id' => $start === 0 ? 'done-new' : 'done-old',
                    'name' => $start === 0 ? 'New' : 'Old', 'category' => 'tv',
                    'status' => 'Completed', 'downloaded' => 100,
                    'completed' => $start === 0 ? 2000 : 1000,
                ]],
            ]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'),
            ['username' => '', 'secret' => 'test-key'],
            ['sab_history' => [
                'normal' => ['offset' => 75, 'generation' => 40],
                'archived' => ['offset' => 0, 'generation' => 40],
            ]],
        );

        self::assertCount(1, $batch->completions);
        self::assertSame('done-new', $batch->completions[0]['source_id']);
        self::assertSame(null, $batch->cursor['history']['error']);
        self::assertTrue($batch->complete);
        self::assertCount(3, $historyQueries);
        self::assertSame(['0', '0', '0'], array_column($historyQueries, 'start'));
        self::assertSame(['1001', '20', '20'], array_column($historyQueries, 'limit'));
        self::assertArrayNotHasKey('last_history_update', $historyQueries[0]);
        self::assertArrayNotHasKey('last_history_update', $historyQueries[1]);
    }

    public function testSabDeferredBackfillKeepsProcessingVisibleFromRegularHead(): void
    {
        $historyQueries = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$historyQueries): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['kbpersec' => 0, 'noofslots' => 0, 'slots' => []]]);
            }
            $historyQueries[] = $query;
            if (isset($query['last_history_update'])) {
                return self::json(['history' => false]);
            }
            if (($query['archive'] ?? '') === '1') {
                return self::json(['history' => ['last_history_update' => 40, 'noofslots' => 0, 'slots' => []]]);
            }

            if (isset($query['status'])) {
                return self::json(['history' => ['slots' => [[
                    'nzo_id' => 'processing-1', 'name' => 'Unpacking', 'category' => 'tv',
                    'status' => 'Extracting', 'downloaded' => 50,
                ]]]]);
            }

            return self::json(['history' => [
                'last_history_update' => 40, 'noofslots' => 200,
                'slots' => [
                    [
                        'nzo_id' => 'done-1', 'name' => 'Done', 'category' => 'tv',
                        'status' => 'Completed', 'downloaded' => 100, 'completed' => 1234,
                    ],
                    [
                        'nzo_id' => 'processing-1', 'name' => 'Unpacking', 'category' => 'tv',
                        'status' => 'Extracting', 'downloaded' => 50,
                    ],
                ],
            ]]);
        });
        $provider = new SabnzbdProvider($transport);
        $connection = $this->connection('sabnzbd', 'http://sab.test');
        $credentials = ['username' => '', 'secret' => 'test-key'];

        $first = $provider->collect($connection, $credentials);
        self::assertCount(1, $first->completions);
        $next = $first;
        for ($poll = 0; $poll < 3; ++$poll) {
            $next = $provider->collect($connection, $credentials, $next->cursor);
            self::assertSame([], $next->completions);
            self::assertSame(['processing'], array_column($next->items, 'state'));
            self::assertSame($first->cursor, $next->cursor);
            self::assertTrue($next->complete);
        }
        self::assertSame(['0', '0', '0', '0', '0', '0'], array_column($historyQueries, 'start'));
        foreach ($historyQueries as $query) {
            self::assertArrayNotHasKey('last_history_update', $query);
        }
    }

    public function testSabTerminalFailureIsHistoryWhileOtherErrorsRemainActionable(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 2, 'slots' => [
                    ['nzo_id' => 'failed-1', 'filename' => 'Failed queue copy', 'status' => 'Failed'],
                    ['nzo_id' => 'retry-1', 'filename' => 'Retrying', 'status' => 'Downloading'],
                ]]]);
            }
            if (($query['archive'] ?? '') === '1') {
                return self::json(['history' => ['slots' => []]]);
            }
            return self::json(['history' => ['slots' => [
                ['nzo_id' => 'failed-1', 'name' => 'Failed transfer', 'status' => 'Failed', 'completed' => 1800000000],
                ['nzo_id' => 'retry-1', 'name' => 'Old failure', 'status' => 'Failed', 'completed' => 1799999000],
                ['nzo_id' => 'repair-1', 'name' => 'Repairing', 'status' => 'Repairing'],
                ['nzo_id' => 'unknown-1', 'name' => 'Unknown', 'status' => 'Mystery'],
            ]]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'),
            ['username' => '', 'secret' => 'test-key'],
        );

        self::assertSame(['retry-1', 'repair-1', 'unknown-1'], array_column($batch->items, 'source_id'));
        self::assertSame(['downloading', 'processing', 'error'], array_column($batch->items, 'state'));
        self::assertSame(['failed-1'], array_column($batch->completions, 'source_id'));
        self::assertSame('failed', $batch->completions[0]['state']);
        self::assertSame(1800000000, $batch->completions[0]['completed_at']);
    }

    public function testSabRecentCompletionDoesNotDisplaceCurrentQueueOrProcessing(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                $slots = [];
                for ($index = 0; $index < 999; ++$index) {
                    $slots[] = ['nzo_id' => 'queued-' . $index, 'status' => 'Queued'];
                }
                return self::json(['queue' => ['kbpersec' => 0, 'noofslots' => 999, 'slots' => $slots]]);
            }
            if (($query['archive'] ?? '') === '1') {
                return self::json(['history' => ['slots' => []]]);
            }
            return self::json(['history' => ['noofslots' => 500000, 'slots' => [
                ['nzo_id' => 'processing-1', 'name' => 'Unpacking', 'status' => 'Extracting'],
                ['nzo_id' => 'done-1', 'name' => 'Done', 'status' => 'Completed', 'completed' => 1234],
            ]]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'),
            ['username' => '', 'secret' => 'test-key'],
        );

        self::assertCount(1000, $batch->items);
        self::assertSame('processing-1', $batch->items[999]['source_id']);
        self::assertCount(1, $batch->completions);
        self::assertSame('done-1', $batch->completions[0]['source_id']);
        self::assertTrue($batch->complete);
    }

    public function testSabRejectsAnApiKeyErrorWithoutExposingTheKey(): void
    {
        $transport = new FixtureDownloaderTransport(static fn (): HttpResponse => self::json(['error' => 'API Key Incorrect']));

        try {
            (new SabnzbdProvider($transport))->testConnection(
                $this->connection('sabnzbd', 'http://sab.test'),
                ['username' => '', 'secret' => 'private-test-key'],
            );
            self::fail('Expected authentication to fail.');
        } catch (ProviderException $exception) {
            self::assertSame('authentication_failed', $exception->reason);
            self::assertStringNotContainsString('private-test-key', $exception->getMessage());
            self::assertStringNotContainsString('API Key Incorrect', $exception->getMessage());
        }
    }

    public function testQbConnectionTestLogsInAndDiscoversCategoriesAndTags(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                self::assertSame('POST', $request->method);
                self::assertStringContainsString('username=admin', (string) $request->body);
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=session-1234567890; Path=/; HttpOnly']]);
            }
            self::assertContains('Cookie: SID=session-1234567890', $request->headers);

            return match (true) {
                str_ends_with($path, '/app/version') => new HttpResponse(200, 'v5.1.2'),
                str_ends_with($path, '/app/webapiVersion') => new HttpResponse(200, '2.11.4'),
                str_ends_with($path, '/torrents/categories') => self::json(['tv' => ['name' => 'tv'], 'movies' => ['name' => 'movies']]),
                str_ends_with($path, '/torrents/tags') => self::json(['sonarr', 'radarr']),
                default => self::fail('Unexpected qB operation'),
            };
        });

        $result = (new QbittorrentProvider($transport, static fn (): int => 1000))->testConnection(
            $this->connection('qbittorrent', 'http://qb.test/qb'),
            ['username' => 'admin', 'secret' => 'password'],
        );

        self::assertSame('5.1.2 (Web API 2.11.4)', $result['version']);
        self::assertSame(['', 'movies', 'tv'], $result['categories']);
        self::assertSame(['radarr', 'sonarr'], $result['tags']);
    }

    public function testQbConnectionAcceptsVersion52NoContentLoginAndBase64Sid(): void
    {
        $sid = 'ab+c/defghijklmnopqrstuvwxyz1234';
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use ($sid): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '', ['set-cookie' => ['QBT_SID_8080=' . $sid . '; Path=/; HttpOnly']]);
            }
            self::assertContains('Cookie: QBT_SID_8080=' . $sid, $request->headers);

            return match (true) {
                str_ends_with($path, '/app/version') => new HttpResponse(200, 'v5.2.0'),
                str_ends_with($path, '/app/webapiVersion') => new HttpResponse(200, '2.15.0'),
                default => self::json([]),
            };
        });

        $result = (new QbittorrentProvider($transport))->testConnection(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );

        self::assertSame('5.2.0 (Web API 2.15.0)', $result['version']);
    }

    public function testQbConnectionAllowsVersion52TrustedIpReadsWithoutCookie(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            foreach ($request->headers as $header) {
                self::assertStringNotContainsString('Cookie:', $header);
            }

            return match (true) {
                str_ends_with($path, '/app/version') => new HttpResponse(200, 'v5.2.3'),
                str_ends_with($path, '/app/webapiVersion') => new HttpResponse(200, '2.15.1'),
                default => self::json([]),
            };
        });

        $result = (new QbittorrentProvider($transport))->testConnection(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'ignored', 'secret' => 'ignored'],
        );

        self::assertSame('5.2.3 (Web API 2.15.1)', $result['version']);
    }

    public function testDiscoveredChoicesAreBounded(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=session-1234567890; Path=/']]);
            }
            if (str_ends_with($path, '/app/version')) {
                return new HttpResponse(200, 'v5.1.2');
            }
            if (str_ends_with($path, '/app/webapiVersion')) {
                return new HttpResponse(200, '2.11.4');
            }
            if (str_ends_with($path, '/torrents/categories')) {
                $categories = [];
                for ($index = 0; $index < 400; ++$index) {
                    $categories['category-' . $index] = ['name' => 'category-' . $index];
                }
                return self::json($categories);
            }
            $tags = [];
            for ($index = 0; $index < 400; ++$index) {
                $tags[] = 'tag-' . $index;
            }
            return self::json($tags);
        });

        $result = (new QbittorrentProvider($transport))->testConnection(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );

        self::assertCount(250, $result['categories']);
        self::assertCount(250, $result['tags']);
        self::assertSame('', $result['categories'][0]);
    }

    public function testQbPreservesLongUnicodeCategoryAndTagIdentity(): void
    {
        $category = str_repeat('ä', 129);
        $tag = str_repeat('t', 129);
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use ($category, $tag): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([]);
            }
            return self::json([[
                'hash' => str_repeat('a', 40), 'name' => 'Current', 'category' => $category,
                'tags' => $tag, 'state' => 'downloading', 'progress' => 0.5,
                'size' => 10, 'completed' => 5, 'dlspeed' => 1,
            ]]);
        });

        $batch = (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
        self::assertSame($category, $batch->items[0]['category']);
        self::assertSame([$tag], $batch->items[0]['tags']);
    }

    public function testQbRejectsOversizedCategoryInsteadOfTruncatingItsIdentity(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([]);
            }
            return self::json([[
                'hash' => str_repeat('a', 40), 'name' => 'Current',
                'category' => str_repeat('x', 256) . 'a', 'tags' => '',
                'state' => 'downloading', 'progress' => 0.5,
            ]]);
        });

        $this->expectException(ProviderException::class);
        (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
    }

    public function testQbIpv6OriginIsNotDoubleWrapped(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            self::assertContains('Origin: http://[::1]:8080', $request->headers);
            self::assertContains('Referer: http://[::1]:8080/qb/', $request->headers);
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=session-1234567890; Path=/']]);
            }
            return match (true) {
                str_ends_with($path, '/app/version') => new HttpResponse(200, 'v5.1.2'),
                str_ends_with($path, '/app/webapiVersion') => new HttpResponse(200, '2.11.4'),
                default => self::json([]),
            };
        });

        $result = (new QbittorrentProvider($transport))->testConnection(
            $this->connection('qbittorrent', 'http://[::1]:8080/qb'),
            ['username' => 'admin', 'secret' => 'password'],
        );

        self::assertSame('5.1.2 (Web API 2.11.4)', $result['version']);
    }

    public function testQbCollectReusesSidAndRenewsItOnceAfterRejection(): void
    {
        $loginCount = 0;
        $rejectOld = true;
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$loginCount, &$rejectOld): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                ++$loginCount;
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=fresh-session-123456; Path=/']]);
            }
            if ($rejectOld && in_array('Cookie: SID=old-session-12345678', $request->headers, true)) {
                return new HttpResponse(403, 'Forbidden');
            }
            $rejectOld = false;
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 4096]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([
                    ['hash' => 'BBBB', 'name' => 'Finished', 'category' => 'movies', 'tags' => 'radarr, favourite', 'state' => 'stoppedUP', 'progress' => 1, 'total_size' => 300, 'completed' => 300, 'dlspeed' => 0, 'eta' => 8640000, 'completion_on' => 900, 'priority' => -1],
                ]);
            }
            self::assertSame('all', $query['filter']);
            self::assertSame('progress', $query['sort']);
            self::assertSame('false', $query['reverse']);
            self::assertSame('1001', $query['limit']);
            self::assertSame('0', $query['offset']);
            return self::json([
                ['hash' => 'AAAA', 'name' => str_repeat('a', 1023) . '€', 'category' => 'tv', 'tags' => 'sonarr', 'state' => 'stalledDL', 'progress' => 0.25, 'total_size' => 1000, 'completed' => 250, 'dlspeed' => 20, 'eta' => 40, 'priority' => 2],
                ['hash' => 'BBBB', 'name' => 'Finished', 'category' => 'movies', 'tags' => 'radarr', 'state' => 'stoppedUP', 'progress' => 1, 'total_size' => 300, 'completed' => 300, 'dlspeed' => 0, 'completion_on' => 900],
            ]);
        });
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);
        $connection = $this->connection('qbittorrent', 'http://qb.test/qb');
        $session = [
            'sid' => 'old-session-12345678', 'expires_at' => 1500,
            'endpoint_hash' => hash('sha256', 'http://qb.test/qb'),
        ];

        $batch = $provider->collect($connection, ['username' => 'admin', 'secret' => 'password'], [], $session);

        self::assertSame(1, $loginCount);
        self::assertSame(4096.0, $batch->speed);
        self::assertCount(1, $batch->items);
        self::assertSame('stalled', $batch->items[0]['state']);
        self::assertSame(25.0, $batch->items[0]['progress']);
        self::assertSame(['sonarr'], $batch->items[0]['tags']);
        self::assertSame(1023, strlen($batch->items[0]['title']));
        self::assertNotFalse(json_encode($batch->items, JSON_THROW_ON_ERROR));
        self::assertCount(1, $batch->completions);
        self::assertSame('bbbb', $batch->completions[0]['source_id']);
        self::assertSame(['radarr', 'favourite'], $batch->completions[0]['tags']);
        self::assertSame('fresh-session-123456', $batch->session['sid']);
        self::assertSame(2800, $batch->session['expires_at']);

        $provider->collect($connection, ['username' => 'admin', 'secret' => 'password'], [], $batch->session);
        self::assertSame(1, $loginCount);
    }

    public function testQbCollectReusesBoundedTrustedIpSessionWithoutCookie(): void
    {
        $loginCount = 0;
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$loginCount): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                ++$loginCount;
                return new HttpResponse(204, '');
            }
            foreach ($request->headers as $header) {
                self::assertStringNotContainsString('Cookie:', $header);
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            return self::json([]);
        });
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);
        $connection = $this->connection('qbittorrent', 'http://qb.test/qb');

        $first = $provider->collect($connection, ['username' => 'ignored', 'secret' => 'ignored']);
        $second = $provider->collect($connection, ['username' => 'ignored', 'secret' => 'ignored'], [], $first->session);

        self::assertSame(1, $loginCount);
        self::assertSame('', $second->session['sid']);
        self::assertSame('', $second->session['cookie_name']);
        self::assertSame(2800, $second->session['expires_at']);
        self::assertSame(hash('sha256', 'http://qb.test/qb'), $second->session['endpoint_hash']);
    }

    public function testQbIgnoresPersistedCompletionOffsetAndReadsRecentHead(): void
    {
        $completionOffsets = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$completionOffsets): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') !== 'completed') {
                return self::json([]);
            }
            $offset = (int) ($query['offset'] ?? 0);
            $completionOffsets[] = $offset;
            return self::json([[
                'hash' => $offset === 0 ? 'AAAA' : 'BBBB',
                'name' => $offset === 0 ? 'Newest' : 'Backfill',
                'state' => $offset === 0 ? 'checkingUP' : 'stoppedUP', 'progress' => 1, 'completion_on' => $offset === 0 ? 2000 : 1000,
                'total_size' => 50, 'completed' => 50, 'dlspeed' => 0,
            ]]);
        });
        $connection = $this->connection('qbittorrent', 'http://qb.test/qb');
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500, 'endpoint_hash' => hash('sha256', 'http://qb.test/qb')];

        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect(
            $connection,
            ['username' => 'admin', 'secret' => 'password'],
            ['completion_offset' => 75],
            $session,
        );

        self::assertSame([0], $completionOffsets);
        self::assertSame(['aaaa'], array_column($batch->completions, 'source_id'));
        self::assertSame(null, $batch->cursor['history']['error']);
        self::assertTrue($batch->complete);
        self::assertSame(1500, $batch->session['expires_at']);
    }

    public function testQbFindsCheckingBeyondLargeSeedLibraryWithoutPartialNotice(): void
    {
        $queries = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$queries): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            if (str_ends_with($path, '/app/version')) {
                return new HttpResponse(200, 'v5.2.0');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $queries[] = $query;
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([]);
            }
            if (($query['filter'] ?? '') === 'checking') {
                return self::json([[
                    'hash' => str_repeat('f', 40), 'name' => 'Checking', 'state' => 'checkingUP',
                    'progress' => 1, 'size' => 10, 'completed' => 10, 'dlspeed' => 0,
                ]]);
            }
            if (($query['filter'] ?? '') === 'moving') {
                return self::json([[
                    'hash' => str_repeat('e', 40), 'name' => 'Moving', 'state' => 'moving',
                    'progress' => 1, 'size' => 10, 'completed' => 10, 'dlspeed' => 0,
                ]]);
            }
            if (($query['filter'] ?? '') === 'errored') {
                return self::json([[
                    'hash' => str_repeat('d', 40), 'name' => 'Missing files', 'state' => 'missingFiles',
                    'progress' => 1, 'size' => 10, 'completed' => 10, 'dlspeed' => 0,
                ]]);
            }
            self::assertSame('all', $query['filter']);
            self::assertSame('1001', $query['limit']);
            $rows = [];
            for ($index = 0; $index < 1001; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Seed ' . $index,
                    'state' => 'stoppedUP', 'progress' => 1, 'size' => 10,
                    'completed' => 10, 'dlspeed' => 0,
                ];
            }
            return self::json($rows);
        });

        $batch = (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
        self::assertSame(['checking', 'processing', 'error'], array_column($batch->items, 'state'));
        self::assertTrue($batch->complete);
        self::assertSame(['all', 'checking', 'moving', 'errored', 'completed'], array_column($queries, 'filter'));
    }

    public function testQbHealthyLargeSeedLibraryHasNoPartialNotice(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            if (str_ends_with($path, '/app/version')) {
                return new HttpResponse(200, 'v5.2.0');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') !== 'all') {
                return self::json([]);
            }
            $rows = [];
            for ($index = 0; $index < 1001; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Seed ' . $index,
                    'state' => 'stoppedUP', 'progress' => 1,
                ];
            }
            return self::json($rows);
        });

        $batch = (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
        self::assertSame([], $batch->items);
        self::assertTrue($batch->complete);
    }

    public function testQbExactlyOneThousandCurrentItemsAreComplete(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            if (str_ends_with($path, '/app/version')) {
                self::fail('No version lookup is needed below the lookahead limit.');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([]);
            }
            self::assertSame('1001', $query['limit']);
            $rows = [];
            for ($index = 0; $index < 1000; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Queued ' . $index,
                    'state' => 'queuedDL', 'progress' => 0,
                ];
            }
            return self::json($rows);
        });

        $batch = (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
        self::assertCount(1000, $batch->items);
        self::assertTrue($batch->complete);
    }

    public function testQbLegacyFullProgressBoundaryDoesNotClaimComplete(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(204, '');
            }
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            if (str_ends_with($path, '/app/version')) {
                return new HttpResponse(200, 'v4.6.0');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                return self::json([]);
            }
            $rows = [];
            for ($index = 0; $index < 1001; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Seed ' . $index,
                    'state' => 'stoppedUP', 'progress' => 1, 'size' => 10,
                    'completed' => 10, 'dlspeed' => 0,
                ];
            }
            return self::json($rows);
        });

        $batch = (new QbittorrentProvider($transport))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
        );
        self::assertFalse($batch->complete);
    }

    public function testQbLargePagesStayBoundedAndInvalidNumbersBecomeNull(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => -1]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $rows = [];
            $rowCount = ($query['filter'] ?? '') === 'completed' ? 100 : 1001;
            for ($index = 0; $index < $rowCount; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Item ' . $index,
                    'state' => $index === 0 ? 'futureState' : (($query['filter'] ?? '') === 'completed' ? 'stoppedUP' : 'queuedDL'),
                    'progress' => $index === 0 ? -0.5 : (($query['filter'] ?? '') === 'completed' ? 1 : 0),
                    'total_size' => $index === 0 ? -1 : 100,
                    'completed' => $index === 0 ? -1 : 0,
                    'dlspeed' => $index === 0 ? -1 : 0,
                    'eta' => $index === 0 ? -1 : 10,
                    'priority' => $index === 0 ? -1 : 1,
                    'completion_on' => ($query['filter'] ?? '') === 'completed' ? 900 : 0,
                ];
            }
            return self::json($rows);
        });
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500, 'endpoint_hash' => hash('sha256', 'http://qb.test')];

        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'password'],
            ['completion_watermark' => 900],
            $session,
        );

        self::assertCount(1000, $batch->items);
        self::assertCount(19, $batch->completions);
        self::assertSame('error', $batch->items[0]['state']);
        self::assertNull($batch->items[0]['progress']);
        self::assertNull($batch->items[0]['size']);
        self::assertNull($batch->items[0]['downloaded']);
        self::assertNull($batch->items[0]['speed']);
        self::assertNull($batch->items[0]['eta']);
        self::assertNull($batch->items[0]['position']);
        self::assertNull($batch->speed);
        self::assertSame(null, $batch->cursor['history']['error']);
        self::assertFalse($batch->complete);
    }

    public function testQbLargeHistoryNeverStartsReconciliation(): void
    {
        $completionOffsets = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$completionOffsets): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') !== 'completed') {
                return self::json([]);
            }
            $completionOffsets[] = (int) ($query['offset'] ?? -1);
            self::assertSame('20', $query['limit']);
            $rows = [];
            for ($index = 0; $index < 20; ++$index) {
                $rows[] = [
                    'hash' => sprintf('%040x', $index + 1), 'name' => 'Old ' . $index,
                    'state' => 'stoppedUP', 'progress' => 1, 'completion_on' => 800 - $index,
                    'total_size' => 50, 'completed' => 50, 'dlspeed' => 0,
                ];
            }
            return self::json($rows);
        });
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);
        $connection = $this->connection('qbittorrent', 'http://qb.test');
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500, 'endpoint_hash' => hash('sha256', 'http://qb.test')];

        $first = $provider->collect(
            $connection,
            ['username' => 'admin', 'secret' => 'password'],
            ['completion_offset' => 75, 'completion_watermark' => 900, 'completion_reconcile' => true],
            $session,
        );
        $second = $provider->collect(
            $connection,
            ['username' => 'admin', 'secret' => 'password'],
            $first->cursor,
            $first->session,
        );
        self::assertSame([0], $completionOffsets);
        self::assertCount(20, $first->completions);
        self::assertSame([], $second->completions);
        self::assertSame($first->cursor, $second->cursor);
        self::assertTrue($first->complete);
        self::assertTrue($second->complete);
    }

    public function testQbRejectsBadLoginAndUnsupportedVersionsSafely(): void
    {
        $badLogin = new FixtureDownloaderTransport(static fn (): HttpResponse => new HttpResponse(200, 'Fails.'));
        try {
            (new QbittorrentProvider($badLogin))->testConnection(
                $this->connection('qbittorrent', 'http://qb.test'),
                ['username' => 'admin', 'secret' => 'private-password'],
            );
            self::fail('Expected authentication to fail.');
        } catch (ProviderException $exception) {
            self::assertSame('authentication_failed', $exception->reason);
            self::assertStringNotContainsString('private-password', $exception->getMessage());
        }

        $unsupported = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return new HttpResponse(200, 'Ok.', ['set-cookie' => ['SID=session-1234567890; Path=/']]);
            }
            return match (true) {
                str_ends_with($path, '/app/version') => new HttpResponse(200, 'v6.0.0'),
                str_ends_with($path, '/app/webapiVersion') => new HttpResponse(200, '3.0'),
                default => self::json([]),
            };
        });
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('This client API version is not supported.');
        (new QbittorrentProvider($unsupported))->testConnection(
            $this->connection('qbittorrent', 'http://qb.test'),
            ['username' => 'admin', 'secret' => 'private-password'],
        );
    }

    public function testSabHistoryFailureKeepsLiveQueueAndBacksOff(): void
    {
        $historyRequests = 0;
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$historyRequests): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 1, 'kbpersec' => 8, 'slots' => [
                    ['nzo_id' => 'active', 'status' => 'Downloading'],
                ]]]);
            }
            ++$historyRequests;
            return new HttpResponse(500, 'unavailable');
        });
        $provider = new SabnzbdProvider($transport);
        $connection = $this->connection('sabnzbd', 'http://sab.test');
        $credentials = ['username' => '', 'secret' => 'key'];

        $first = $provider->collect($connection, $credentials);
        $second = $provider->collect($connection, $credentials, $first->cursor);

        self::assertSame(['active'], array_column($first->items, 'source_id'));
        self::assertSame(8192.0, $first->speed);
        self::assertFalse($first->complete);
        self::assertSame([], $first->completions);
        self::assertSame('request_failed', $first->history['error']);
        self::assertSame(1, $first->history['failure_count']);
        self::assertSame(2, $second->history['failure_count']);
        self::assertSame(2, $historyRequests);
    }

    public function testSabSeeksPastUnselectedRecentResults(): void
    {
        $offsets = [];
        $timeouts = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$offsets, &$timeouts): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 0, 'slots' => []]]);
            }
            $timeouts[] = $request->timeoutMs;
            if (isset($query['status'])) {
                return self::json(['history' => ['slots' => []]]);
            }
            if (($query['archive'] ?? '') === '1') {
                return self::json(['history' => ['slots' => []]]);
            }
            $offset = (int) $query['start'];
            $offsets[] = $offset;
            if ($offset === 0) {
                return self::json(['history' => ['slots' => array_map(static fn (int $index): array => [
                    'nzo_id' => 'other-' . $index, 'category' => 'movies', 'status' => 'Completed', 'completed' => 1000,
                ], range(1, 20))]]);
            }
            return self::json(['history' => ['slots' => [
                ['nzo_id' => 'selected', 'category' => 'tv', 'status' => 'Completed', 'completed' => 900],
            ]]]);
        });
        $connection = new Connection('test-id', 'sabnzbd', 'Test', 'http://sab.test',
            filterMode: 'selected', categories: ['tv']);

        $batch = (new SabnzbdProvider($transport))->collect($connection, ['username' => '', 'secret' => 'key']);

        self::assertSame([0, 20], $offsets);
        self::assertCount(4, $timeouts);
        self::assertTrue(max($timeouts) <= 5000 && min($timeouts) > 0);
        self::assertSame(['selected'], array_column($batch->completions, 'source_id'));
        self::assertNull($batch->history['error']);
    }

    public function testSabArchivedHistoryFailureRetainsConfirmedHeadAndLiveQueue(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 1, 'kbpersec' => 4, 'slots' => [
                    ['nzo_id' => 'active', 'status' => 'Downloading'],
                ]]]);
            }
            if (($query['archive'] ?? '') === '1') {
                return new HttpResponse(500, 'unavailable');
            }
            if (isset($query['status'])) {
                return self::json(['history' => ['slots' => [
                    ['nzo_id' => 'processing', 'status' => 'Extracting'],
                ]]]);
            }
            return self::json(['history' => ['slots' => [
                ['nzo_id' => 'processing', 'status' => 'Extracting'],
                ['nzo_id' => 'done', 'status' => 'Completed', 'completed' => 1000],
            ]]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'), ['username' => '', 'secret' => 'key'],
        );

        self::assertSame(['active', 'processing'], array_column($batch->items, 'source_id'));
        self::assertSame(['done'], array_column($batch->completions, 'source_id'));
        self::assertSame(4096.0, $batch->speed);
        self::assertTrue($batch->complete);
        self::assertSame('request_failed', $batch->history['error']);
    }

    public function testQbHistoryFailureKeepsLiveInventoryAndVersionProbe(): void
    {
        $filters = [];
        $versionRequests = 0;
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$filters, &$versionRequests): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 4096]);
            }
            if (str_ends_with($path, '/app/version')) {
                ++$versionRequests;
                return new HttpResponse(200, 'v5.2.0');
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $filter = (string) ($query['filter'] ?? '');
            $filters[] = $filter;
            if ($filter === 'completed') {
                return new HttpResponse(500, 'unavailable');
            }
            if ($filter !== 'all') {
                return self::json([]);
            }
            $rows = [];
            for ($index = 0; $index < 1001; ++$index) {
                $rows[] = ['hash' => sprintf('%040x', $index + 1), 'state' => 'stoppedUP', 'progress' => 1];
            }
            return self::json($rows);
        });
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);
        $connection = $this->connection('qbittorrent', 'http://qb.test');
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500,
            'endpoint_hash' => hash('sha256', 'http://qb.test')];

        $first = $provider->collect($connection, ['username' => 'a', 'secret' => 'b'], [], $session);
        $second = $provider->collect($connection, ['username' => 'a', 'secret' => 'b'], $first->cursor, $first->session);

        self::assertTrue($first->complete);
        self::assertSame(4096.0, $first->speed);
        self::assertSame([], $first->completions);
        self::assertSame('request_failed', $first->history['error']);
        self::assertSame([], $second->completions);
        self::assertSame(2, $versionRequests);
        self::assertSame(1, count(array_filter($filters, static fn (string $filter): bool => $filter === 'completed')));
    }

    public function testQbSeeksPastUnselectedRecentResults(): void
    {
        $offsets = [];
        $timeouts = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$offsets, &$timeouts): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') !== 'completed') {
                return self::json([]);
            }
            $timeouts[] = $request->timeoutMs;
            $offset = (int) $query['offset'];
            $offsets[] = $offset;
            if ($offset === 0) {
                return self::json(array_map(static fn (int $index): array => [
                    'hash' => sprintf('%040x', $index), 'category' => 'movies', 'state' => 'stoppedUP',
                    'progress' => 1, 'completion_on' => 1000 - $index,
                ], range(1, 20)));
            }
            return self::json([['hash' => str_repeat('f', 40), 'category' => 'tv',
                'state' => 'stoppedUP', 'progress' => 1, 'completion_on' => 900]]);
        });
        $connection = new Connection('test-id', 'qbittorrent', 'Test', 'http://qb.test',
            filterMode: 'selected', categories: ['tv']);
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500,
            'endpoint_hash' => hash('sha256', 'http://qb.test')];

        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect(
            $connection, ['username' => 'a', 'secret' => 'b'], [], $session,
        );

        self::assertSame([0, 20], $offsets);
        self::assertCount(2, $timeouts);
        self::assertTrue(max($timeouts) <= 5000 && min($timeouts) > 0);
        self::assertSame([str_repeat('f', 40)], array_column($batch->completions, 'source_id'));
        self::assertNull($batch->history['error']);
    }

    public function testSabStatusReadFindsProcessingBehindTerminalHistory(): void
    {
        $historyQueries = [];
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$historyQueries): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 0, 'kbpersec' => 0, 'slots' => []]]);
            }
            $historyQueries[] = $query;
            if (isset($query['status'])) {
                return self::json(['history' => ['slots' => [
                    ['nzo_id' => 'processing', 'status' => 'Extracting', 'category' => 'tv'],
                ]]]);
            }
            return self::json(['history' => ['slots' => array_map(static fn (int $index): array => [
                'nzo_id' => 'done-' . $index, 'status' => 'Completed', 'completed' => 1000,
            ], range(1, 20))]]);
        });
        $connection = $this->connection('sabnzbd', 'http://sab.test');
        $cursor = ['history' => ['next_due_at' => time() + 60, 'failure_count' => 0]];

        $batch = (new SabnzbdProvider($transport))->collect($connection, ['username' => '', 'secret' => 'key'], $cursor);

        self::assertSame(['processing'], array_column($batch->items, 'source_id'));
        self::assertSame([], $batch->completions);
        self::assertTrue($batch->complete);
        self::assertCount(1, $historyQueries);
        self::assertSame('1001', $historyQueries[0]['limit']);
        self::assertStringContainsString('Extracting', $historyQueries[0]['status']);
    }

    public function testSabMalformedLaterProcessingRowMarksLiveInventoryIncomplete(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['mode'] ?? '') === 'queue') {
                return self::json(['queue' => ['noofslots' => 1, 'kbpersec' => 4, 'slots' => [
                    ['nzo_id' => 'active', 'status' => 'Downloading'],
                ]]]);
            }
            self::assertArrayHasKey('status', $query);
            return self::json(['history' => ['slots' => [
                ['nzo_id' => 'processing', 'status' => 'Extracting', 'category' => 'tv'],
                ['nzo_id' => 'bad', 'status' => 'Extracting', 'category' => "\x01"],
            ]]]);
        });

        $batch = (new SabnzbdProvider($transport))->collect(
            $this->connection('sabnzbd', 'http://sab.test'), ['username' => '', 'secret' => 'key'],
        );

        self::assertSame(['active', 'processing'], array_column($batch->items, 'source_id'));
        self::assertSame([], $batch->completions);
        self::assertSame(4096.0, $batch->speed);
        self::assertFalse($batch->complete);
        self::assertSame('invalid_response', $batch->history['error']);
    }

    public function testQbObservedCompletionSurvivesHistoryFailureAndRemoval(): void
    {
        $inventoryReads = 0;
        $historyReads = 0;
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request) use (&$inventoryReads, &$historyReads): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                ++$historyReads;
                return new HttpResponse(500, 'unavailable');
            }
            ++$inventoryReads;
            return self::json($inventoryReads === 1 ? [[
                'hash' => 'ABCD', 'state' => 'stoppedUP', 'progress' => 1,
                'completion_on' => 900, 'category' => 'tv',
            ]] : []);
        });
        $provider = new QbittorrentProvider($transport, static fn (): int => 1000);
        $connection = $this->connection('qbittorrent', 'http://qb.test');
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500,
            'endpoint_hash' => hash('sha256', 'http://qb.test')];

        $first = $provider->collect($connection, ['username' => 'a', 'secret' => 'b'], [], $session);
        $second = $provider->collect($connection, ['username' => 'a', 'secret' => 'b'], $first->cursor, $first->session);

        self::assertSame(['abcd'], array_column($first->completions, 'source_id'));
        self::assertSame('request_failed', $first->history['error']);
        self::assertSame([], $second->completions);
        self::assertSame(1, $historyReads);
        self::assertTrue($first->complete && $second->complete);
    }

    public function testQbObservedCompletionsKeepLatestHundredMatchingResults(): void
    {
        $transport = new FixtureDownloaderTransport(static function (HttpRequest $request): HttpResponse {
            $path = (string) parse_url($request->url, PHP_URL_PATH);
            if (str_ends_with($path, '/transfer/info')) {
                return self::json(['dl_info_speed' => 0]);
            }
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            if (($query['filter'] ?? '') === 'completed') {
                self::fail('History is not due.');
            }
            $rows = [];
            for ($index = 1; $index <= 125; ++$index) {
                $rows[] = ['hash' => sprintf('%040x', $index), 'state' => 'stoppedUP', 'progress' => 1,
                    'completion_on' => $index, 'category' => $index <= 5 ? 'movies' : 'tv'];
            }
            $rows[] = ['hash' => sprintf('%040x', 125), 'state' => 'stoppedUP', 'progress' => 1,
                'completion_on' => 1, 'category' => 'tv'];
            return self::json($rows);
        });
        $connection = new Connection('test-id', 'qbittorrent', 'Test', 'http://qb.test',
            filterMode: 'selected', categories: ['tv']);
        $session = ['sid' => 'valid-session-12345', 'expires_at' => 1500,
            'endpoint_hash' => hash('sha256', 'http://qb.test')];
        $cursor = ['history' => ['next_due_at' => 1060, 'failure_count' => 0]];

        $batch = (new QbittorrentProvider($transport, static fn (): int => 1000))->collect(
            $connection, ['username' => 'a', 'secret' => 'b'], $cursor, $session,
        );

        self::assertCount(100, $batch->completions);
        self::assertSame(125, $batch->completions[0]['completed_at']);
        self::assertSame(26, $batch->completions[99]['completed_at']);
    }

    private function connection(string $provider, string $url): Connection
    {
        return new Connection('test-id', $provider, 'Test', $url, revision: 1, hasSecret: true);
    }

    /** @param mixed $value */
    private static function json(mixed $value): HttpResponse
    {
        return new HttpResponse(200, json_encode($value, JSON_THROW_ON_ERROR));
    }
}

final class FixtureDownloaderTransport implements DownloaderTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    private Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        return ($this->handler)($request);
    }

    public function requestMany(array $requests): array
    {
        return array_map(fn (HttpRequest $request): HttpResponse => $this->request($request), $requests);
    }
}
