<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/session-control/src/StreamRuleEngine.php';
require_once __DIR__ . '/../modules/session-control/src/StreamRuleRepository.php';
require_once __DIR__ . '/../modules/session-control/src/StreamRuleEnforcer.php';
require_once __DIR__ . '/../modules/session-control/src/SessionActionsService.php';

use Mk\Framework\Database;
use Mk\Modules\SessionControl\StreamRuleEnforcer;
use Mk\Modules\SessionControl\StreamRuleRepository;
use PHPUnit\Framework\TestCase;

final class StreamRuleEnforcerTest extends TestCase
{
    private Database $db;
    private StreamRuleRepository $repository;
    private array $actions;
    private array $sent;

    private const STREAM = [
        'id' => 'session-1',
        'user' => 'user1',
        'device' => 'Fire TV',
        'title' => 'Family Guy',
        'playMethod' => 'Transcode',
        'watchedSec' => 720,
    ];

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->repository = new StreamRuleRepository($this->db);
        $this->actions = [];
        $this->sent = [];
    }

    private function transcodeRule(string $action = 'stop'): int
    {
        return $this->repository->save('No transcodes', [
            ['parameter' => 'playMethod', 'operator' => 'is', 'value' => 'Transcode', 'type' => 'str'],
        ], '', $action, true);
    }

    private function enforcer(array $streams, int $now): StreamRuleEnforcer
    {
        return new StreamRuleEnforcer(
            repository: $this->repository,
            kill: function (string $action, string $sessionId): bool {
                $this->actions[] = [$action, $sessionId];

                return true;
            },
            announce: function (array $rule, array $stream, string $action): void {
                $this->sent[] = ['rule' => $rule['name'], 'action' => $action, 'user' => $stream['user']];
            },
            sessions: static fn (): array => $streams,
        );
    }

    public function testKillsMatchingSessionAndNotifies(): void
    {
        $ruleId = $this->transcodeRule();
        $killed = $this->enforcer([self::STREAM], 1000)->run(1000);

        $this->assertSame(1, $killed);
        $this->assertSame([['stop', 'session-1']], $this->actions);
        $this->assertCount(1, $this->sent);
        $this->assertSame('No transcodes', $this->sent[0]['rule']);
        $this->assertSame('user1', $this->sent[0]['user']);
        $this->assertTrue($this->repository->wasKilledRecently('session-1', $ruleId, 1000));
    }

    public function testGuardPreventsRepeatedKillsForSameSession(): void
    {
        $this->transcodeRule();
        $enforcer = $this->enforcer([self::STREAM], 1000);

        $this->assertSame(1, $enforcer->run(1000));
        // Same poll window: the lingering session must not be killed again.
        $this->assertSame(0, $enforcer->run(1300));
        $this->assertCount(1, $this->actions);
    }

    public function testNonMatchingSessionsAreLeftAlone(): void
    {
        $this->transcodeRule();

        $stream = self::STREAM;
        $stream['playMethod'] = 'DirectPlay';

        $this->assertSame(0, $this->enforcer([$stream], 1000)->run(1000));
        $this->assertSame([], $this->actions);
        $this->assertSame([], $this->sent);
    }

    public function testKickActionAndDisabledRules(): void
    {
        $ruleId = $this->transcodeRule('kick');
        $this->repository->toggle($ruleId, false);

        $this->assertSame(0, $this->enforcer([self::STREAM], 1000)->run(1000));
        $this->assertSame([], $this->actions);

        $this->repository->toggle($ruleId, true);
        $this->assertSame(1, $this->enforcer([self::STREAM], 1000)->run(1000));
        $this->assertSame([['kick', 'session-1']], $this->actions);
    }
}
