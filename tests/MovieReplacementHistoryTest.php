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
