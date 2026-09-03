<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\StatisticsPayloadCache;
use PHPUnit\Framework\TestCase;

final class StatisticsPayloadCacheTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jellydash-statistics-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (['week', 'month', 'year', 'all'] as $range) {
            @unlink($this->cacheFile($range));
            @unlink($this->cacheFile($range) . '.lock');
        }
        @unlink($this->cacheDirectory);
        @rmdir($this->cacheDirectory);
    }

    public function testFreshEntryUsesTheBuilderOnlyOnce(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $builder = static function () use (&$calls): array {
            ++$calls;

            return ['value' => $calls];
        };
        $now = new DateTimeImmutable('2026-09-01 12:00:00');

        $this->assertSame(['value' => 1], $cache->remember('week', $now, 'history-v1', $builder));
        $this->assertSame(['value' => 1], $cache->remember('week', $now->modify('+30 seconds'), 'history-v1', $builder));
        $this->assertSame(1, $calls);
    }

    public function testEachNormalizedRangeUsesItsOwnCacheEntry(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $now = new DateTimeImmutable('2026-09-01 12:00:00');

        $week = $cache->remember('invalid', $now, 'history-v1', static function () use (&$calls): array {
            ++$calls;

            return ['range' => 'week'];
        });
        $month = $cache->remember('month', $now, 'history-v1', static function () use (&$calls): array {
            ++$calls;

            return ['range' => 'month'];
        });

        $this->assertSame(['range' => 'week'], $week);
        $this->assertSame(['range' => 'month'], $month);
        $this->assertFileExists($this->cacheFile('week'));
        $this->assertFileExists($this->cacheFile('month'));
        $this->assertSame(2, $calls);
    }

    public function testTtlExpiryRebuildsThePayload(): void
    {
        $cache = $this->cache(120);
        $calls = 0;
        $builder = static function () use (&$calls): array {
            ++$calls;

            return ['build' => $calls];
        };
        $now = new DateTimeImmutable('2026-09-01 12:00:00');

        $cache->remember('week', $now, 'history-v1', $builder);
        $this->assertSame(['build' => 1], $cache->remember('week', $now->modify('+119 seconds'), 'history-v1', $builder));
        $this->assertSame(['build' => 2], $cache->remember('week', $now->modify('+120 seconds'), 'history-v1', $builder));
    }

    public function testCalendarMidnightExpiresEvenWithinTheTtl(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $builder = static function () use (&$calls): array {
            ++$calls;

            return ['build' => $calls];
        };
        $beforeMidnight = new DateTimeImmutable('2026-09-01 23:59:30');

        $cache->remember('week', $beforeMidnight, 'history-v1', $builder);
        $this->assertSame(
            ['build' => 2],
            $cache->remember('week', $beforeMidnight->modify('+31 seconds'), 'history-v1', $builder),
        );
    }

    public function testChangedContextFingerprintRebuildsThePayload(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $builder = static function () use (&$calls): array {
            ++$calls;

            return ['build' => $calls];
        };
        $now = new DateTimeImmutable('2026-09-01 12:00:00');

        $cache->remember('week', $now, 'history-v1', $builder);
        $this->assertSame(['build' => 2], $cache->remember('week', $now, 'history-v2', $builder));
    }

    public function testCorruptOrOldSchemaEntriesAreRebuilt(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $builder = static function () use (&$calls): array {
            ++$calls;

            return ['build' => $calls];
        };
        $now = new DateTimeImmutable('2026-09-01 12:00:00');

        $this->writeRaw('not json');
        $this->assertSame(['build' => 1], $cache->remember('week', $now, 'history-v1', $builder));

        $this->writeEnvelope([
            'schema_version' => 999,
            'range' => 'week',
            'context_fingerprint' => 'history-v1',
            'generated_at' => $now->getTimestamp(),
            'local_date' => $now->format('Y-m-d'),
            'stats' => ['build' => 1],
        ]);
        $this->assertSame(['build' => 2], $cache->remember('week', $now, 'history-v1', $builder));
    }

    public function testCacheAndLockFailuresStillReturnFreshStats(): void
    {
        $fileInsteadOfDirectory = $this->cacheDirectory . '.file';
        file_put_contents($fileInsteadOfDirectory, 'not a directory');
        $cache = new StatisticsPayloadCache($fileInsteadOfDirectory);

        $this->assertSame(
            ['fresh' => true],
            $cache->remember('week', new DateTimeImmutable('2026-09-01 12:00:00'), 'history-v1', static fn (): array => ['fresh' => true]),
        );

        @unlink($fileInsteadOfDirectory);
    }

    public function testBuilderExceptionDoesNotReturnAnExpiredPayload(): void
    {
        $cache = $this->cache();
        $now = new DateTimeImmutable('2026-09-01 12:00:00');
        $cache->remember('week', $now, 'history-v1', static fn (): array => ['stale' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Statistics calculation failed');
        $cache->remember(
            'week',
            $now->modify('+121 seconds'),
            'history-v1',
            static function (): array {
                throw new RuntimeException('Statistics calculation failed');
            },
        );
    }

    private function cache(int $ttl = 120): StatisticsPayloadCache
    {
        return new StatisticsPayloadCache($this->cacheDirectory, $ttl);
    }

    private function cacheFile(string $range): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'statistics-' . $range . '.json';
    }

    private function writeRaw(string $contents): void
    {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0775, true);
        }
        file_put_contents($this->cacheFile('week'), $contents);
    }

    /** @param array<string, mixed> $payload */
    private function writeEnvelope(array $payload): void
    {
        $this->writeRaw(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
