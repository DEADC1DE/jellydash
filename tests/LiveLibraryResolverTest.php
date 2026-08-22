<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\LiveLibraryResolver;
use PHPUnit\Framework\TestCase;

final class LiveLibraryResolverTest extends TestCase
{
    public function testUsesConfirmedStoredLibraryWithoutCallingJellyfin(): void
    {
        $calls = 0;
        $resolver = new LiveLibraryResolver(static function (array $ids) use (&$calls): array {
            ++$calls;

            return [];
        });

        $streams = $resolver->resolve([$this->stream()], [0 => 'Anime']);

        $this->assertSame(0, $calls);
        $this->assertSame('Anime', $streams[0]['library']);
        $this->assertTrue($streams[0]['libraryResolved']);
    }

    public function testBatchesUnresolvedItemsAndMarksSuccessfulResults(): void
    {
        $requested = [];
        $resolver = new LiveLibraryResolver(static function (array $ids) use (&$requested): array {
            $requested = $ids;

            return [
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => [
                    'runtime_sec' => 1800,
                    'library' => 'TV Shows',
                ],
            ];
        });

        $first = $this->stream();
        $second = $this->stream();
        $second['id'] = 'session-2';

        $streams = $resolver->resolve([$first, $second]);

        $this->assertSame(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], $requested);
        $this->assertSame('TV Shows', $streams[0]['library']);
        $this->assertTrue($streams[0]['libraryResolved']);
        $this->assertTrue($streams[1]['libraryResolved']);
    }

    public function testUnavailableMetadataKeepsSafeGenericLabel(): void
    {
        $resolver = new LiveLibraryResolver(static function (): array {
            throw new \RuntimeException('Jellyfin unavailable');
        });

        $streams = $resolver->resolve([$this->stream()]);

        $this->assertSame('TV Shows', $streams[0]['library']);
        $this->assertFalse($streams[0]['libraryResolved']);
    }

    public function testLiveTvNeverRequiresAPathLookup(): void
    {
        $calls = 0;
        $resolver = new LiveLibraryResolver(static function () use (&$calls): array {
            ++$calls;

            return [];
        });
        $stream = $this->stream();
        $stream['isLive'] = true;
        $stream['library'] = 'Live TV';

        $streams = $resolver->resolve([$stream]);

        $this->assertSame(0, $calls);
        $this->assertSame('Live TV', $streams[0]['library']);
        $this->assertTrue($streams[0]['libraryResolved']);
    }

    /** @return array<string, mixed> */
    private function stream(): array
    {
        return [
            'id' => 'session-1',
            'itemId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'itemType' => 'Episode',
            'library' => 'TV Shows',
            'libraryResolved' => false,
            'isLive' => false,
        ];
    }
}
