<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsDevicesFrontendTest extends TestCase
{
    public function testStatisticsLoadsDevicesAsAnOptionalAsyncSection(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/statistics-devices.js');
        $endpoint = file_get_contents(ROOT_DIR . '/public/api/client-activity.php');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($endpoint);
        $this->assertStringContainsString('data-statistics-devices data-range="{{ stats.range }}" hidden', $template);
        $this->assertStringContainsString('/api/client-activity.php?range=', $script);
        $this->assertStringContainsString('if (!response.ok)', $script);
        $this->assertStringContainsString('section.hidden = false', $script);
        $this->assertStringContainsString('data-device-manage', $template);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $template);
        $this->assertStringContainsString("url.protocol === 'http:' || url.protocol === 'https:'", $script);
        $this->assertStringContainsString('manageLink.hidden = false', $script);
        $this->assertStringContainsString("include_once ROOT_DIR . '/utils/@api-guard.php'", $endpoint);
        $this->assertStringContainsString('session_write_close()', $endpoint);
    }

    public function testDeviceCardsUseTextNodesInsteadOfInterpolatedHtml(): void
    {
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/statistics-devices.js');
        $icons = file_get_contents(TEMPLATES_DIR . '/statistics/_device_icons.twig');

        $this->assertIsString($script);
        $this->assertIsString($icons);
        $this->assertStringContainsString('element.textContent =', $script);
        $this->assertStringContainsString('list.replaceChildren()', $script);
        $this->assertStringContainsString("iconKinds.has(item.icon) ? item.icon : 'device'", $script);
        $this->assertStringContainsString("createElementNS('http://www.w3.org/2000/svg', 'use')", $script);
        $kinds = [
            'android', 'apple', 'audio', 'browser', 'chrome', 'console', 'desktop', 'device',
            'edge', 'firefox', 'home', 'integration', 'opera', 'safari', 'tv', 'windows', 'xbox',
        ];
        foreach ($kinds as $kind) {
            $this->assertStringContainsString("'{$kind}'", $script);
            $this->assertStringContainsString('id="stats-device-icon-' . $kind . '"', $icons);
        }
        $this->assertStringNotContainsString('innerHTML', $script);
    }

    public function testDeviceLayoutIsCompactAndHorizontallyScrollableOnMobile(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.stats-device-grid', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $stylesheet);
        $this->assertStringContainsString('scroll-snap-type: x proximity;', $stylesheet);
        $this->assertStringContainsString('flex: 0 0 min(275px, 78vw);', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: 34px minmax(0, 1fr)', $stylesheet);
        $this->assertStringContainsString('.stats-device-icon {', $stylesheet);
        $this->assertStringContainsString('background: linear-gradient(135deg, #6547d6, #2d65c7);', $stylesheet);
        $this->assertStringContainsString('color: #fff;', $stylesheet);
    }

    public function testStatisticsHeaderSubtitleStylesDoNotMuteNestedActionLabels(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.stats-section-header > span {', $stylesheet);
        $this->assertStringNotContainsString(".stats-section-header span {\n", $stylesheet);
    }

    public function testActiveDeviceUsesALabelDotWithoutTintingTheWholeRow(): void
    {
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($stylesheet);
        $this->assertStringNotContainsString('.stats-device-row.is-active {', $stylesheet);
        $this->assertStringContainsString('.stats-device-row.is-active .stats-device-activity small::before {', $stylesheet);
        $this->assertStringContainsString('background: #34d8a6;', $stylesheet);
    }
}
