<?php

use Mk\Framework\Config;
use Mk\Framework\Router;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test: verifies the bootstrap, Composer PSR-4 autoload, and constants are
 * all wired up. The minimal "is the net working" check.
 */
final class SmokeTest extends TestCase
{
    public function testFrameworkConstantsAreLoaded(): void
    {
        $this->assertTrue(defined('ROOT_DIR'));
        $this->assertTrue(defined('TEMPLATES_DIR'));
    }

    public function testFrameworkClassesAutoloadViaPsr4(): void
    {
        $this->assertTrue(class_exists(Config::class));
        $this->assertTrue(class_exists(Router::class));
    }

    public function testComposerVendorClassesAreAvailable(): void
    {
        $this->assertTrue(class_exists(\Twig\Environment::class));
        $this->assertTrue(class_exists(\Dibi\Connection::class));
    }
}
