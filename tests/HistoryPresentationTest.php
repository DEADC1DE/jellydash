<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HistoryPresentationTest extends TestCase
{
    public function testFilteredEmptyHistoryOffersAnUnrestrictedPeriod(): void
    {
        $html = $this->twig()->render('history/_empty.twig', ['summary' => ['total' => 12]]);
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[normalize-space(.)="Clear filters"]');

        self::assertSame(1, $links->length);
        self::assertSame('/history?range=all', $links->item(0)->getAttribute('href'));
        self::assertStringContainsString('date range', $html);
        self::assertStringNotContainsString('Import existing history', $html);
    }

    public function testNewInstallationStillOffersHistoryImport(): void
    {
        $html = $this->twig()->render('history/_empty.twig', ['summary' => ['total' => 0]]);
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//a[@href="/settings#import-history"]')->length);
        self::assertStringContainsString('No plays yet', $html);
        self::assertStringNotContainsString('Clear filters', $html);
    }

    public function testPhoneDisclosureRetainsEscapedPlaybackDetails(): void
    {
        $play = [
            'time' => '20:45', 'initials' => 'MJ', 'avatarBg' => '#302040', 'avatarUrl' => '',
            'user' => 'Mártin & Jane', 'title' => 'An <unusually long> title', 'sub' => 'S1 E2',
            'poster' => 'none', 'library' => 'Movies & TV', 'client' => 'Web <client>',
            'device' => 'Living room & television', 'watchedLabel' => '2h 45m watched',
            'methodLabel' => 'Direct Stream', 'isTranscode' => false,
            'finished' => true, 'completionPct' => 100,
        ];
        $html = $this->twig()->render('history/_history_row.twig', ['play' => $play]);
        $xpath = $this->xpath($html);
        $details = $xpath->query('//details')->item(0);

        self::assertInstanceOf(DOMElement::class, $details);
        $summary = $xpath->query('./summary', $details)->item(0)->textContent;
        self::assertStringContainsString('Playback details', $summary);
        self::assertStringContainsString($play['title'], $summary);
        self::assertStringContainsString($play['user'], $summary);
        self::assertStringContainsString('20:45', $summary);
        foreach (['methodLabel', 'client', 'device', 'library'] as $field) {
            self::assertStringContainsString($play[$field], $details->textContent);
        }
        self::assertSame(0, $xpath->query('//unusually | //client')->length);
        self::assertStringContainsString('2h 45m watched', $html);
        self::assertStringNotContainsString('Watched</span>', $html);
        self::assertSame(1, $xpath->query('//*[@aria-label="Finished"]')->length);
    }

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(TEMPLATES_DIR), ['autoescape' => 'html']);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        return new DOMXPath($document);
    }
}
