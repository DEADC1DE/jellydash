<?php

use Mk\Framework\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for environment-backed Config (Phase 3 / #13, S14).
 */
final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Fully clear test keys from every source Config reads (a loaded .env
        // populates $_ENV, $_SERVER and getenv).
        foreach (['CFG_FOO', 'CFG_FLAG', 'APP_DEBUG', 'APP_ENV'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', Config::get('CFG_FOO', 'fallback'));
        $this->assertNull(Config::get('CFG_FOO'));
    }

    public function testGetReadsEnv(): void
    {
        $_ENV['CFG_FOO'] = 'bar';
        $this->assertSame('bar', Config::get('CFG_FOO'));
    }

    public function testBoolParsing(): void
    {
        $_ENV['CFG_FLAG'] = 'true';
        $this->assertTrue(Config::bool('CFG_FLAG'));

        $_ENV['CFG_FLAG'] = '0';
        $this->assertFalse(Config::bool('CFG_FLAG'));

        $this->assertTrue(Config::bool('CFG_MISSING', true));
    }

    public function testEnvDefaultsToProduction(): void
    {
        $this->assertSame('production', Config::env());
        $this->assertFalse(Config::isDebug());
        $this->assertTrue(Config::isProduction());
    }
}
