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
}
