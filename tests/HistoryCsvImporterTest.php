<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryCsvExporter;
use Mk\Framework\Jellyfin\HistoryCsvImporter;
use Mk\Framework\Jellyfin\HistoryCsvParser;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class HistoryCsvImporterTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private PlayHistoryRepository $repository;
    /** @var list<string> */
    private array $files = [];

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
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testExportCanBePreviewedImportedAndSafelyReimported(): void
    {
        $this->insertPlay();
        $path = $this->temporaryFile();
        $stream = fopen($path, 'w+b');
        $this->assertIsResource($stream);

        $exported = (new HistoryCsvExporter($this->repository))->write(
            new HistoryFilters(user: 'PHPUnit Native CSV', range: 'all'),
            $stream,
        );
        fclose($stream);
        $this->assertSame(1, $exported);

        $this->cleanup();
        $importer = new HistoryCsvImporter(Container::db(), new HistoryCsvParser(), $this->repository);
        $this->assertSame([
            'parsed' => 1,
            'importable' => 1,
            'skipped' => 0,
            'kind' => 'jellydash',
        ], $importer->previewFile($path));

        $result = $importer->importFile($path);
        $this->assertSame(['parsed' => 1, 'inserted' => 1, 'skipped' => 0], $result);

        $row = $this->dibi->select('*')->from('play_history')
            ->where('session_key = %s', 'phpunit-native-csv-a')
            ->fetch();
        $this->assertInstanceOf(\Dibi\Row::class, $row);
        $this->assertSame('=A Jellydash title', $row['item_name']);
        $this->assertSame('["SubtitleCodecNotSupported"]', $row['transcode_reasons']);
        $this->assertSame(1, (int) $row['notified']);
        $this->assertSame(1, (int) $row['is_finished']);

        $this->assertSame([
            'parsed' => 1,
            'importable' => 0,
            'skipped' => 1,
            'kind' => 'jellydash',
        ], $importer->previewFile($path));
        $this->assertSame(
            ['parsed' => 1, 'inserted' => 0, 'skipped' => 1],
            $importer->importFile($path),
        );
    }

    public function testSourceTimezoneIsConvertedToTheReceivingInstallation(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        try {
            $path = $this->csvFile($this->csvRow([
                'jellydash_timezone' => 'UTC',
                'session_key' => 'phpunit-native-csv-zone',
                'item_id' => 'phpunit-native-zone-item',
                'started_at' => '2026-08-22 10:00:00',
                'updated_at' => '2026-08-22 11:00:00',
                'ended_at' => '2026-08-22 11:00:00',
            ]));

            $rows = iterator_to_array((new HistoryCsvParser())->iterateFile($path));
            $this->assertCount(1, $rows);
            $this->assertSame('2026-08-22 12:00:00', $rows[0]['started_at']);
            $this->assertSame('2026-08-22 13:00:00', $rows[0]['updated_at']);
            $this->assertSame('2026-08-22 13:00:00', $rows[0]['ended_at']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testMalformedCsvIsRejectedBeforeItCanWriteAnything(): void
    {
        $valid = $this->csvRow([
            'session_key' => 'phpunit-native-csv-before-error',
            'item_id' => 'phpunit-native-before-error-item',
        ]);
        $invalid = $this->csvRow([
            'session_key' => 'phpunit-native-csv-invalid',
            'item_id' => 'phpunit-native-invalid-item',
            'watched_sec' => '-1',
        ]);
        $path = $this->csvFile($valid, $invalid);

        try {
            (new HistoryCsvImporter(Container::db(), new HistoryCsvParser(), $this->repository))->importFile($path);
            $this->fail('Malformed CSV should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid watched_sec', $e->getMessage());
        }

        $count = $this->dibi->select('COUNT(*)')->from('play_history')
            ->where('session_key LIKE %s', 'phpunit-native-csv-%')
            ->fetchSingle();
        $this->assertSame(0, (int) $count);
    }

    public function testUnsupportedHeaderAndDuplicateRowsAreRejected(): void
    {
        $path = $this->temporaryFile();
        file_put_contents($path, "wrong,header\n1,2\n");
        $parser = new HistoryCsvParser();

        try {
            iterator_to_array($parser->iterateFile($path));
            $this->fail('Unsupported header should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not a supported Jellydash History CSV', $e->getMessage());
        }

        $row = $this->csvRow();
        $duplicatePath = $this->csvFile($row, $row);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicates an earlier play');
        iterator_to_array($parser->iterateFile($duplicatePath));
    }

    /** @param array<string, string> $overrides */
    private function csvRow(array $overrides = []): array
    {
        return array_merge([
            'jellydash_history_version' => HistoryCsvExporter::FORMAT_VERSION,
            'jellydash_timezone' => date_default_timezone_get(),
            'session_key' => 'phpunit-native-csv-row',
            'started_at' => '2026-08-22 10:00:00',
            'updated_at' => '2026-08-22 10:30:00',
            'ended_at' => '',
            'user_id' => 'phpunit-native-user',
            'user_name' => 'PHPUnit Native CSV',
            'item_id' => 'phpunit-native-item',
            'item_type' => 'Movie',
            'series_name' => '',
            'item_name' => "''Twas a fixture",
            'season_ep' => '',
            'library' => 'Movies',
            'library_resolved_at' => '2026-08-22 10:00:00',
            'play_method' => 'DirectPlay',
            'play_method_detail' => 'Direct Play',
            'client' => 'Jellyfin Web',
            'device' => 'Chrome',
            'source_video_codec' => 'H.264',
            'source_audio_codec' => 'AAC',
            'source_container' => 'MP4',
            'target_video_codec' => '',
            'target_audio_codec' => '',
            'target_container' => '',
            'is_video_direct' => '1',
            'is_audio_direct' => '1',
            'transcode_reasons' => '',
            'watched_sec' => '1800',
            'runtime_sec' => '3600',
            'is_finished' => '0',
        ], $overrides);
    }

    /** @param array<string, string> ...$rows */
    private function csvFile(array ...$rows): string
    {
        $path = $this->temporaryFile();
        $stream = fopen($path, 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, HistoryCsvExporter::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $column): string => $row[$column], HistoryCsvExporter::COLUMNS), ',', '"', '');
        }
        fclose($stream);

        return $path;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jellydash-history-csv-');
        $this->assertIsString($path);
        $this->files[] = $path;

        return $path;
    }

    private function insertPlay(): void
    {
        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-native-csv-a',
            'user_id' => 'phpunit-native-user',
            'user_name' => 'PHPUnit Native CSV',
            'item_id' => 'phpunit-native-item-a',
            'item_type' => 'Movie',
            'item_name' => '=A Jellydash title',
            'library' => 'Movies',
            'library_resolved_at' => '2026-08-22 10:00:00',
            'play_method' => 'Transcode',
            'play_method_detail' => 'Video Transcode',
            'client' => 'Jellyfin Web',
            'device' => 'Chrome',
            'source_video_codec' => 'HEVC',
            'source_audio_codec' => 'EAC3',
            'source_container' => 'MKV',
            'target_video_codec' => 'H.264',
            'target_audio_codec' => 'AAC',
            'target_container' => 'TS',
            'is_video_direct' => 0,
            'is_audio_direct' => 0,
            'transcode_reasons' => '["SubtitleCodecNotSupported"]',
            'watched_sec' => 3600,
            'runtime_sec' => 3600,
            'started_at' => '2026-08-22 10:00:00',
            'updated_at' => '2026-08-22 11:00:00',
            'ended_at' => '2026-08-22 11:00:00',
            'is_finished' => 1,
            'notified' => 0,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-native-csv-%')
            ->execute();
    }
}
