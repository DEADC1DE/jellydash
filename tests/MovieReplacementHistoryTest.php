<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\JellyfinUserAvatars;
use Mk\Framework\Jellyfin\MonthlyRecapService;
use Mk\Framework\Jellyfin\MovieReplacements;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Pages\HistoryController;
use PHPUnit\Framework\TestCase;

final class MovieReplacementHistoryTest extends TestCase
{
    private Database $database;
    private PlayHistoryRepository $history;
    private MovieReplacements $replacements;

    protected function setUp(): void
    {
        $this->database = Container::db();
        $this->replacements = new MovieReplacements($this->database, 'phpunit-movie-views');
        $this->history = new PlayHistoryRepository($this->database, movieReplacements: $this->replacements);
        $this->cleanup();
        foreach (['old-a', 'old-a', 'old-b', 'old-b', 'unrelated'] as $index => $id) {
            $this->database->getDibi()->insert('play_history', [
                'session_key' => 'phpunit-replacement-view-' . $index,
                'item_id' => 'phpunit-replacement-' . $id,
                'item_type' => 'Movie',
                'item_name' => 'Same movie title',
                'user_name' => ['Alex', 'Sam'][$index % 2],
                'watched_sec' => 60,
                'watch_duration_sec' => 60,
                'runtime_sec' => 600,
                'play_method' => 'DirectPlay',
                'library' => 'Movies',
                'library_resolved_at' => '2099-06-10 18:00:00',
                'started_at' => '2099-06-10 19:0' . $index . ':00',
                'updated_at' => '2099-06-10 19:1' . $index . ':00',
                'is_finished' => 0,
            ])->execute();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testReplacementDrilldownsPaginationCountsAndCsvKeepEveryOriginalPlay(): void
    {
        $before = $this->storedRows();
        $this->linkCopies();
        foreach (['old-a', 'old-b', 'current'] as $id) {
            $filters = $this->filters($id, limit: 2, offset: 1);
            self::assertSame(4, $this->history->historyTotal($filters));
            self::assertSame(['plays' => 4, 'unique_users' => 2, 'watch_sec' => 240, 'estimated_plays' => 0, 'transcodes' => 0], $this->history->historyAggregate($filters));
            $rows = $this->history->historyRows($filters);
            self::assertCount(2, $rows);
            self::assertSame('phpunit-replacement-current', $rows[0]['resolved_item_id']);

            $stream = fopen('php://temp', 'w+b');
            self::assertSame(4, (new HistoryCsvExporter($this->history))->write($filters, $stream));
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            self::assertStringContainsString('phpunit-replacement-old-a', $csv);
            self::assertStringContainsString('phpunit-replacement-old-b', $csv);
            self::assertStringNotContainsString('phpunit-replacement-current', $csv);
            self::assertStringNotContainsString('phpunit-replacement-unrelated', $csv);
        }
        self::assertSame(2, $this->history->historyTotal($this->filters('current', user: 'Alex')));
        self::assertSame($before, $this->storedRows());
    }

    public function testStatisticsRecapHistoryArtworkAndCacheContextFollowTheSameLinks(): void
    {
        $service = new PlaybackStatisticsService($this->history);
        $fingerprint = new ReflectionMethod($service, 'cacheContextFingerprint');
        $before = $fingerprint->invoke($service);
        $this->linkCopies();
        self::assertNotSame($before, $fingerprint->invoke($service));
        $now = new DateTimeImmutable('2099-06-11 12:00:00');
        $stats = $service->data('week', $now);
        self::assertCount(2, $stats['trending']);
        self::assertSame(4, $stats['trending'][0]['plays']);
        self::assertSame(2, $stats['trending'][0]['users']);
        self::assertSame('phpunit-replacement-current', $stats['trending'][0]['itemId']);
        parse_str((string) parse_url($stats['trending'][0]['href'], PHP_URL_QUERY), $query);
        self::assertSame(4, $this->history->historyTotal(HistoryFilters::fromQuery($query)));

        $recap = (new MonthlyRecapService($this->history))->data('2099-06', null, new DateTimeImmutable('2099-07-02'))['recap'];
        self::assertCount(2, $recap['movies']);
        self::assertSame(4, $recap['movies'][0]['plays']);
        self::assertStringContainsString('item=phpunit-replacement-current&', $recap['movies'][0]['poster']);

        $row = $this->history->historyRows($this->filters('old-a'))[0];
        $controller = (new ReflectionClass(HistoryController::class))->newInstanceWithoutConstructor();
        $view = (new ReflectionMethod($controller, 'rowView'))->invoke($controller, $row, $now, new JellyfinUserAvatars());
        self::assertStringContainsString('item=phpunit-replacement-current&', $view['poster']);
        self::assertSame('phpunit-replacement-old-b', $row['item_id']);

        $this->replacements->unlink('phpunit-replacement-old-a');
        self::assertSame(2, $this->history->historyTotal($this->filters('current')));
        self::assertCount(3, $service->data('week', $now)['trending']);
    }

    public function testLinksKeepMovieTypeCaseAndPeriodFiltersExact(): void
    {
        $this->linkCopies();
        $db = $this->database->getDibi();
        $db->update('play_history', ['item_type' => 'Episode'])
            ->where('session_key = %s', 'phpunit-replacement-view-0')->execute();
        $db->update('play_history', ['item_id' => 'PHPUNIT-REPLACEMENT-OLD-A'])
            ->where('session_key = %s', 'phpunit-replacement-view-1')->execute();
        self::assertSame(2, $this->history->historyTotal($this->filters('current')));
        self::assertSame(0, $this->history->historyTotal(new HistoryFilters(
            range: 'custom',
            start: new DateTimeImmutable('2099-06-11'),
            end: new DateTimeImmutable('2099-06-12'),
            mediaType: 'item',
            mediaId: 'phpunit-replacement-current',
            mediaItemType: 'Movie',
            mediaTitle: 'Same movie title',
        )));
        self::assertSame(1, $this->history->historyTotal(new HistoryFilters(
            range: 'all',
            mediaType: 'item',
            mediaId: 'phpunit-replacement-old-a',
            mediaItemType: 'Episode',
            mediaTitle: 'Same movie title',
        )));
    }

    public function testEquivalentFullGuidsShareMovieCardAndCompleteHistoryDrilldown(): void
    {
        $database = $this->database;
        $replacements = $this->replacements;
        $history = $this->history;
        $source = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $sourceDashed = 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA';
        $target = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $db = $database->getDibi();
        foreach ([$source, $sourceDashed, $target, 'a-b', 'ab', 'A-B', '12345'] as $index => $itemId) {
            $db->insert('play_history', [
                'session_key' => 'phpunit-replacement-view-guid-' . $index,
                'item_id' => $itemId,
                'item_type' => 'Movie',
                'item_name' => 'Same title',
                'user_name' => 'Viewer',
                'watched_sec' => 60,
                'watch_duration_sec' => 60,
                'runtime_sec' => 600,
                'play_method' => 'DirectPlay',
                'library' => 'Movies',
                'library_resolved_at' => '2099-06-10 18:00:00',
                'started_at' => '2099-06-10 19:0' . $index . ':00',
                'updated_at' => '2099-06-10 19:1' . $index . ':00',
                'is_finished' => 0,
            ])->execute();
        }
        $replacements->link($source, $target);

        $cards = (new PlaybackStatisticsService($history))->data('all', new DateTimeImmutable('2099-06-11'))['mostWatched']['movies'];
        $matching = array_values(array_filter($cards, static fn (array $card): bool => $card['itemId'] === $target));
        self::assertCount(1, $matching);
        self::assertSame(3, $matching[0]['plays']);
        parse_str((string) parse_url($matching[0]['href'], PHP_URL_QUERY), $query);
        $drilldown = HistoryFilters::fromQuery($query);
        self::assertSame(3, $history->historyTotal($drilldown));
        self::assertSame(180, $history->historyAggregate($drilldown)['watch_sec']);
        self::assertSame(3, $history->historyTotal(new HistoryFilters(
            range: 'all', mediaType: 'item', mediaId: $sourceDashed, mediaItemType: 'Movie', mediaTitle: 'Same title',
        )));
        self::assertSame(1, $history->historyTotal(new HistoryFilters(
            range: 'all', mediaType: 'item', mediaId: 'a-b', mediaItemType: 'Movie', mediaTitle: 'Same title',
        )));
        self::assertSame(1, $history->historyTotal(new HistoryFilters(
            range: 'all', mediaType: 'item', mediaId: 'ab', mediaItemType: 'Movie', mediaTitle: 'Same title',
        )));
        self::assertSame(1, $history->historyTotal(new HistoryFilters(
            range: 'all', mediaType: 'item', mediaId: 'A-B', mediaItemType: 'Movie', mediaTitle: 'Same title',
        )));
        self::assertSame(1, $history->historyTotal(new HistoryFilters(
            range: 'all', mediaType: 'item', mediaId: '12345', mediaItemType: 'Movie', mediaTitle: 'Same title',
        )));
    }

    public function testNonMovieGuidSpellingsKeepSeparateTrendingDrilldowns(): void
    {
        $ids = ['cccccccccccccccccccccccccccccccc', 'CCCCCCCC-CCCC-CCCC-CCCC-CCCCCCCCCCCC'];
        foreach ($ids as $index => $itemId) {
            $this->database->getDibi()->insert('play_history', [
                'session_key' => 'phpunit-replacement-view-audio-' . $index,
                'item_id' => $itemId,
                'item_type' => 'Audio',
                'item_name' => 'Same audio title',
                'user_name' => 'Viewer',
                'watched_sec' => 60,
                'watch_duration_sec' => 60,
                'runtime_sec' => 120,
                'play_method' => 'DirectPlay',
                'library' => 'Music',
                'library_resolved_at' => '2099-06-10 18:00:00',
                'started_at' => '2099-06-10 20:0' . $index . ':00',
                'updated_at' => '2099-06-10 20:1' . $index . ':00',
                'is_finished' => 0,
            ])->execute();
        }

        $cards = (new PlaybackStatisticsService($this->history))->data('all', new DateTimeImmutable('2099-06-11'))['trending'];
        $audio = array_values(array_filter($cards, static fn (array $card): bool => $card['title'] === 'Same audio title'));
        self::assertCount(2, $audio);
        foreach ($audio as $card) {
            self::assertSame(1, $card['plays']);
            parse_str((string) parse_url($card['href'], PHP_URL_QUERY), $query);
            self::assertSame(1, $this->history->historyTotal(HistoryFilters::fromQuery($query)));
        }
    }

    public function testConflictingGuidAliasesKeepEveryCardDrilldownWithinItsDisplayedGroup(): void
    {
        $ids = [
            'e943a6b352fb46f98d3c1a287ca84b90',
            'e943a6b3-52fb-46f9-8d3c-1a287ca84b90',
            'E943A6B3-52FB-46F9-8D3C-1A287CA84B90',
            'E943A6B352FB46F98D3C1A287CA84B90',
        ];
        foreach ($ids as $index => $itemId) {
            $this->database->getDibi()->insert('play_history', [
                'session_key' => 'phpunit-replacement-view-conflict-' . $index,
                'item_id' => $itemId,
                'item_type' => 'Movie',
                'item_name' => 'Conflicting GUID movie',
                'user_name' => 'Viewer',
                'watched_sec' => 60 * ($index + 1),
                'watch_duration_sec' => 60 * ($index + 1),
                'runtime_sec' => 600,
                'play_method' => 'DirectPlay',
                'library' => 'Movies',
                'library_resolved_at' => '2099-06-10 18:00:00',
                'started_at' => '2099-06-10 20:0' . $index . ':00',
                'updated_at' => '2099-06-10 20:1' . $index . ':00',
                'is_finished' => 0,
            ])->execute();
        }
        $before = $this->storedRows();
        $targetX = 'phpunit-replacement-conflict-x';
        $targetY = 'phpunit-replacement-conflict-y';
        $this->replacements->link($ids[0], $targetX);
        $this->replacements->link($ids[1], $targetX);
        $this->replacements->link($ids[2], $targetY);

        $cards = (new PlaybackStatisticsService($this->history))->data('all', new DateTimeImmutable('2099-06-11'))['mostWatched']['movies'];
        $cards = array_values(array_filter($cards, static fn (array $card): bool => $card['title'] === 'Conflicting GUID movie'));
        self::assertCount(3, $cards);
        $expected = [
            $targetX => ['ids' => [$ids[0], $ids[1]], 'watch_sec' => 180],
            $targetY => ['ids' => [$ids[2]], 'watch_sec' => 180],
            $ids[3] => ['ids' => [$ids[3]], 'watch_sec' => 240],
        ];
        foreach ($cards as $card) {
            self::assertArrayHasKey($card['itemId'], $expected);
            $group = $expected[$card['itemId']];
            self::assertSame(count($group['ids']), $card['plays']);
            parse_str((string) parse_url($card['href'], PHP_URL_QUERY), $query);
            $filters = HistoryFilters::fromQuery($query);
            self::assertSame(count($group['ids']), $this->history->historyTotal($filters), $card['itemId']);
            $aggregate = $this->history->historyAggregate($filters);
            self::assertSame(count($group['ids']), $aggregate['plays']);
            self::assertSame($group['watch_sec'], $aggregate['watch_sec']);
            $actualIds = array_map(static fn (Dibi\Row $row): string => (string) $row['item_id'], $this->history->historyRows($filters));
            self::assertEqualsCanonicalizing($group['ids'], $actualIds);
        }
        self::assertSame($before, $this->storedRows());
    }

    public function testEquivalentGuidTargetAndSourceRolesDoNotInferAnotherAliasHop(): void
    {
        $source = 'phpunit-replacement-chain-source';
        $compact = 'd56364d9fce2458795f2fc21c98ba543';
        $dashed = 'D56364D9-FCE2-4587-95F2-FC21C98BA543';
        $uppercase = strtoupper($compact);
        $target = 'phpunit-replacement-chain-target';
        $this->seedGuidChainRows([$source, $compact, $dashed, $uppercase]);
        $before = $this->storedRows();
        $this->replacements->link($source, $compact);
        $this->replacements->link($dashed, $target);
        $aliases = $this->replacements->all();

        $cards = (new PlaybackStatisticsService($this->history))->data('all', new DateTimeImmutable('2099-06-11'))['mostWatched']['movies'];
        $cards = array_values(array_filter($cards, static fn (array $card): bool => $card['title'] === 'GUID alias chain'));
        self::assertCount(2, $cards);
        $expected = [$uppercase => [$source, $compact, $uppercase], $target => [$dashed]];
        foreach ($cards as $card) {
            self::assertArrayHasKey($card['itemId'], $expected);
            $ids = $expected[$card['itemId']];
            self::assertSame(count($ids), $card['plays']);
            parse_str((string) parse_url($card['href'], PHP_URL_QUERY), $query);
            $filters = HistoryFilters::fromQuery($query);
            self::assertSame(count($ids), $this->history->historyTotal($filters));
            self::assertSame(count($ids) * 60, $this->history->historyAggregate($filters)['watch_sec']);
            self::assertEqualsCanonicalizing($ids, array_map(
                static fn (Dibi\Row $row): string => (string) $row['item_id'], $this->history->historyRows($filters),
            ));
        }
        self::assertSame($aliases, $this->replacements->all());
        self::assertSame($before, $this->storedRows());
    }

    public function testSelfEquivalentGuidAliasKeepsItsUnambiguousGroup(): void
    {
        $compact = 'cf57b5c26ecd4137b56d1c81e4357a40';
        $dashed = 'CF57B5C2-6ECD-4137-B56D-1C81E4357A40';
        $uppercase = strtoupper($compact);
        $this->seedGuidChainRows([$compact, $dashed, $uppercase]);
        $before = $this->storedRows();
        $this->replacements->link($compact, $dashed);
        $aliases = $this->replacements->all();

        $cards = (new PlaybackStatisticsService($this->history))->data('all', new DateTimeImmutable('2099-06-11'))['mostWatched']['movies'];
        $cards = array_values(array_filter($cards, static fn (array $card): bool => $card['title'] === 'GUID alias chain'));
        self::assertCount(1, $cards);
        self::assertSame(3, $cards[0]['plays']);
        parse_str((string) parse_url($cards[0]['href'], PHP_URL_QUERY), $query);
        $filters = HistoryFilters::fromQuery($query);
        self::assertSame(3, $this->history->historyTotal($filters));
        self::assertSame(180, $this->history->historyAggregate($filters)['watch_sec']);
        self::assertEqualsCanonicalizing([$compact, $dashed, $uppercase], array_map(
            static fn (Dibi\Row $row): string => (string) $row['item_id'], $this->history->historyRows($filters),
        ));
        self::assertSame($aliases, $this->replacements->all());
        self::assertSame($before, $this->storedRows());
    }

    /** @param list<string> $ids */
    private function seedGuidChainRows(array $ids): void
    {
        foreach ($ids as $index => $itemId) {
            $this->database->getDibi()->insert('play_history', [
                'session_key' => 'phpunit-replacement-view-guid-chain-' . $index,
                'item_id' => $itemId,
                'item_type' => 'Movie',
                'item_name' => 'GUID alias chain',
                'user_name' => 'Viewer',
                'watched_sec' => 60,
                'watch_duration_sec' => 60,
                'runtime_sec' => 600,
                'play_method' => 'DirectPlay',
                'library' => 'Movies',
                'library_resolved_at' => '2099-06-10 18:00:00',
                'started_at' => '2099-06-10 20:0' . $index . ':00',
                'updated_at' => '2099-06-10 20:1' . $index . ':00',
                'is_finished' => 0,
            ])->execute();
        }
    }

    private function filters(string $id, int $limit = 100, int $offset = 0, string $user = ''): HistoryFilters
    {
        return new HistoryFilters(
            user: $user,
            range: 'all',
            limit: $limit,
            offset: $offset,
            mediaType: 'item',
            mediaId: 'phpunit-replacement-' . $id,
            mediaItemType: 'Movie',
            mediaTitle: 'Same movie title',
        );
    }

    private function linkCopies(): void
    {
        $this->replacements->link('phpunit-replacement-old-a', 'phpunit-replacement-current');
        $this->replacements->link('phpunit-replacement-old-b', 'phpunit-replacement-current');
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return array_map(static fn (Dibi\Row $row): array => array_map(
            static fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.uP') : $value,
            $row->toArray(),
        ), $this->database->getDibi()
            ->select('*')->from('play_history')->where('session_key LIKE %s', 'phpunit-replacement-view-%')->orderBy('id')->fetchAll());
    }

    private function cleanup(): void
    {
        $this->database->getDibi()->delete('play_history')->where('session_key LIKE %s', 'phpunit-replacement-view-%')->execute();
        foreach ($this->replacements->all() as $id => $_) {
            $this->replacements->unlink((string) $id);
        }
    }
}
