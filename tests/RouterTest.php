<?php

use Mk\Framework\Router;
use Mk\Framework\View;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the router (Phase 6 / #12). The 404 path needs no database.
 */
final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        http_response_code(200);
    }

    public function testNowPlayingRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('now-playing', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Now Playing', $output);
        $this->assertStringContainsString('app-shell', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testHistoryRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('history', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('History', $output);
        $this->assertStringContainsString('filter-bar', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testStatisticsRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('statistics', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Statistics', $output);
        $this->assertStringContainsString('stats-kpi-grid', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testLibrariesRouteRendersDashboardShell(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('libraries', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Libraries', $output);
        $this->assertStringContainsString('library-grid', $output);
        $this->assertSame(200, http_response_code());
    }

    public function testUnknownRouteRendersNotFound(): void
    {
        ob_start();
        (new Router(new View()))->dispatch('definitely-not-a-route', null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('PAGE NOT FOUND', $output);
        $this->assertSame(404, http_response_code());
    }
}
