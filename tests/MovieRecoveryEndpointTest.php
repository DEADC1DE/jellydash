<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MovieRecoveryEndpointTest extends TestCase
{
    public function testInvalidMethodsAndCsrfFailuresReturnJsonBeforeRecoveryStarts(): void
    {
        foreach (['PUT' => 405, 'DELETE' => 405, 'POST' => 419] as $method => $status) {
            $result = $this->probe($method, false, null, false);
            self::assertSame($status, $result['status']);
            self::assertIsString($result['body']['error'] ?? null);
            self::assertSame([], $result['recoveryTables']);
        }
    }

    public function testAuthenticationAndLowRolesDenyRecoveryBeforeMutation(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $anonymous = $this->probe($method, true, null, true);
            self::assertSame(401, $anonymous['status']);
            self::assertSame([], $anonymous['recoveryTables']);
            foreach ([3, 4] as $role) {
                $result = $this->probe($method, true, $role, true);
                self::assertSame(403, $result['status']);
                self::assertIsString($result['body']['error'] ?? null);
                self::assertSame([], $result['recoveryTables']);
            }
        }
    }

    public function testSignedInManagersNeedCsrfAndValidJsonBeforeRecoveryStarts(): void
    {
        foreach ([1, 2] as $role) {
            $missingToken = $this->probe('POST', true, $role, false);
            self::assertSame(419, $missingToken['status']);
            self::assertSame([], $missingToken['recoveryTables']);
            $emptyBody = $this->probe('POST', true, $role, true);
            self::assertSame(400, $emptyBody['status']);
            self::assertSame([], $emptyBody['recoveryTables']);
        }
    }

    public function testManagerAndOpenDashboardCanReadStatusWithoutChangingRecords(): void
    {
        foreach ([[true, 1], [true, 2], [false, null]] as [$authentication, $role]) {
            $result = $this->probe('GET', $authentication, $role, false, true);
            self::assertSame(200, $result['status']);
            self::assertSame('paused', $result['body']['state']);
            self::assertSame(1, $result['body']['suggestionCount']);
            self::assertSame('Old movie', $result['body']['suggestions'][0]['sourceTitle']);
            self::assertSame('current', $result['body']['suggestions'][0]['candidates'][0]['id']);
            self::assertTrue($result['recordsUnchanged']);
        }
    }

    /** @return array<string, mixed> */
    private function probe(string $method, bool $authentication, ?int $role, bool $validCsrf, bool $seedRecovery = false): array
    {
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $database = \Mk\Framework\Database::sqlite(':memory:');
            $database->ensureAuthSchema();
            \Mk\Framework\Container::set('db', $database);
            $role = $argv[3] === '' ? null : (int) $argv[3];
            $session = ['csrf_token' => 'recovery-fixture-token'];
            if ($role !== null) {
                $database->getDibi()->insert('users', ['id' => 123, 'username' => 'recovery-test', 'name' => 'Recovery Test', 'password' => 'unused', 'role' => $role])->execute();
                $session['auth_user'] = ['id' => 123, 'username' => 'recovery-test', 'name' => 'Recovery Test', 'role' => $role, 'auth_generation' => 0];
                $session['auth_login_time'] = time();
                $session['auth_last_activity'] = time();
            }
            ini_set('session.serialize_handler', 'php_serialize');
            session_set_save_handler(new class($session) implements \SessionHandlerInterface {
                public function __construct(private array $session) {}
                public function open(string $path, string $name): bool { return true; }
                public function close(): bool { return true; }
                public function read(string $id): string { return serialize($this->session); }
                public function write(string $id, string $data): bool { return true; }
                public function destroy(string $id): bool { return true; }
                public function gc(int $maxLifetime): int { return 0; }
            });
            $_SERVER['REQUEST_METHOD'] = $argv[2];
            $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
            $_SERVER['REQUEST_URI'] = '/api/movie-recovery.php';
            $_SERVER['SERVER_PORT'] = '80';
            if ($argv[4] === '1') $_SERVER['HTTP_X_CSRF_TOKEN'] = 'recovery-fixture-token';
            $snapshot = static function () use ($database): string {
                $rows = [];
                foreach (['movie_identity_state', 'movie_identity_items', 'movie_replacements'] as $table) {
                    if ($database->getDibi()->getDatabaseInfo()->hasTable($table)) {
                        $rows[$table] = array_map(static fn (\Dibi\Row $row): array => $row->toArray(), $database->getDibi()->select('*')->from($table)->fetchAll());
                    }
                }
                return serialize($rows);
            };
            if ($argv[5] === '1') {
                $identities = new \Mk\Framework\Jellyfin\MovieIdentityRepository($database);
                $token = $identities->acquireLease(time());
                $identities->saveState(['phase' => 'idle', 'state' => 'paused', 'message' => 'Stored state', 'scan' => 3]);
                $identities->put('old', ['title' => 'Old movie', 'candidateIds' => ['current'], 'sourcePlays' => 2]);
                $identities->put('current', ['title' => 'Current movie', 'year' => 2026]);
                $identities->releaseLease($token);
                new \Mk\Framework\Jellyfin\MovieReplacements($database);
                $_GET = ['action' => 'confirm', 'sourceId' => 'old', 'targetId' => 'current'];
            }
            $before = $snapshot();
            register_shutdown_function(static function () use ($database, $snapshot, $before): void {
                $tables = array_values(array_filter($database->getDibi()->getDatabaseInfo()->getTableNames(), static fn (string $table): bool => str_starts_with($table, 'movie_')));
                echo "\nPROBE:", json_encode(['status' => http_response_code(), 'recoveryTables' => $tables, 'recordsUnchanged' => $before === $snapshot()], JSON_THROW_ON_ERROR);
            });
            require $argv[1] . '/public/api/movie-recovery.php';
            PHP;
        $environment = getenv();
        self::assertIsArray($environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AUTH_ENABLED' => $authentication ? 'true' : 'false',
            'FORCE_HTTPS' => 'false', 'DB_DRIVER' => 'sqlite3', 'DB_NAME' => ':memory:',
            'JELLYFIN_URL' => 'http://127.0.0.1:9/recovery-test', 'JELLYFIN_API_TOKEN' => 'test-only', 'JELLYFIN_API_KEY' => 'test-only',
            'JELLYSEER_URL' => '', 'JELLYSEER_API_TOKEN' => '', 'TRUSTED_PROXIES' => '',
        ]);
        $process = proc_open(
            [PHP_BINARY, '-r', $code, ROOT_DIR, $method, $role === null ? '' : (string) $role, $validCsrf ? '1' : '0', $seedRecovery ? '1' : '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT_DIR,
            $environment,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('', $errors);
        [$body, $probe] = explode("\nPROBE:", $output, 2);
        $result = json_decode($probe, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['body'] = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
