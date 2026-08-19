<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatisticsRangeFrontendTest extends TestCase
{
    public function testRangePickerExposesTheDefaultViewAction(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/statistics/index.twig');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        $controller = file_get_contents(ROOT_DIR . '/src/Pages/StatisticsController.php');
        $request = file_get_contents(ROOT_DIR . '/operations/@request.php');

        $this->assertIsString($template);
        $this->assertIsString($stylesheet);
        $this->assertIsString($controller);
        $this->assertIsString($request);
        $this->assertStringContainsString('class="statistics-range-picker"', $template);
        $this->assertStringContainsString('Viewing period', $template);
        $this->assertStringContainsString('Default view', $template);
        $this->assertStringContainsString('Make default', $template);
        $this->assertStringContainsString('name="csrf_token"', $template);
        $this->assertStringContainsString('aria-current="page"', $template);
        $this->assertStringContainsString('.statistics-range-control a.is-active', $stylesheet);
        $this->assertStringContainsString("AppSettings::get('statistics_default_range')", $controller);
        $this->assertStringContainsString("AppSettings::set('statistics_default_range'", $request);
        $this->assertStringContainsString("requestIs('statistics-default')", $request);
    }
}
