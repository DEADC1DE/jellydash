<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

final class SettingsImportPresentationTest extends TestCase
{
    public function testConflictOnlyImportShowsEntriesThatWereNotImported(): void
    {
        $html = $this->renderImport(['inserted' => 0, 'skipped' => 0, 'conflicts' => 2, 'unresolved' => 0, 'error' => '']);

        self::assertStringContainsString('Imported 0 plays, 2 need review and were not imported.', $html);
        self::assertStringNotContainsString('already present', $html);
    }

    public function testMixedImportDoesNotMislabelAllSkippedEntriesAsDuplicates(): void
    {
        $html = $this->renderImport(['inserted' => 1, 'skipped' => 3, 'conflicts' => 2, 'unresolved' => 1, 'error' => '']);

        self::assertStringContainsString('Imported 1 play, skipped 3, 2 need review and were not imported, 1 without Jellyfin runtime.', $html);
        self::assertStringNotContainsString('skipped 3 already present', $html);
    }

    /** @param array{inserted: int, skipped: int, conflicts: int, unresolved: int, error: string} $import */
    private function renderImport(array $import): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['_shell.twig' => '{% block dashboard_content %}{% endblock %}']),
            new FilesystemLoader(TEMPLATES_DIR),
        ]), ['autoescape' => 'html']);

        return $twig->render('settings/index.twig', [
            'can_manage_global' => true,
            'libraries' => [],
            'users' => [],
            'monitoring_ignored_users' => [],
            'ignored_users' => [],
            'import' => $import,
        ]);
    }
}
