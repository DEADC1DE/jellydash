<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsTemplateTest extends TestCase
{
    public function testImportFormPostsPlaybackReportingBackup(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('action="/api/playback-reporting.php"', $template);
        $this->assertStringContainsString('name="commit"', $template);
        $this->assertStringContainsString('data-import-dropzone', $template);
        $this->assertStringContainsString('id="import-history"', $template);
        $this->assertStringContainsString('name="playback_reporting"', $template);
        $this->assertStringContainsString('data-import-plugin', $template);
        $this->assertStringContainsString('data-import-alt', $template);
        $this->assertStringContainsString('asks you to confirm', $template);
        $this->assertStringContainsString('data-import-plugin-broken-note', $template);
        $this->assertStringContainsString('history-import.js?v=20260817-discovery', $template);
        $this->assertStringContainsString('https://github.com/jellyfin/jellyfin-plugin-playbackreporting/pull/131', $template);
        $this->assertStringContainsString('value="plugin"', $template);
        $this->assertStringNotContainsString('Import file', $template);
        $this->assertStringNotContainsString('value="tsv"', $template);
        $this->assertStringNotContainsString('value="sqlite"', $template);
    }

    public function testChangedSettingsExposeAnImmediateSaveAction(): void
    {
        $template = file_get_contents(TEMPLATES_DIR . '/settings/index.twig');
        $script = file_get_contents(ROOT_DIR . '/public/assets/js/settings-dirty.js');
        $stylesheet = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');

        $this->assertIsString($template);
        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('id="settings-form"', $template);
        $this->assertStringContainsString('data-settings-dirty-bar hidden', $template);
        $this->assertStringContainsString('form="settings-form"', $template);
        $this->assertStringContainsString('settings-dirty.js?v=20260818', $template);
        $this->assertStringContainsString("form.addEventListener('change', sync)", $script);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $script);
        $this->assertStringContainsString('.settings-dirty-bar[hidden]', $stylesheet);
    }
}
