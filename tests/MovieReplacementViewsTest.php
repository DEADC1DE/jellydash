<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\MonthlyRecapService;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use PHPUnit\Framework\TestCase;

final class MovieReplacementViewsTest extends TestCase
{
    public function testRetiredMovieIdsShareOneStatisticsCardAndCurrentArtwork(): void
    {
        $service = new PlaybackStatisticsService();
        $groups = (new ReflectionMethod($service, 'groupTitles'))->invoke($service, $this->rows());
        $cards = (new ReflectionMethod($service, 'titleCards'))->invoke($service, $groups, 'all');

        self::assertCount(1, $cards);
        self::assertSame(4, $cards[0]['plays']);
        self::assertSame(2, $cards[0]['users']);
        self::assertSame('current-copy', $cards[0]['itemId']);
        self::assertStringContainsString('item=current-copy&', $cards[0]['poster']);
        self::assertStringContainsString('media_id=current-copy&', $cards[0]['href']);
    }

    public function testMonthlyRecapCombinesOnlyConfirmedReplacements(): void
    {
        $rows = $this->rows();
        $other = clone $rows[0];
        $other['item_id'] = 'different-film-with-same-name';
        unset($other['resolved_item_id']);
        $rows[] = $other;
        $service = new MonthlyRecapService();
        $groups = (new ReflectionMethod($service, 'titleGroups'))->invoke($service, $rows);

        self::assertCount(2, $groups);
        self::assertSame(4, $groups[0]['plays']);
        self::assertSame('4m', $groups[0]['duration']);
        self::assertStringContainsString('item=current-copy&', $groups[0]['poster']);
        self::assertSame(1, $groups[1]['plays']);
    }

    public function testUnresolvedLibraryLookupUsesReplacementWhileKeepingHistoricalExclusions(): void
    {
        $rows = $this->rows();
        foreach ($rows as $row) {
            $row['library_resolved_at'] = null;
        }
        $rows[0]['library'] = 'Excluded';
        $rows[0]['library_resolved_at'] = '2026-10-10 18:00:00';
        $calls = [];
        $service = new PlaybackStatisticsService();
        $kept = (new ReflectionMethod($service, 'withoutExcludedLibraryRows'))->invoke(
            $service,
            $rows,
            ['excluded'],
            static function (array $ids) use (&$calls): array {
                $calls = $ids;

                return ['current-copy' => ['library' => 'Movies', 'runtime_sec' => 6000]];
            },
        );

        self::assertSame(['current-copy'], $calls);
        self::assertCount(3, $kept);
        self::assertSame('old-copy-a', $kept[0]['item_id']);
    }

    /** @return list<Dibi\Row> */
    private function rows(): array
    {
        $rows = [];
        foreach (['old-copy-a', 'old-copy-a', 'old-copy-b', 'old-copy-b'] as $index => $itemId) {
            $rows[] = new Dibi\Row([
                'item_id' => $itemId,
                'resolved_item_id' => 'current-copy',
                'item_type' => 'Movie',
                'item_name' => 'Replacement example',
                'series_name' => null,
                'user_name' => ['Alex', 'Sam'][$index % 2],
                'watched_sec' => 60,
                'watch_duration_sec' => 60,
                'started_at' => '2026-10-10 19:0' . $index . ':00',
                'library' => 'Movies',
                'library_resolved_at' => '2026-10-10 18:00:00',
            ]);
        }

        return $rows;
    }
}
