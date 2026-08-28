<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/activity-feed/src/ActivityLogClient.php';
require_once __DIR__ . '/../modules/activity-feed/src/GhostUserResolver.php';

use Mk\Framework\Database;
use Mk\Modules\ActivityFeed\GhostUserResolver;
use PHPUnit\Framework\TestCase;

final class ActivityFeedGhostUserResolverTest extends TestCase
{
    public function testResolveFillsInEmptyUserNamesFromActivityLog(): void
    {
        $db = Database::sqlite(':memory:');
        $dibi = $db->getDibi();
        $dibi->query('CREATE TABLE play_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, user_name TEXT)');
        $dibi->insert('play_history', ['user_id' => 'e965542d-dbc9-43e3-b994-8512afeee72c', 'user_name' => ''])->execute();
        $dibi->insert('play_history', ['user_id' => 'e965542d-dbc9-43e3-b994-8512afeee72c', 'user_name' => ''])->execute();
        $dibi->insert('play_history', ['user_id' => 'known-user', 'user_name' => 'AlreadyKnown'])->execute();

        // Same shape ActivityLogClient::page() returns — the resolver only
        // needs one page here, matching "manual, on-demand button" from the
        // spec (no full 78k-row scan on every run in real use, but the
        // resolver's own paging loop is exercised in the next test).
        $logClient = new class {
            public function page(int $startIndex, int $limit): array
            {
                if ($startIndex > 0) {
                    return ['items' => [], 'total' => 1];
                }
                return [
                    'items' => [[
                        'date' => '2026-07-30T18:51:04Z',
                        'name' => 'jf_deleted_user_1 wurde getrennt von TestTV/00',
                        'userId' => 'e965542ddbc943e3b9948512afeee72c',
                    ]],
                    'total' => 1,
                ];
            }
        };

        $resolved = (new GhostUserResolver($logClient, $db))->resolve();

        $this->assertSame(['e965542d-dbc9-43e3-b994-8512afeee72c' => 'jf_deleted_user_1'], $resolved);

        $rows = $dibi->select('user_name')->from('play_history')->where('user_id = %s', 'e965542d-dbc9-43e3-b994-8512afeee72c')->fetchAll();
        $this->assertSame('jf_deleted_user_1', (string) $rows[0]['user_name']);
        $this->assertSame('jf_deleted_user_1', (string) $rows[1]['user_name']);
    }

    public function testResolveReturnsEmptyWhenNoGhostRows(): void
    {
        $db = Database::sqlite(':memory:');
        $db->getDibi()->query('CREATE TABLE play_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, user_name TEXT)');
        $db->getDibi()->insert('play_history', ['user_id' => 'known-user', 'user_name' => 'AlreadyKnown'])->execute();

        $logClient = new class {
            public function page(int $startIndex, int $limit): array
            {
                return ['items' => [], 'total' => 0];
            }
        };

        $resolved = (new GhostUserResolver($logClient, $db))->resolve();

        $this->assertSame([], $resolved);
    }
}
