<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsTemplateTest extends TestCase
{
    public function testDrilldownNamesRetainMeasurementsAndTheirHistoryScope(): void
    {
        $scope = ['range' => 'custom', 'start' => '2026-10-05', 'end' => '2026-10-12'];
        $url = static fn (array $filter = []): string => '/history?' . http_build_query($scope + $filter);
        $user = [
            'user' => 'Mártin & Jane', 'watch' => '2h 30m', 'plays' => '3', 'avg' => '50m',
            'share' => '60%', 'initials' => 'MJ', 'avatarBg' => '#6547d6', 'avatarUrl' => '',
            'w' => '100%', 'color' => '#6547d6', 'href' => $url(['user' => 'Mártin & Jane']),
        ];
        $client = [
            'name' => 'Living room & TV', 'pct' => '75%', 'rank' => 1, 'sessions' => '3',
            'watch' => '2h 30m', 'w' => '100%', 'color' => '#6547d6', 'transcodeVal' => '1',
            'directPct' => '67%', 'transcodePctStr' => '33%',
            'href' => $url(['client' => 'Living room & TV']),
            'directHref' => $url(['client' => 'Living room & TV', 'method' => 'direct']),
            'transcodeHref' => $url(['client' => 'Living room & TV', 'method' => 'transcode']),
        ];
        $method = ['label' => 'Transcode', 'pct' => '25%', 'color' => '#f7b955', 'href' => $url(['method' => 'transcode'])];
        $codec = ['name' => 'HEVC', 'pct' => '75%', 'w' => '75%', 'color' => '#6547d6', 'href' => $url(['codec' => ['HEVC ', 'HEVC']])];
        $reason = ['name' => 'Audio codec not supported', 'pct' => '100%', 'w' => '100%', 'color' => '#6547d6', 'href' => $url(['reason' => ['Audio codec not supported']])];
        $bar = [
            'periodLabel' => '5 October 2026', 'value' => '45m', 'h' => '30%', 'label' => 'Mon',
            'href' => '/history?' . http_build_query(['range' => 'custom', 'start' => '2026-10-05', 'end' => '2026-10-06']),
        ];
        $stats = array_replace($this->emptyStats(), [
            'topUsers' => [$user], 'usersTable' => [$user], 'directnessLegend' => [$method],
            'clientBreakdown' => [$client], 'clientsRanked' => [$client],
            'clientsTranscode' => [$client], 'clientsUsage' => [$client],
            'codecs' => [$codec], 'reasons' => [$reason], 'trend' => [$bar],
        ]);
        $stats['hasCodecData'] = true;
        $stats['hasReasonData'] = true;
        $stats['totalWatchHref'] = $url();
        $stats['totalWatch'] = '4h 10m';
        $stats['watchTimeEstimated'] = true;
        $xpath = $this->render($stats);

        $this->assertLink($xpath, 'stats-user-link', $user['href'], ['Mártin & Jane', '2h 30m', 'watch time']);
        $this->assertLink($xpath, 'stats-user-table-link', $user['href'], ['2h 30m', '3 plays', '50m average per play', '60%']);
        $this->assertLink($xpath, 'stats-legend-row', $method['href'], ['Transcode', '25%', 'sessions']);
        $this->assertLink($xpath, 'stats-legend-row', $client['href'], ['Living room & TV', '75%']);
        $this->assertLink($xpath, 'stats-ranked-row', $client['href'], ['Living room & TV', '3 sessions']);
        $this->assertLink($xpath, 'stats-bar-row', $client['href'], ['Living room & TV', '2h 30m']);
        $this->assertLink($xpath, '', $client['directHref'], ['Living room & TV', 'Direct', '67%']);
        $this->assertLink($xpath, '', $client['transcodeHref'], ['Living room & TV', 'Transcode', '33%']);
        $this->assertLink($xpath, 'stats-bar-row', $codec['href'], ['HEVC', '75%']);
        $this->assertLink($xpath, 'stats-bar-row', $reason['href'], ['Audio codec not supported', '100% of logged reasons']);
        $this->assertLink($xpath, '', $bar['href'], ['5 October 2026', '45m']);
        $this->assertLink($xpath, 'stats-watch-total-link', $url(), ['Estimated Watch Time', '4h 10m']);
    }

    public function testEmptyStatisticsKeepsOptionalDevicesHidden(): void
    {
        $xpath = $this->render($this->emptyStats());
        self::assertSame(1, $xpath->query('//*[@data-statistics-devices and @hidden]')->length);
        self::assertSame(1, $xpath->query('//*[@data-device-list]')->length);
        self::assertSame(0, $xpath->query('//a[contains(@class, "stats-user-link")]')->length);
        self::assertSame(1, $xpath->query('//h3[text()="Watch time over time"]')->length);
    }

    public function testMediaPrecedesSummaryWithoutLosingTitleDrilldowns(): void
    {
        $title = [
            'title' => 'Example film', 'poster' => 'none', 'meta' => '3 plays', 'multi' => false,
            'users' => '1', 'href' => '/history?range=all&media_type=item&media_id=fixture-film',
        ];
        $stats = array_replace($this->emptyStats(), [
            'hasTrending' => true, 'trending' => [$title], 'hasMostWatched' => true,
            'mostWatched' => ['series' => [], 'movies' => [$title]],
            'kpis' => [['label' => 'Total Plays', 'value' => '3', 'color' => '#6547d6',
                'delta' => '3 plays', 'deltaColor' => '#fff', 'href' => '/history?range=all']],
        ]);
        $xpath = $this->render($stats);
        self::assertSame(1, $xpath->query('//section[contains(@class, "stats-kpi-grid")]/following-sibling::section[contains(@class, "stats-general-grid")]')->length);
        self::assertSame(1, $xpath->query('//details[@data-most-watched]/following-sibling::section[contains(@class, "stats-kpi-grid")]')->length);
        self::assertSame(1, $xpath->query('//section[contains(@class, "trending-section")]/following-sibling::details[@data-most-watched]')->length);
        $cards = $xpath->query('//a[contains(@class, "trending-card")]');
        self::assertCount(2, $cards);
        foreach ($cards as $card) {
            self::assertInstanceOf(DOMElement::class, $card);
            self::assertSame($title['href'], $card->getAttribute('href'));
            self::assertStringContainsString('3 plays', $card->textContent);
        }
        self::assertSame(1, $xpath->query('//*[@data-most-watched]//*[@data-deferred-poster]')->length);
    }

    /** @param array<string, mixed> $stats */
    private function render(array $stats): DOMXPath
    {
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(TEMPLATES_DIR), ['strict_variables' => true]);
        $html = $twig->load('statistics/index.twig')->renderBlock('dashboard_content', ['stats' => $stats]);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /** @param list<string> $measurements */
    private function assertLink(DOMXPath $xpath, string $class, string $href, array $measurements): void
    {
        $matches = [];
        foreach ($xpath->query('//a') as $link) {
            if ($link instanceof DOMElement && $link->getAttribute('href') === $href
                && ($class === '' || in_array($class, explode(' ', $link->getAttribute('class')), true))) {
                $matches[] = $link;
            }
        }
        self::assertCount(1, $matches, $class . ' must keep its exact History URL');
        $name = $matches[0]->getAttribute('aria-label');
        foreach ($measurements as $measurement) {
            self::assertStringContainsString($measurement, $name);
        }
        self::assertStringContainsString('View', $name);
        self::assertStringContainsString('History', $name);
    }

    /** @return array<string, mixed> */
    private function emptyStats(): array
    {
        return [
            'range' => 'week', 'hasTrending' => false, 'hasMostWatched' => false, 'kpis' => [],
            'totalWatchHref' => '/history?range=all', 'totalWatch' => '0m', 'watchTimeEstimated' => false,
            'trendUnit' => 'hours / day', 'directnessConic' => '#6547d6', 'directVal' => '0%',
            'clientsConic' => '#6547d6', 'totalSessionsVal' => '0', 'hasCodecData' => false,
            'hasReasonData' => false, 'codecCoverage' => '0 of 0 plays', 'reasonCoverage' => '0 of 0 transcodes',
            'topUsers' => [], 'usersTable' => [], 'directnessLegend' => [], 'clientBreakdown' => [],
            'clientsRanked' => [], 'clientsTranscode' => [], 'clientsUsage' => [], 'trend' => [],
        ];
    }
}
