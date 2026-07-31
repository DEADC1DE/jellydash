<?php

use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the owned auth layer (Phase 7). Needs the database;
 * skipped automatically when it isn't reachable. Uses a throwaway test user.
 */
final class AuthIntegrationTest extends TestCase
{
    private const USERNAME = 'phpunit_testuser';
    private const PASSWORD = 'test-password-123';

    private Database $db;
    private \Dibi\Connection $dibi;

    protected function setUp(): void
    {
        try {
            $this->db = Container::db();
            $this->dibi = $this->db->getDibi();
            // Fresh databases (no SQL import) get the auth tables on demand,
            // the same way the console user commands bootstrap them.
            $this->db->ensureAuthSchema();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $_SESSION = [];
        $this->cleanup();
        $this->db->addAuthUser(self::USERNAME, self::PASSWORD, 'Tester', Authorization::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
        $_SESSION = [];
    }

    public function testSuccessfulLoginSetsSessionAndRole(): void
    {
        $auth = new Authorization($this->db);

        $this->assertTrue($auth->userLogin(self::USERNAME, self::PASSWORD));
        $this->assertTrue($auth->isUserLoggedIn());
        $this->assertSame(self::USERNAME, $auth->getUserData()['username']);

        // role admin(2): has admin, but not owner(1)
        $this->assertTrue($auth->hasRole(Authorization::ROLE_ADMIN));
        $this->assertFalse($auth->hasRole(Authorization::ROLE_OWNER));
    }

    public function testWrongPasswordFails(): void
    {
        $auth = new Authorization($this->db);

        $this->assertFalse($auth->userLogin(self::USERNAME, 'definitely-wrong'));
        $this->assertFalse($auth->isUserLoggedIn());
    }

    public function testLockoutBlocksEvenCorrectPassword(): void
    {
        $auth = new Authorization($this->db);

        for ($i = 0; $i < 5; $i++) {
            $auth->userLogin(self::USERNAME, 'wrong');
        }

        // Now locked out; the correct password is rejected too.
        $this->assertFalse($auth->userLogin(self::USERNAME, self::PASSWORD));
    }

    public function testLogoutClearsSession(): void
    {
        $auth = new Authorization($this->db);
        $auth->userLogin(self::USERNAME, self::PASSWORD);
        $this->assertTrue($auth->isUserLoggedIn());

        $auth->userLogout();
        $this->assertFalse($auth->isUserLoggedIn());
    }

    private function cleanup(): void
    {
        $this->dibi->delete('users')->where('username = %s', self::USERNAME)->execute();
        $this->dibi->delete('login_attempts')->execute();
    }
}
