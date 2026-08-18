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
}
