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

    private const IDLE_TIMEOUT = 3600;        // 1h of inactivity
    private const ABSOLUTE_TIMEOUT = 28800;   // 8h since login

    // Fixed valid hash to equalize timing when the user doesn't exist.
    private const DUMMY_HASH = '$2y$10$4wJAscRXRu64tOYV47kYFeV5NalHszH7rNTGJw9/FfWq63lOYrqym';

    private Database $db;
    private \Dibi\Connection $dibi;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Container::db();
        $this->dibi = $this->db->getDibi();
        $this->enforceTimeouts();
        $this->resumeFromRememberCookie();
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
        $_SESSION[self::SESSION_LOGIN_TIME] = time();
        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();

        if ($remember) {
            $this->setRememberCookie(RememberToken::issue((int) $row['id']));
        }

        return true;
    }

    // Explicit, user-initiated sign-out: also forgets this device's "stay
    // signed in" token. A plain session timeout must NOT do this (see
    // clearSession()) or remember-me would never survive past IDLE_TIMEOUT.
    public function userLogout(): bool
    {
        $this->clearSession();

        $cookie = $_COOKIE[RememberToken::COOKIE_NAME] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            RememberToken::forget($cookie);
        }
        $this->clearRememberCookie();

        return true;
    }

    // --- Internals ---------------------------------------------------------

    // If no session is active but a valid "stay signed in" cookie is
    // presented, transparently resume the session from it (and rotate the
    // token). Runs once per request, before any isUserLoggedIn() check.
    private function resumeFromRememberCookie(): void
    {
        if (isset($_SESSION[self::SESSION_USER])) {
            return;
        }

        $cookie = $_COOKIE[RememberToken::COOKIE_NAME] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return;
        }

        $result = RememberToken::consume($cookie);
        if ($result === null) {
            $this->clearRememberCookie();
            return;
        }

        $this->regenerateId();
        $_SESSION[self::SESSION_USER] = [
            'id' => (int) $result['user']['id'],
            'username' => $result['user']['username'],
            'name' => $result['user']['name'],
            'role' => (int) $result['user']['role'],
        ];
        $_SESSION[self::SESSION_LOGIN_TIME] = time();
        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();

        $this->setRememberCookie($result['cookie']);
    }

    private function setRememberCookie(string $value): void
    {
        setcookie(RememberToken::COOKIE_NAME, $value, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        if (!isset($_COOKIE[RememberToken::COOKIE_NAME])) {
            return;
        }

        setcookie(RememberToken::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // Mirrors the detection in utils/@settings.php for the session cookie.
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    // Log the user out if the session is idle or past its absolute lifetime.
    private function enforceTimeouts(): void
    {
        if (!isset($_SESSION[self::SESSION_USER])) {
            return;
        }

        $now = time();
        $idle = $now - (int) ($_SESSION[self::SESSION_LAST_ACTIVITY] ?? $now);
        $age = $now - (int) ($_SESSION[self::SESSION_LOGIN_TIME] ?? $now);

        if ($idle > self::IDLE_TIMEOUT || $age > self::ABSOLUTE_TIMEOUT) {
            // Session expiry only: leaves any remember-me token untouched so
            // resumeFromRememberCookie() can transparently sign back in.
            $this->clearSession();
            return;
        }

        $_SESSION[self::SESSION_LAST_ACTIVITY] = $now;
    }

    private function clearSession(): void
    {
        unset(
            $_SESSION[self::SESSION_USER],
            $_SESSION[self::SESSION_LOGIN_TIME],
            $_SESSION[self::SESSION_LAST_ACTIVITY]
        );
        $this->regenerateId();
    }

    private function regenerateId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
