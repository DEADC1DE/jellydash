<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NowPlayingFrontendTest extends TestCase
{
    public function testInitialRequestFailureReplacesLoadingStates(): void
    {
        $nowPlaying = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $navCount = file_get_contents(ROOT_DIR . '/public/assets/js/nav-count.js');

        $this->assertIsString($nowPlaying);
        $this->assertIsString($navCount);
        $this->assertStringContainsString('Could not load sessions', $nowPlaying);
        $this->assertStringContainsString('renderError', $nowPlaying);
        $this->assertStringContainsString("navCount.classList.remove('is-loading')", $navCount);
        $this->assertStringContainsString("navCount.textContent = '-'", $navCount);
    }

    public function testActiveStreamsAreLabelledAsStreams(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString("stats.active_streams == 1 ? 'stream' : 'streams'", $template);
        $this->assertStringContainsString("activeStreams === 1 ? 'stream' : 'streams'", $script);
    }

    public function testMobileIdleLayoutUsesTheAvailableFlexSpace(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="now-playing-page"', $template);
        $this->assertStringContainsString('.dashboard-content-now-playing', $stylesheet);
        $this->assertStringNotContainsString('calc(100dvh - 350px)', $stylesheet);
    }

    public function testRecentlyAddedStaysHiddenForEmptyOrFailedRequests(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/recently-added.js');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertStringContainsString('data-recently-added aria-labelledby=', $template);
        $this->assertStringContainsString('if (!response.ok)', $script);
        $this->assertStringContainsString('if (items.length === 0)', $script);
        $this->assertStringContainsString('panel.hidden = false', $script);
    }

    public function testRecentlyAddedCanBeDisabledWithoutLoadingItsClient(): void
    {
        $nowPlaying = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $settings = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $view = file_get_contents(ROOT_DIR . '/src/View.php');
        $request = file_get_contents(ROOT_DIR . '/operations/@request.php');

        $this->assertIsString($nowPlaying);
        $this->assertIsString($settings);
        $this->assertIsString($view);
        $this->assertIsString($request);
        $this->assertStringContainsString('{% if show_recently_added %}', $nowPlaying);
        $this->assertStringContainsString('name="show_recently_added"', $settings);
        $this->assertStringContainsString("AppSettings::bool('show_recently_added', true)", $view);
        $this->assertStringContainsString("AppSettings::set('show_recently_added'", $request);
    }

    public function testActiveStreamGridCannotShrinkUnderRecentlyAddedShelf(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/now_playing/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/now-playing.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("streams is not empty ? ' has-streams' : ''", $template);
        $this->assertStringContainsString("root.classList.toggle('has-streams', streams.length > 0)", $script);
        $this->assertStringContainsString('.now-playing-live.has-streams', $stylesheet);
        $this->assertStringContainsString('min-height: min-content;', $stylesheet);
        $this->assertStringContainsString(".now-playing-page {\n    min-height: min-content;", $stylesheet);
    }

    public function testMobilePlaybackDetailsCannotPushTheMethodBadgeToAnotherRow(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.stream-card-top .playback-stack', $stylesheet);
        $this->assertStringContainsString('display: contents;', $stylesheet);
        $this->assertStringContainsString('.stream-card-top .quality-chip', $stylesheet);
        $this->assertStringContainsString('grid-column: 1 / -1;', $stylesheet);
        $this->assertStringContainsString('width: fit-content;', $stylesheet);
        $this->assertStringContainsString('justify-self: end;', $stylesheet);
        $this->assertStringContainsString('text-overflow: ellipsis;', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: 54px minmax(0, 1fr);', $stylesheet);
        $this->assertStringContainsString('-webkit-line-clamp: 3;', $stylesheet);
    }
}
