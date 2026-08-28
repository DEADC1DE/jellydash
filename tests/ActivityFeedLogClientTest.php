<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/activity-feed/src/ActivityLogClient.php';

use Mk\Modules\ActivityFeed\ActivityLogClient;
use PHPUnit\Framework\TestCase;

final class ActivityFeedLogClientTest extends TestCase
{
    public function testPagePassesStartIndexAndLimitAndMapsItems(): void
    {
        $client = new class {
            public ?string $lastPath = null;
            public function getJson(string $path): mixed
            {
                $this->lastPath = $path;
                return [
                    'Items' => [[
                        'Date' => '2026-08-27T18:59:46.4877177Z',
                        'Name' => "jf_test_user_1 hat die Wiedergabe gestartet",
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
}
