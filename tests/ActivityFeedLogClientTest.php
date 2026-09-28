<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/activity-feed/src/ActivityLogClient.php';

use Mk\Modules\ActivityFeed\ActivityLogClient;
use PHPUnit\Framework\TestCase;

final class ActivityFeedLogClientTest extends TestCase
{
    public function testPagePassesStartIndexAndLimitAndMapsItems(): void
    {
        $client = new class () {
            public ?string $lastPath = null;
            public function getJson(string $path): mixed
            {
                $this->lastPath = $path;
                return [
                    'Items' => [[
                        'Date' => '2026-08-27T18:59:46.4877177Z',
                        'Name' => 'jf_test_user_1 hat die Wiedergabe gestartet',
                        'UserId' => '570852392c8f4e7e86006fa586ef9bf6',
                    ]],
                    'TotalRecordCount' => 78611,
                ];
            }
        };

        $page = (new ActivityLogClient($client))->page(50, 25);

        $this->assertStringContainsString('startIndex=50', $client->lastPath);
        $this->assertStringContainsString('limit=25', $client->lastPath);
        $this->assertSame(78611, $page['total']);
        $this->assertSame('570852392c8f4e7e86006fa586ef9bf6', $page['items'][0]['userId']);
    }

    public function testPageForwardsMinDateAsUtcIso(): void
    {
        $client = new class () {
            public ?string $lastPath = null;

            public function getJson(string $path): mixed
            {
                $this->lastPath = $path;

                return ['Items' => [], 'TotalRecordCount' => 0];
            }
        };

        (new ActivityLogClient($client))->page(0, 50, new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('Europe/Berlin')));

        $this->assertStringContainsString('minDate=2026-09-04T10%3A00%3A00.000Z', $client->lastPath);
    }

    /**
     * Fake log client serving a fixed log newest-first, mimicking Jellyfin's
     * paging so searchPage's batching and truncation behave realistically.
     */
    private function fakeLog(int $total, string $namePrefix = 'entry'): object
    {
        return new class ($total, $namePrefix) {
            public int $calls = 0;

            public function __construct(private int $total, private string $namePrefix)
            {
            }

            public function getJson(string $path): mixed
            {
                $this->calls++;
                parse_str((string) parse_url('?' . (string) parse_url($path, PHP_URL_QUERY), PHP_URL_QUERY), $params);
                $start = (int) $params['startIndex'];
                $limit = (int) $params['limit'];

                $items = [];
                for ($i = $start; $i < min($start + $limit, $this->total); $i++) {
                    $items[] = [
                        'Date' => '2026-09-1' . ($i % 10) . 'T10:00:00Z',
                        'Name' => $this->namePrefix . ' ' . $i . ($i % 3 === 0 ? ' LOGIN failed' : ' playback'),
                        'UserId' => null,
                    ];
                }

                return ['Items' => $items, 'TotalRecordCount' => $this->total];
            }
        };
    }

    public function testSearchPageFiltersByTextAcrossBatches(): void
    {
        $client = new ActivityLogClient($this->fakeLog(400));

        $result = $client->searchPage('LOGIN', null, 0, 50);

        // Indices 0, 3, 6, … carry " LOGIN failed" — 134 of 400 entries.
        $this->assertSame(134, $result['total']);
        $this->assertFalse($result['truncated']);
        $this->assertCount(50, $result['items']);
    }

    public function testSearchPageMatchesCaseInsensitiveAndPaginates(): void
    {
        $log = $this->fakeLog(120);
        $client = new ActivityLogClient($log);

        $page1 = $client->searchPage('login failed', null, 0, 2);
        $this->assertSame(2, count($page1['items']));
        $this->assertFalse($page1['truncated']);
        $this->assertGreaterThanOrEqual(2, $page1['total']);

        // Every match contains the needle regardless of case.
        foreach ($page1['items'] as $item) {
            $this->assertStringContainsStringIgnoringCase('login failed', $item['name']);
        }

        // Offsets slice into the same result set.
        $page2 = $client->searchPage('login failed', null, 2, 2);
        $this->assertNotSame($page1['items'][0]['name'], $page2['items'][0]['name']);
    }

    public function testSearchPageReportsTruncationAtWindowCap(): void
    {
        $log = new class () {
            public function getJson(string $path): mixed
            {
                parse_str((string) parse_url('?' . (string) parse_url($path, PHP_URL_QUERY), PHP_URL_QUERY), $params);
                $start = (int) $params['startIndex'];

                // Always returns a full batch of common entries so the window
                // cap is the only end condition.
                $items = [];
                for ($i = $start; $i < $start + 250; $i++) {
                    $items[] = ['Date' => '2026-09-10T10:00:00Z', 'Name' => 'noise ' . $i, 'UserId' => null];
                }

                return ['Items' => $items, 'TotalRecordCount' => 999999];
            }
        };

        $result = (new ActivityLogClient($log))->searchPage('nothing-matches-this', null, 0, 50);

        $this->assertSame(0, $result['total']);
        $this->assertTrue($result['truncated']);
    }

    public function testSearchPageWithEmptyQueryStopsAtEndOfLog(): void
    {
        $log = $this->fakeLog(80);
        $client = new ActivityLogClient($log);

        $result = $client->searchPage('', null, 0, 50);

        $this->assertSame(80, $result['total']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $log->calls);
    }
}
