<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyfin\ThemePlaybackExclusions;
use PHPUnit\Framework\TestCase;

final class MovieReplacementsTest extends TestCase
{
    private Database $database;
    private MovieReplacements $replacements;
    private string $scope;
    private string $otherScope;
    private string $sessionKey;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->scope = hash('sha256', 'replacement-test-' . bin2hex(random_bytes(8)));
        $this->otherScope = hash('sha256', $this->scope);
        $this->sessionKey = 'replacement-test-' . bin2hex(random_bytes(8));
        $this->replacements = new MovieReplacements($this->database, $this->scope);
        new PlayHistoryRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (!isset($this->replacements)) {
            return;
        }
        $this->database->getDibi()->delete('movie_replacements')
            ->where('server_key IN %in', [$this->scope, $this->otherScope])->execute();
        $this->database->getDibi()->delete('play_history')
            ->where('session_key = %s', $this->sessionKey)->execute();
    }

    public function testLinksGroupAliasesAndRemainFreshAcrossInstances(): void
    {
        $second = new MovieReplacements($this->database, $this->scope);
        self::assertSame('old', $second->resolve('old'));
        self::assertSame(['old'], $second->members('old'));
        $fingerprint = $second->fingerprint();
        $this->replacements->link('old', 'current');
        $this->replacements->link('older', 'current');

        self::assertSame('current', $second->resolve('old'));
        self::assertSame(['current', 'old', 'older'], $second->members('old'));
        self::assertSame(['current', 'old', 'older'], $second->members('current'));
        self::assertSame(['old' => 'current', 'older' => 'current'], $second->all());
        self::assertNotSame($fingerprint, $second->fingerprint());
        $fingerprint = $second->fingerprint();
        $second->link('old', 'current');
        self::assertSame($fingerprint, $second->fingerprint());
    }

    public function testChainedReplacementsFlattenEveryUpstreamAlias(): void
    {
        $this->replacements->link('A', 'B');
        $this->replacements->link('older', 'A');
        $this->replacements->link('B', 'C');

        self::assertSame(['A' => 'C', 'B' => 'C', 'older' => 'C'], $this->replacements->all());
        self::assertSame(['C', 'A', 'B', 'older'], $this->replacements->members('A'));
        self::assertSame('C', $this->replacements->resolve('older'));
    }

    public function testCyclesSelfLinksAndConflictingTargetsLeaveMappingsUnchanged(): void
    {
        $this->replacements->link('A', 'B');
        foreach ([['A', 'A'], ['B', 'A'], ['A', 'different']] as [$old, $new]) {
            try {
                $this->replacements->link($old, $new);
                self::fail('Invalid replacement must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(['A' => 'B'], $this->replacements->all());
            }
        }
        $this->replacements->link('B', 'C');
        self::assertSame('C', $this->replacements->resolve('A'));
    }

    public function testIdsRemainExactAndServerScopesStaySeparate(): void
    {
        foreach (['12345', 'a-B', 'A-B', 'a-B ', 'ABCDEF01-2345-6789-ABCD-EF0123456789', str_repeat('x', 64)] as $index => $id) {
            $this->replacements->link($id, 'current-' . $index);
            self::assertSame('current-' . $index, $this->replacements->resolve($id));
        }
        self::assertSame('a-b', $this->replacements->resolve('a-b'));
        $other = new MovieReplacements($this->database, $this->otherScope);
        self::assertSame('a-B', $other->resolve('a-B'));
        $other->link('a-B', 'another-current');
        self::assertSame('another-current', $other->resolve('a-B'));
        self::assertSame('current-1', $this->replacements->resolve('a-B'));
        self::assertSame('current-2', $this->replacements->resolve('A-B'));
        self::assertSame('current-3', $this->replacements->resolve('a-B '));
        self::assertNotSame($other->fingerprint(), $this->replacements->fingerprint());
    }

    public function testInvalidIdsCannotCreateMappings(): void
    {
        foreach (['', ' ', str_repeat('x', 65)] as $id) {
            foreach ([[$id, 'valid'], ['valid', $id]] as [$old, $new]) {
                try {
                    $this->replacements->link($old, $new);
                    self::fail('Invalid replacement ID must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame([], $this->replacements->all());
                }
            }
        }
    }

    public function testUnlinkIsReversibleAndRemovesOnlyTheSpecifiedAlias(): void
    {
        $this->replacements->link('A', 'B');
        $this->replacements->link('B', 'C');
        self::assertTrue($this->replacements->unlink('B'));
        self::assertFalse($this->replacements->unlink('B'));
        self::assertSame('B', $this->replacements->resolve('B'));
        self::assertSame('C', $this->replacements->resolve('A'));
        self::assertTrue($this->replacements->unlink('A'));
        $this->replacements->link('A', 'corrected');
        self::assertSame('corrected', $this->replacements->resolve('A'));
    }

    public function testHistoryRowsRemainByteForByteUnchanged(): void
    {
        new PlayHistoryRepository($this->database);
        $db = $this->database->getDibi();
        $db->insert('play_history', [
            'session_key' => $this->sessionKey,
            'item_id' => 'old',
            'item_type' => 'Movie',
            'item_name' => 'Original title',
            'play_method' => 'DirectPlay',
            'watched_sec' => 300,
            'runtime_sec' => 3600,
            'started_at' => '2026-10-11 12:00:00',
            'updated_at' => '2026-10-11 12:05:00',
        ])->execute();
        $snapshot = fn (): string => serialize($db->select('*')->from('play_history')
            ->where('session_key = %s', $this->sessionKey)->fetch()?->toArray());
        $before = $snapshot();
        $this->replacements->link('old', 'current');
        $this->replacements->link('current', 'newest');
        $this->replacements->unlink('old');
        self::assertSame($before, $snapshot());
    }

    public function testFailureAfterUpdatingAliasesRollsBackTheWholeChain(): void
    {
        $this->replacements->link('A', 'B');
        $db = $this->database->getDibi();
        $trigger = 'replacement_failure_' . substr($this->scope, 0, 16);
        $condition = $db->translate('NEW.server_key = %s AND NEW.item_id = %s', $this->scope, 'B');
        $sql = $this->database->getPlatform()->isSqlite()
            ? 'CREATE TRIGGER %n BEFORE INSERT ON movie_replacements WHEN ' . $condition . ' BEGIN SELECT RAISE(ABORT, \'Replacement test failure\'); END'
            : 'CREATE TRIGGER %n BEFORE INSERT ON movie_replacements FOR EACH ROW BEGIN IF ' . $condition . ' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Replacement test failure\'; END IF; END';
        $db->query($sql, $trigger);
        try {
            try {
                $this->replacements->link('B', 'C');
                self::fail('The fixture must reject the final insert.');
            } catch (\Dibi\Exception) {
                self::assertSame(['A' => 'B'], $this->replacements->all());
            }
        } finally {
            $db->query('DROP TRIGGER %n', $trigger);
        }
        $this->replacements->link('B', 'C');
        self::assertSame(['A' => 'C', 'B' => 'C'], $this->replacements->all());
    }

    public function testDefaultScopeUsesConfiguredJellyfinUrl(): void
    {
        $default = new MovieReplacements($this->database);
        $explicit = new MovieReplacements($this->database, ThemePlaybackExclusions::serverKey(Config::get('JELLYFIN_URL', '')));
        self::assertSame($explicit->fingerprint(), $default->fingerprint());
    }
}
