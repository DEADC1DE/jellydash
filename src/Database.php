<?php

declare(strict_types=1);

namespace Mk\Framework;

class Database
{
    private \Dibi\Connection $dibi;

    public function __construct()
    {
        $config = [
            'driver' => DATABASE_DRIVER_DIBI,
            'host' => DATABASE_HOST,
            'username' => DATABASE_USERNAME,
            'password' => DATABASE_PASSWORD,
            'database' => DATABASE_NAME,
        ];

        if (defined('DATABASE_PORT') && DATABASE_PORT !== null && DATABASE_PORT !== '') {
            $config['port'] = (int) DATABASE_PORT;
        }

        // Throws \Dibi\Exception on failure; handled centrally by ErrorHandler,
        // or by the caller where a graceful fallback exists (e.g. the login flow).
        $this->dibi = new \Dibi\Connection($config);
    }

    // Return whole Dibi instance
    public function getDibi(): \Dibi\Connection
    {
        return $this->dibi;
    }

    // Minimum length enforced when creating a user.
    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * Create the auth tables when they don't exist yet. Fresh installs rely on
     * auto-created schema (no SQL import), so user management must be able to
     * bootstrap its own tables. Called from the console user commands.
     */
    public function ensureAuthSchema(): void
    {
        $this->dibi->query(
            'CREATE TABLE IF NOT EXISTS `users` (
                `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `username` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `name` varchar(100) NOT NULL,
                `role` tinyint(4) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->dibi->query(
            'CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `identifier` varchar(190) NOT NULL,
                `attempts` int NOT NULL DEFAULT 0,
                `locked_until` datetime DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_identifier` (`identifier`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /* CREATE NEW USER IN THE 'users' TABLE,
    ROLES: 1-owner, 2-admin, 3-regular, 4-guest */
    public function addAuthUser($username, $password, $name, $role): int
    {
        if (strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        $dibi_data = [
            'username' => strtolower($username),
            'password' => password_hash((string) $password, PASSWORD_DEFAULT),
            'name' => ucfirst($name),
            'role' => intval($role),
        ];

        // Returns the new row id; throws \Dibi\Exception on failure.
        return (int) $this->dibi->insert('users', $dibi_data)->execute(\dibi::IDENTIFIER);
    }

    // Set (reset) a user's password. Returns false if no such user.
    public function setUserPassword(string $username, string $password): bool
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        $this->dibi->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)])
            ->where('username = %s', strtolower($username))->execute();

        return $this->dibi->getAffectedRows() > 0;
    }

    public function getUser($id): ?array
    {
        $row = $this->dibi->select('id, username, name, role')
            ->from('users')->where('id = %i', $id)->limit(1)->fetch();

        return $row?->toArray();
    }
}
