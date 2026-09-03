<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Authentication + authorization, owned by the framework (no Aura).
 *
 * Login state lives in a small set of session keys; roles come from the
 * users.role column. Lower role number = more privilege (1 owner … 4 guest).
 */
class Authorization
{
    // Roles (users.role). Lower number = more privilege.
    public const ROLE_OWNER = 1;
    public const ROLE_ADMIN = 2;
    public const ROLE_USER = 3;
    public const ROLE_GUEST = 4;

    private const SESSION_USER = 'auth_user';
    private const SESSION_LOGIN_TIME = 'auth_login_time';
    private const SESSION_LAST_ACTIVITY = 'auth_last_activity';

    public const SESSION_IDLE_TIMEOUT = 3600;        // 1h without requests
    public const SESSION_ABSOLUTE_TIMEOUT = 28800;   // 8h since login
    public const REMEMBER_LIFETIME = 7776000;        // rolling 90 days
    public const REMEMBER_COOKIE = 'jellydash_remember';

    // Fixed valid hash to equalize timing when the user doesn't exist.
    private const DUMMY_HASH = '$2y$10$4wJAscRXRu64tOYV47kYFeV5NalHszH7rNTGJw9/FfWq63lOYrqym';

    private Database $db;
    private \Dibi\Connection $dibi;
    private ?RememberTokenRepository $rememberTokens;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(string, int): void */
    private \Closure $rememberCookieWriter;

    public function __construct(
        ?Database $db = null,
        ?RememberTokenRepository $rememberTokens = null,
        ?callable $clock = null,
        ?callable $rememberCookieWriter = null,
    ) {
        $this->db = $db ?? Container::db();
        $this->dibi = $this->db->getDibi();
        $this->rememberTokens = $rememberTokens;
        $this->clock = $clock !== null ? $clock(...) : static fn (): int => time();
        $this->rememberCookieWriter = $rememberCookieWriter !== null
            ? $rememberCookieWriter(...)
            : $this->writeRememberCookie(...);
        $this->enforceTimeouts();

        if (!isset($_SESSION[self::SESSION_USER])) {
            $this->restoreRememberedLogin();
        }
    }

    // --- State -------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $_SESSION[self::SESSION_USER] ?? null;
    }

    public function isUserLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function getUserData(): array
    {
        return $this->user() ?? [];
    }

    public function role(): ?int
    {
        $user = $this->user();
        return isset($user['role']) ? (int) $user['role'] : null;
    }

    // --- Authorization -----------------------------------------------------

    // True if the user holds at least the given role (numerically <=).
    public function hasRole(int $minimumRole): bool
    {
        $role = $this->role();
        return $role !== null && $role <= $minimumRole;
    }

    // Abort with 403 unless the user holds at least the given role.
    public function requireRole(int $minimumRole): void
    {
        if (!$this->hasRole($minimumRole)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    // --- Login / logout ----------------------------------------------------

    // Returns true on success, false on bad credentials / lockout.
    // \Dibi\Exception propagates (handled centrally).
    public function userLogin($username, $password, bool $remember = false): bool
    {
        $username = trim(strtolower((string) $username));
        $ip = Log::userIP();

        if (LoginThrottle::isLocked($username, $ip)) {
            Log::logDebugMessage("Login blocked (throttled) -> {$username} / {$ip}", $this);
            return false;
        }

        $row = $this->dibi->select('*')->from('users')
            ->where('username = %s', $username)->limit(1)->fetch();

        if (!$row || !password_verify((string) $password, (string) $row['password'])) {
            if (!$row) {
                // Equalize timing against a dummy hash (user enumeration defense).
                password_verify((string) $password, self::DUMMY_HASH);
            }
            LoginThrottle::recordFailure($username, $ip);
            Log::logDebugMessage("Wrong password attempt -> {$ip}", $this);
            return false;
        }

        // Success.
        LoginThrottle::clear($username, $ip);

        // Transparently upgrade legacy hashes to the current algorithm.
        if (password_needs_rehash((string) $row['password'], PASSWORD_DEFAULT)) {
            $this->dibi->update('users', ['password' => password_hash((string) $password, PASSWORD_DEFAULT)])
                ->where('id = %i', $row['id'])->execute();
        }

        $this->regenerateId(); // session fixation defense
        $_SESSION[self::SESSION_USER] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'name' => $row['name'],
            'role' => (int) $row['role'],
        ];
        $now = $this->now();
        $_SESSION[self::SESSION_LOGIN_TIME] = $now;
        $_SESSION[self::SESSION_LAST_ACTIVITY] = $now;

        $existingToken = $this->rememberCookie();
        if ($existingToken !== '') {
            $this->tokens()->revoke($existingToken);
        }

        if ($remember) {
            $token = $this->tokens()->issue((int) $row['id'], $now, self::REMEMBER_LIFETIME);
            $this->setRememberCookie($token, $now + self::REMEMBER_LIFETIME);
        } else {
            $this->clearRememberCookie();
        }

        return true;
    }

    public function userLogout(): bool
    {
        $token = $this->rememberCookie();
        if ($token !== '') {
            $this->tokens()->revoke($token);
        }
        $this->clearRememberCookie();
        $this->clearLoginSession();
        $this->regenerateId();
        return true;
    }

    // --- Internals ---------------------------------------------------------

    // Log the user out if the session is idle or past its absolute lifetime.
    private function enforceTimeouts(): void
    {
        if (!isset($_SESSION[self::SESSION_USER])) {
            return;
        }

        $now = $this->now();
        $idle = $now - (int) ($_SESSION[self::SESSION_LAST_ACTIVITY] ?? $now);
        $age = $now - (int) ($_SESSION[self::SESSION_LOGIN_TIME] ?? $now);

        if ($idle > self::SESSION_IDLE_TIMEOUT || $age > self::SESSION_ABSOLUTE_TIMEOUT) {
            // A remembered browser may immediately establish a fresh session.
            // Only an explicit logout revokes its persistent token.
            $this->clearLoginSession();
            $this->regenerateId();
            return;
        }

        $_SESSION[self::SESSION_LAST_ACTIVITY] = $now;
    }

    private function regenerateId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function restoreRememberedLogin(): void
    {
        $token = $this->rememberCookie();
        if ($token === '') {
            return;
        }

        try {
            $now = $this->now();
            $remembered = $this->tokens()->consume($token, $now, self::REMEMBER_LIFETIME);
            if ($remembered === null) {
                $this->clearRememberCookie();
                return;
            }

            $user = $remembered['user'];
            $this->regenerateId();
            $_SESSION[self::SESSION_USER] = [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'name' => (string) $user['name'],
                'role' => (int) $user['role'],
            ];
            $_SESSION[self::SESSION_LOGIN_TIME] = $now;
            $_SESSION[self::SESSION_LAST_ACTIVITY] = $now;
            $this->setRememberCookie($remembered['token'], $now + self::REMEMBER_LIFETIME);
        } catch (\Throwable $e) {
            // A database outage should leave the normal login page available
            // without discarding a token that may work again on the next request.
            Log::logException($e);
        }
    }

    private function clearLoginSession(): void
    {
        unset(
            $_SESSION[self::SESSION_USER],
            $_SESSION[self::SESSION_LOGIN_TIME],
            $_SESSION[self::SESSION_LAST_ACTIVITY]
        );
    }

    private function tokens(): RememberTokenRepository
    {
        return $this->rememberTokens ??= new RememberTokenRepository($this->db);
    }

    private function rememberCookie(): string
    {
        return is_string($_COOKIE[self::REMEMBER_COOKIE] ?? null)
            ? trim($_COOKIE[self::REMEMBER_COOKIE])
            : '';
    }

    private function setRememberCookie(string $token, int $expires): void
    {
        ($this->rememberCookieWriter)($token, $expires);
        $_COOKIE[self::REMEMBER_COOKIE] = $token;
    }

    private function clearRememberCookie(): void
    {
        ($this->rememberCookieWriter)('', $this->now() - 3600);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private function writeRememberCookie(string $value, int $expires): void
    {
        $params = session_get_cookie_params();
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
