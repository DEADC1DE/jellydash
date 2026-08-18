<?php

use Mk\Framework\Authorization;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\LoginThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the owned auth layer (Phase 7). Needs the database;
 * skipped automatically when it isn't reachable. Uses a throwaway test user.
 */
final class AuthIntegrationTest extends TestCase
{
    private const USERNAME = 'phpunit_testuser';
    private const PASSWORD = 'test-password-123';
    private const ENV_USERNAME = 'phpunit_env_admin';
    private const ENV_PASSWORD = 'environment-password-123';
    private const CLI_USERNAME = 'phpunit_cli_admin';
    private const CLI_PASSWORD = 'command-password-123';

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

    public function testExpiredLockoutStartsWithOneFreshFailure(): void
    {
        $identifier = self::USERNAME . '|203.0.113.10';
        $this->dibi->insert('login_attempts', [
            'identifier' => $identifier,
            'attempts' => 5,
            'locked_until' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->execute();

        LoginThrottle::recordFailure(self::USERNAME, '203.0.113.10');

        $row = $this->dibi->select('attempts, locked_until')
            ->from('login_attempts')
            ->where('identifier = %s', $identifier)
            ->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testLogoutClearsSession(): void
    {
        $auth = new Authorization($this->db);
        $auth->userLogin(self::USERNAME, self::PASSWORD);
        $this->assertTrue($auth->isUserLoggedIn());

        $auth->userLogout();
        $this->assertFalse($auth->isUserLoggedIn());
    }

    public function testUserEnsureReadsInitialAdminFromEnvironment(): void
    {
        $result = $this->runConsole(['user:ensure'], [
            'AUTH_ADMIN_USER' => self::ENV_USERNAME,
            'AUTH_ADMIN_PASSWORD' => self::ENV_PASSWORD,
        ]);

        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $row = $this->dibi->select('username, password, role')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(self::ENV_USERNAME, (string) $row['username']);
        $this->assertTrue(password_verify(self::ENV_PASSWORD, (string) $row['password']));
        $this->assertSame(Authorization::ROLE_OWNER, (int) $row['role']);
    }

    public function testUserEnsureStillAcceptsCommandLineCredentials(): void
    {
        $result = $this->runConsole([
            'user:ensure',
            self::CLI_USERNAME,
            self::CLI_PASSWORD,
        ], [
            'AUTH_ADMIN_USER' => self::ENV_USERNAME,
            'AUTH_ADMIN_PASSWORD' => self::ENV_PASSWORD,
        ]);

        $this->assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $row = $this->dibi->select('username, password, role')->from('users')
            ->where('username = %s', self::CLI_USERNAME)->fetch();
        $this->assertNotFalse($row);
        $this->assertTrue(password_verify(self::CLI_PASSWORD, (string) $row['password']));
        $this->assertSame(0, (int) $this->dibi->select('COUNT(*)')->from('users')
            ->where('username = %s', self::ENV_USERNAME)->fetchSingle());
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environmentOverrides
     *
     * @return array{exitCode: int, output: string, error: string}
     */
    private function runConsole(array $arguments, array $environmentOverrides): array
    {
        $environment = getenv();
        $this->assertIsArray($environment);

        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'DB_HOST' => DATABASE_HOST,
            'DB_DRIVER' => DATABASE_DRIVER_DIBI,
            'DB_NAME' => DATABASE_NAME,
            'DB_USER' => DATABASE_USERNAME,
            'DB_PASS' => DATABASE_PASSWORD,
        ], $environmentOverrides);
        if (DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $environment['DB_PORT'] = (string) DATABASE_PORT;
        }

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/bin/console.php', ...$arguments],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ROOT_DIR,
            $environment
        );
        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'output' => $output,
            'error' => $error,
        ];
    }

    private function cleanup(): void
    {
        $this->dibi->delete('users')->where('username IN %in', [
            self::USERNAME,
            self::ENV_USERNAME,
            self::CLI_USERNAME,
        ])->execute();
        $this->dibi->delete('login_attempts')->execute();
    }
}
