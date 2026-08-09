<?php

use Mk\Framework\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CSRF token generation and validation (Phase 2 / S2).
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testTokenIsStableWithinSession(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first)); // 32 random bytes, hex-encoded
    }

    public function testValidateAcceptsCurrentToken(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::validate($token));
    }

    public function testValidateRejectsWrongOrMissingToken(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::validate('not-the-token'));
        $this->assertFalse(Csrf::validate(null));
    }

    public function testValidateRejectsWhenNoSessionToken(): void
    {
        // No token generated yet, so nothing should validate.
        $this->assertFalse(Csrf::validate('anything'));
    }

    public function testValidateHeaderUsesTheCustomRequestHeader(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        $this->assertTrue(Csrf::validateHeader());

        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';
        $this->assertFalse(Csrf::validateHeader());
    }
}
