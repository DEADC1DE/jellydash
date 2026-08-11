<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseHighlightsTest extends TestCase
{
    public function testVersionedHighlightsAreStructuredAndSafe(): void
    {
        $path = ROOT_DIR . '/public/assets/release-highlights/1.2.0.json';

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('1.2.0', $payload['version'] ?? null);
        $this->assertTrue($payload['auto_show'] ?? false);
        $this->assertIsString($payload['title'] ?? null);
        $this->assertNotSame('', trim((string) ($payload['title'] ?? '')));
        $this->assertIsString($payload['summary'] ?? null);
        $this->assertNotSame('', trim((string) ($payload['summary'] ?? '')));
        $this->assertIsArray($payload['highlights'] ?? null);
        $this->assertNotEmpty($payload['highlights']);
        $this->assertIsArray($payload['links'] ?? null);
        $this->assertNotEmpty($payload['links']);

        foreach ($payload['highlights'] as $highlight) {
            $this->assertIsString($highlight);
            $this->assertNotSame('', trim($highlight));
        }

        foreach ($payload['links'] as $link) {
            $this->assertIsArray($link);
            $this->assertIsString($link['label'] ?? null);
            $this->assertNotSame('', trim((string) ($link['label'] ?? '')));
            $this->assertIsString($link['url'] ?? null);

            $parts = parse_url((string) ($link['url'] ?? ''));
            $this->assertIsArray($parts);
            $this->assertSame('https', $parts['scheme'] ?? null);
            $this->assertSame('github.com', $parts['host'] ?? null);
            $this->assertStringStartsWith('/themartz90/jellydash/', (string) ($parts['path'] ?? ''));
        }
    }
}
