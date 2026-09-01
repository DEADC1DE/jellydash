<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\PlaybackReportingClient;
use Mk\Framework\Jellyfin\PlaybackReportingParser;
use PHPUnit\Framework\TestCase;

final class PlaybackReportingClientTest extends TestCase
{
    public function testActivityChunkRequestsPauseDurationWhenPluginProvidesIt(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(true);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(480, $chunk['rows'][0]['watched_sec']);
        $this->assertStringContainsString('PauseDuration', $client->queries[1] ?? '');
        $this->assertStringNotContainsString('RemoteAddress', $client->queries[1] ?? '');
    }

    public function testActivityChunkKeepsLegacyColumnsWhenPauseDurationIsMissing(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(false);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(600, $chunk['rows'][0]['watched_sec']);
        $this->assertStringNotContainsString('PauseDuration', $client->queries[1] ?? '');
    }

    public function testActivityChunkFallsBackWhenSchemaProbeFails(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(null);

        $chunk = $client->activityChunk(new PlaybackReportingParser(), 0, 25);

        $this->assertCount(1, $chunk['rows']);
        $this->assertSame(600, $chunk['rows'][0]['watched_sec']);
        $this->assertStringNotContainsString('PauseDuration', $client->queries[1] ?? '');
    }

    public function testActivityChunkCachesTheSchemaProbe(): void
    {
        $client = new SchemaAwarePlaybackReportingClient(true);

        $client->activityChunk(new PlaybackReportingParser(), 0, 25);
        $client->activityChunk(new PlaybackReportingParser(), 25, 25);

        $schemaQueries = array_filter(
            $client->queries,
            static fn (string $query): bool => str_starts_with($query, 'PRAGMA table_info'),
        );
        $this->assertCount(1, $schemaQueries);
    }
}

/** @internal */
final class SchemaAwarePlaybackReportingClient extends PlaybackReportingClient
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly ?bool $withPauseDuration)
    {
    }

    /**
     * @return array{columns: array<int, mixed>, results: array<int, mixed>}
     */
    protected function customQuery(string $sql, int $timeout): array
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'PRAGMA table_info')) {
            if ($this->withPauseDuration === null) {
                throw new RuntimeException('Schema probe unavailable.');
            }

            $names = [
                'DateCreated',
                'UserId',
                'ItemId',
                'ItemType',
                'ItemName',
                'PlaybackMethod',
                'ClientName',
                'DeviceName',
                'PlayDuration',
            ];
            if ($this->withPauseDuration) {
                $names[] = 'PauseDuration';
            }

            return [
                'columns' => ['cid', 'name', 'type', 'notnull', 'dflt_value', 'pk'],
                'results' => array_map(
                    static fn (int $cid, string $name): array => [$cid, $name, 'TEXT', 0, null, 0],
                    array_keys($names),
                    $names,
                ),
            ];
        }

        $columns = ['DateCreated', 'UserId', 'ItemId', 'ItemType', 'ItemName', 'PlaybackMethod', 'ClientName', 'DeviceName', 'PlayDuration'];
        $row = ['2026-08-31 20:14:00.1234567', '7654321', '1234567', 'Movie', 'Arrival', 'DirectPlay', 'Emby Web', 'Chrome', '600'];
        if ($this->withPauseDuration === true) {
            $columns[] = 'PauseDuration';
            $row[] = '120';
        }

        return ['columns' => $columns, 'results' => [$row]];
    }
}
