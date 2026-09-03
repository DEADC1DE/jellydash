<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class HistoryCsvExporterTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private PlayHistoryRepository $repository;

    protected function setUp(): void
    {
        try {
            $this->dibi = Container::db()->getDibi();
            $this->repository = new PlayHistoryRepository(Container::db());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
    }

    public function testQueryFiltersRejectArraysAndKeepOnlySupportedValues(): void
    {
        $filters = HistoryFilters::fromQuery([
            'search' => '  Arrival  ',
            'user' => ['not', 'valid'],
            'library' => 'Movies',
            'range' => 'forever',
            'p' => '9',
        ]);

        $this->assertSame('Arrival', $filters->search);
        $this->assertSame('', $filters->user);
        $this->assertSame('Movies', $filters->library);
        $this->assertSame('30', $filters->range);
        $this->assertSame([
            'search' => 'Arrival',
            'library' => 'Movies',
        ], $filters->queryParameters());
    }

    public function testExportUsesAllFilteredRowsInsteadOfTheVisiblePage(): void
    {
        $this->insertPlay('phpunit-csv-a', '2026-08-22 12:00:00', 'Newest, with comma');
        $this->insertPlay('phpunit-csv-b', '2026-08-22 11:00:00', '=HYPERLINK("https://invalid.example")');
        $this->insertPlay('phpunit-csv-other', '2026-08-22 10:00:00', 'Not selected', 'Someone else');

        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        $count = (new HistoryCsvExporter($this->repository))->write(
            new HistoryFilters(user: 'PHPUnit CSV Export', range: 'all', limit: 1, offset: 1),
            $stream,
            new DateTimeImmutable('2026-08-22 13:00:00'),
        );

        rewind($stream);
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));

        $header = fgetcsv($stream, null, ',', '"', '');
        $newest = fgetcsv($stream, null, ',', '"', '');
        $older = fgetcsv($stream, null, ',', '"', '');
        $end = fgetcsv($stream, null, ',', '"', '');
        fclose($stream);

        $this->assertSame(2, $count);
        $this->assertSame(HistoryCsvExporter::COLUMNS, $header);
        $this->assertIsArray($newest);
        $this->assertIsArray($older);
        $this->assertFalse($end);

        $newestRow = array_combine($header, $newest);
        $olderRow = array_combine($header, $older);
        $this->assertIsArray($newestRow);
        $this->assertIsArray($olderRow);
        $this->assertSame(HistoryCsvExporter::FORMAT_VERSION, $newestRow['jellydash_history_version']);
        $this->assertSame(date_default_timezone_get(), $newestRow['jellydash_timezone']);
        $this->assertSame('Newest, with comma', $newestRow['item_name']);
        $this->assertSame('2026-08-22 12:00:00', $newestRow['started_at']);
        $this->assertSame('PHPUnit CSV Export', $newestRow['user_name']);
        $this->assertSame("'=HYPERLINK(\"https://invalid.example\")", $olderRow['item_name']);
        $this->assertArrayNotHasKey('id', $newestRow);
        $this->assertArrayNotHasKey('notified', $newestRow);
    }

    public function testExportCapturesOneDefaultClockForAllCursorBatches(): void
    {
        $exportNow = new DateTimeImmutable('2030-01-31 12:00:00');
        for ($index = 0; $index < 500; $index++) {
            $this->insertPlay(
                'phpunit-csv-clock-recent-' . $index,
                '2030-01-31 12:00:00',
                'Recent ' . $index,
            );
        }

        $this->insertPlay(
            'phpunit-csv-clock-boundary',
            '2030-01-02 12:00:00',
            'Boundary row',
        );

        $clockCalls = 0;
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);

        try {
            $count = (new HistoryCsvExporter($this->repository, static function () use (&$clockCalls, $exportNow): DateTimeImmutable {
                $clockCalls++;

                return $exportNow;
            }))->write(
                new HistoryFilters(user: 'PHPUnit CSV Export', range: '30'),
                $stream,
            );
        } finally {
            fclose($stream);
        }

        $this->assertSame(501, $count);
        $this->assertSame(1, $clockCalls);
    }

    public function testEndpointIsAuthenticatedAndDownloadsAFilteredCsv(): void
    {
        $endpoint = (string) file_get_contents(ROOT_DIR . '/public/api/history-export.php');

        $this->assertStringContainsString("'/utils/@api-guard.php'", $endpoint);
        $this->assertStringContainsString('HistoryFilters::fromQuery($_GET)', $endpoint);
        $this->assertStringContainsString('Content-Type: text/csv; charset=utf-8', $endpoint);
        $this->assertStringContainsString('Content-Disposition: attachment;', $endpoint);
        $this->assertStringContainsString('php://temp/maxmemory:5242880', $endpoint);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD']", $endpoint);
        $this->assertStringContainsString("(string) \$_GET['preview'] === '1'", $endpoint);
        $this->assertStringContainsString('historyTotal($filters)', $endpoint);
        $this->assertStringContainsString("'plays' =>", $endpoint);
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $endpoint);
    }

    private function insertPlay(
        string $sessionKey,
        string $startedAt,
        string $itemName,
        string $userName = 'PHPUnit CSV Export',
    ): void {
        $this->dibi->insert('play_history', [
            'session_key' => $sessionKey,
            'user_id' => 'phpunit-csv-user',
            'user_name' => $userName,
            'item_id' => $sessionKey . '-item',
            'item_type' => 'Movie',
            'item_name' => $itemName,
            'library' => 'Movies',
            'library_resolved_at' => $startedAt,
            'play_method' => 'DirectPlay',
            'play_method_detail' => 'Direct Play',
            'client' => 'Jellyfin Web',
            'device' => 'Chrome',
            'source_video_codec' => 'H.264',
            'source_audio_codec' => 'AAC',
            'source_container' => 'MP4',
            'is_video_direct' => 1,
            'is_audio_direct' => 1,
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'is_finished' => 0,
            'notified' => 1,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-csv-%')
            ->execute();
    }
}
