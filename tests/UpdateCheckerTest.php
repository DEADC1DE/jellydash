<?php

declare(strict_types=1);

use Mk\Framework\Updates\UpdateChecker;
use PHPUnit\Framework\TestCase;

final class UpdateCheckerTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/jellydash-update-check-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
        @unlink($this->cacheFile . '.lock');
    }

    public function testReportsAvailableUpdateAndUsesFreshCache(): void
    {
        $calls = 0;
        $checker = $this->checker(
            static function (?string $etag) use (&$calls): array {
                $calls++;

                return [
                    'status' => 200,
                    'body' => self::releaseJson('v1.1.0'),
                    'etag' => '"release-1.1.0"',
                ];
            },
            1000,
        );

        $first = $checker->status('1.0.1');
        $second = $checker->status('v1.0.1');

        $this->assertTrue($first['checked']);
        $this->assertTrue($first['fresh']);
        $this->assertTrue($first['update_available']);
        $this->assertSame('1.1.0', $first['latest_version']);
        $this->assertSame('https://github.com/themartz90/jellydash/releases/tag/v1.1.0', $first['release_url']);
        $this->assertSame(1000, $first['checked_at']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function testCurrentAndNewerInstallationsDoNotReportAnUpdate(): void
    {
        $checker = $this->checker(
            static fn (?string $etag): array => [
                'status' => 200,
                'body' => self::releaseJson('v1.0.1'),
                'etag' => null,
            ],
            1000,
        );

        $this->assertFalse($checker->status('1.0.1')['update_available']);
        $this->assertFalse($checker->status('1.1.0')['update_available']);
    }

    public function testStaleCacheUsesEtagAndAcceptsNotModified(): void
    {
        $this->writeCache([
            'version' => '1.0.1',
            'url' => 'https://github.com/themartz90/jellydash/releases/tag/v1.0.1',
            'etag' => '"release-1.0.1"',
            'checked_at' => 100,
            'retry_after' => 0,
        ]);

        $receivedEtag = null;
        $checker = $this->checker(
            static function (?string $etag) use (&$receivedEtag): array {
                $receivedEtag = $etag;

                return ['status' => 304, 'body' => '', 'etag' => $etag];
            },
            5000,
            cacheTtl: 100,
        );

        $status = $checker->status('1.0.1');
        $cache = $this->readCache();

        $this->assertTrue($status['checked']);
        $this->assertTrue($status['fresh']);
        $this->assertFalse($status['update_available']);
        $this->assertSame('"release-1.0.1"', $receivedEtag);
        $this->assertSame(5000, $status['checked_at']);
        $this->assertSame(5000, $cache['checked_at']);
    }

    public function testFailureKeepsAStaleReleaseAndBacksOff(): void
    {
        $this->writeCache([
            'version' => '1.2.0',
            'url' => 'https://github.com/themartz90/jellydash/releases/tag/v1.2.0',
            'etag' => '"release-1.2.0"',
            'checked_at' => 100,
            'retry_after' => 0,
        ]);

        $calls = 0;
        $checker = $this->checker(
            static function (?string $etag) use (&$calls): array {
                $calls++;
                throw new RuntimeException('GitHub unavailable');
            },
            5000,
            cacheTtl: 100,
            failureTtl: 600,
        );

        $first = $checker->status('1.0.1');
        $second = $checker->status('1.0.1');

        $this->assertTrue($first['checked']);
        $this->assertFalse($first['fresh']);
        $this->assertTrue($first['update_available']);
        $this->assertSame(100, $first['checked_at']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertSame(5600, $this->readCache()['retry_after']);
    }

    public function testFailureWithoutCacheReturnsUnknownAndBacksOff(): void
    {
        $calls = 0;
        $checker = $this->checker(
            static function (?string $etag) use (&$calls): array {
                $calls++;
                throw new RuntimeException('GitHub unavailable');
            },
            5000,
            failureTtl: 600,
        );

        $first = $checker->status('1.0.1');
        $second = $checker->status('1.0.1');

        $this->assertFalse($first['checked']);
        $this->assertFalse($first['fresh']);
        $this->assertFalse($first['update_available']);
        $this->assertNull($first['latest_version']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertSame(5600, $this->readCache()['retry_after']);
    }

    public function testInvalidReleasePayloadIsNotTrusted(): void
    {
        $checker = $this->checker(
            static fn (?string $etag): array => [
                'status' => 200,
                'body' => json_encode([
                    'tag_name' => 'not-a-version',
                    'html_url' => 'https://example.com/not-jellydash',
                ], JSON_THROW_ON_ERROR),
                'etag' => '"invalid"',
            ],
            1000,
        );

        $status = $checker->status('1.0.1');

        $this->assertFalse($status['checked']);
        $this->assertFalse($status['fresh']);
        $this->assertFalse($status['update_available']);
        $this->assertNull($status['release_url']);
    }

    public function testReleaseUrlMustMatchReportedTag(): void
    {
        $checker = $this->checker(
            static fn (?string $etag): array => [
                'status' => 200,
                'body' => json_encode([
                    'tag_name' => 'v1.2.0',
                    'html_url' => 'https://github.com/themartz90/jellydash/releases/tag/v9.9.9',
                ], JSON_THROW_ON_ERROR),
                'etag' => '"mismatched"',
            ],
            1000,
        );

        $status = $checker->status('1.0.1');

        $this->assertFalse($status['checked']);
        $this->assertFalse($status['fresh']);
        $this->assertFalse($status['update_available']);
        $this->assertNull($status['release_url']);
    }

    private function checker(
        callable $fetcher,
        int $now,
        int $cacheTtl = 43200,
        int $failureTtl = 3600,
    ): UpdateChecker {
        return new UpdateChecker(
            $fetcher,
            $this->cacheFile,
            static fn (): int => $now,
            $cacheTtl,
            $failureTtl,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function readCache(): array
    {
        $payload = json_decode((string) file_get_contents($this->cacheFile), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        return $payload;
    }

    private static function releaseJson(string $tag): string
    {
        return json_encode([
            'tag_name' => $tag,
            'html_url' => 'https://github.com/themartz90/jellydash/releases/tag/' . $tag,
        ], JSON_THROW_ON_ERROR);
    }
}
