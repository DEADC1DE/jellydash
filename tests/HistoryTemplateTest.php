<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoryTemplateTest extends TestCase
{
    public function testFooterUsesFilteredResultTotal(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            'Showing {{ summary.from }}-{{ summary.to }} of {{ summary.filtered_total }} plays',
            $template
        );
        $this->assertStringContainsString(
            '{{ summary.from }}-{{ summary.to }} <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString(
            '0 <small>of {{ summary.filtered_total }}</small>',
            $template
        );
        $this->assertStringContainsString('history/_pager.twig', $template);
        $this->assertStringContainsString('href="/settings#import-history"', $template);
        $this->assertStringContainsString('Import history', $template);
        $this->assertStringNotContainsString('data-import-history-banner', $template);
        $this->assertStringNotContainsString('history-import.js', $template);
        $this->assertStringContainsString('{% for library in libraries %}', $template);
        $this->assertStringNotContainsString('option value="Movies"', $template);
        $this->assertStringContainsString('Watch time', $template);
        $this->assertStringNotContainsString('Watch time shown', $template);

        $empty = file_get_contents(TEMPLATES_DIR . '/history/_empty.twig');
        $this->assertIsString($empty);
        $this->assertStringContainsString('{% if summary.total == 0 %}', $empty);
        $this->assertStringContainsString('Import existing history', $empty);
        $this->assertStringContainsString('href="/settings#import-history"', $empty);
    }

    public function testHistoryRowsUseSharedAvatarPartial(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('_avatar.twig', $template);
        $this->assertStringContainsString('play.avatarUrl', $template);
    }

    public function testHistoryRowsKeepItemDetailsAndLibrarySeparate(): void
    {
        $controller = new \Mk\Framework\Pages\HistoryController(new \Mk\Framework\View());
        $method = new ReflectionMethod($controller, 'rowView');
        $avatars = new \Mk\Framework\Jellyfin\JellyfinUserAvatars();

        $episode = $method->invoke(
            $controller,
            $this->historyRow([
                'item_type' => 'Episode',
                'series_name' => 'Impractical Jokers',
                'item_name' => 'Celebrity Crushed',
                'season_ep' => 'S13 E3',
                'library' => 'TV Shows',
            ]),
            new DateTimeImmutable('2026-08-22 12:00:00'),
            $avatars,
        );
        $movie = $method->invoke(
            $controller,
            $this->historyRow([
                'item_type' => 'Movie',
                'series_name' => null,
                'item_name' => 'Elvira: Mistress of the Dark',
                'season_ep' => null,
                'library' => 'Movies',
            ]),
            new DateTimeImmutable('2026-08-22 12:00:00'),
            $avatars,
        );

        $this->assertSame('S13 E3 - Celebrity Crushed', $episode['sub']);
        $this->assertSame('TV Shows', $episode['library']);
        $this->assertSame('Movie', $movie['sub']);
        $this->assertSame('Movies', $movie['library']);

        $template = (string) file_get_contents(TEMPLATES_DIR . '/history/_history_row.twig');
        $stylesheet = (string) file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        $this->assertStringContainsString('history-item-meta', $template);
        $this->assertStringContainsString('history-library-pill', $template);
        $this->assertStringContainsString('play.library', $template);
        $this->assertMatchesRegularExpression(
            '/\.history-item-meta small\s*\{[^}]*flex:\s*0 1 auto;/s',
            $stylesheet,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function historyRow(array $overrides): \Dibi\Row
    {
        return new \Dibi\Row(array_merge([
            'item_type' => 'Video',
            'play_method' => 'DirectPlay',
            'watched_sec' => 120,
            'runtime_sec' => 600,
            'series_name' => null,
            'item_name' => 'Fixture',
            'user_name' => 'Martin',
            'user_id' => '',
            'season_ep' => null,
            'library' => 'Videos',
            'client' => 'Jellyfin Web',
            'device' => 'Chrome',
            'is_finished' => 0,
            'item_id' => 'fixture-item',
        ], $overrides));
    }
}
