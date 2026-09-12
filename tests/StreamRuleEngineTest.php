<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/session-control/src/StreamRuleEngine.php';

use Mk\Modules\SessionControl\StreamRuleEngine;
use PHPUnit\Framework\TestCase;

final class StreamRuleEngineTest extends TestCase
{
    private StreamRuleEngine $engine;

    /** A session shape mirroring JellyfinSessionMapper's mapped streams. */
    private array $session = [
        'user' => 'Dreamboxx',
        'client' => 'Jellyfin Android TV',
        'device' => "Joachim's 4th Fire TV",
        'title' => 'Family Guy',
        'seriesName' => 'Family Guy',
        'library' => 'Serien',
        'playMethod' => 'Transcode',
        'quality' => '1080p',
        'bitrate' => 6000,
        'progressPct' => 42.5,
        'watchedMin' => 12,
    ];

    protected function setUp(): void
    {
        $this->engine = new StreamRuleEngine();
    }

    public function testStringOperators(): void
    {
        $condition = fn (string $operator, string $value): array => [
            ['parameter' => 'playMethod', 'operator' => $operator, 'value' => $value, 'type' => 'str'],
        ];

        $this->assertTrue($this->engine->evaluate($condition('is', 'Transcode'), '', $this->session));
        $this->assertFalse($this->engine->evaluate($condition('is', 'Direct Play'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('contains', 'trans'), '', $this->session));
        $this->assertFalse($this->engine->evaluate($condition('does not contain', 'trans'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('begins with', 'trans'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('ends with', 'code'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('is not', 'DirectPlay,DirectStream'), '', $this->session));
    }

    public function testNumericOperatorsAndCasting(): void
    {
        $condition = fn (string $operator, string $value, string $type): array => [
            ['parameter' => 'bitrate', 'operator' => $operator, 'value' => $value, 'type' => $type],
        ];

        $this->assertTrue($this->engine->evaluate($condition('is greater than', '5000', 'int'), '', $this->session));
        $this->assertFalse($this->engine->evaluate($condition('is greater than', '6000', 'int'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('is less than', '6001', 'int'), '', $this->session));
        $this->assertTrue($this->engine->evaluate($condition('is', '6000', 'int'), '', $this->session));
    }

    public function testCommaSeparatedValuesActAsOr(): void
    {
        $condition = [
            ['parameter' => 'user', 'operator' => 'is', 'value' => 'Alpha, Dreamboxx, Beta', 'type' => 'str'],
        ];

        $this->assertTrue($this->engine->evaluate($condition, '', $this->session));
    }

    public function testBlankConditionsAreSkipped(): void
    {
        $this->assertTrue($this->engine->evaluate([
            ['parameter' => '', 'operator' => 'is', 'value' => 'x', 'type' => 'str'],
            ['parameter' => 'user', 'operator' => '', 'value' => 'x', 'type' => 'str'],
            ['parameter' => 'user', 'operator' => 'is', 'value' => '', 'type' => 'str'],
        ], '', $this->session));

        // One real non-matching condition still fails the rule.
        $this->assertFalse($this->engine->evaluate([
            ['parameter' => '', 'operator' => 'is', 'value' => 'x', 'type' => 'str'],
            ['parameter' => 'user', 'operator' => 'is', 'value' => 'other', 'type' => 'str'],
        ], '', $this->session));
    }

    public function testMissingSessionParameterFailsThatCondition(): void
    {
        $this->assertFalse($this->engine->evaluate([
            ['parameter' => 'unknownField', 'operator' => 'is', 'value' => 'x', 'type' => 'str'],
        ], '', $this->session));
    }

    public function testLogicStringAndOrGroups(): void
    {
        $match = fn (string $user, string $method): array => [
            ['parameter' => 'user', 'operator' => 'is', 'value' => $user, 'type' => 'str'],
            ['parameter' => 'playMethod', 'operator' => 'is', 'value' => $method, 'type' => 'str'],
            ['parameter' => 'device', 'operator' => 'contains', 'value' => 'Fire TV', 'type' => 'str'],
        ];

        // False and False or True → left to right: (False or True) = True.
        $this->assertTrue($this->engine->evaluate($match('Other', 'DirectPlay'), '{1} or {2} or {3}', $this->session));

        // True and False and True → False.
        $this->assertFalse($this->engine->evaluate($match('Dreamboxx', 'DirectPlay'), '{1} and {2} and {3}', $this->session));

        // Brackets: {1} and ({2} or {3}) → True and (False or True) = True.
        $this->assertTrue($this->engine->evaluate($match('Dreamboxx', 'DirectPlay'), '({1} and ({2} or {3}))', $this->session));
    }

    public function testMalformedLogicIsNotAMatch(): void
    {
        $conditions = [
            ['parameter' => 'user', 'operator' => 'is', 'value' => 'Dreamboxx', 'type' => 'str'],
        ];

        $this->assertFalse($this->engine->evaluate($conditions, '{1} and', $this->session));
        $this->assertFalse($this->engine->evaluate($conditions, '({1}', $this->session));
        $this->assertFalse($this->engine->evaluate($conditions, '{5} or {1}', $this->session));
        $this->assertFalse($this->engine->evaluate($conditions, 'drop table {1}', $this->session));
    }
}
