<?php

use Mk\Framework\Main;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Main helpers, including regression tests pinning the Phase 1 fixes:
 *  - #1 capturePostPassword (broken, removed)
 *  - #2 setSessionValue (previously could never store)
 */
final class MainTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    // Regression for bug #2: setSessionValue used to always return false.
    public function testSetAndGetSessionValueRoundTrip(): void
    {
        $this->assertTrue(Main::setSessionValue('greeting', 'hello'));
        $this->assertSame('hello', Main::getSessionValue('greeting'));
    }

    public function testSetSessionValueRejectsEmptyName(): void
    {
        $this->assertFalse(Main::setSessionValue('', 'value'));
        $this->assertSame([], $_SESSION);
    }

    // Regression for bug #1: the broken password escaper must stay gone.
    public function testBrokenPasswordEscaperIsRemoved(): void
    {
        $this->assertFalse(method_exists(Main::class, 'capturePostPassword'));
    }

    public function testIsPostSet(): void
    {
        $_POST = ['name' => 'Alice'];
        $this->assertTrue(Main::isPostSet('name'));
        $this->assertFalse(Main::isPostSet('missing'));
    }

    public function testValidateEmail(): void
    {
        $this->assertSame('user@example.com', Main::validateEmail('user@example.com'));
        $this->assertNull(Main::validateEmail('not-an-email'));
    }

    public function testArrayItemsNotEmpty(): void
    {
        $this->assertTrue(Main::arrayItemsNotEmpty(['a', 'b']));
        $this->assertFalse(Main::arrayItemsNotEmpty(['a', '']));
        $this->assertFalse(Main::arrayItemsNotEmpty(['a', '   ']));
    }
}
