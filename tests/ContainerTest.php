<?php

use Mk\Framework\Container;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the service container (Phase 5 / #9, R6).
 */
final class ContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::reset();
    }

    public function testLoggerIsPsr3AndShared(): void
    {
        $first = Container::logger();
        $second = Container::logger();

        $this->assertInstanceOf(LoggerInterface::class, $first);
        $this->assertSame($first, $second, 'logger() must return the same shared instance');
    }

    public function testResetCreatesAFreshInstance(): void
    {
        $first = Container::logger();
        Container::reset();

        $this->assertNotSame($first, Container::logger());
    }

    public function testSetOverridesService(): void
    {
        $custom = new Logger('test');
        Container::set('logger', $custom);

        $this->assertSame($custom, Container::logger());
    }
}
