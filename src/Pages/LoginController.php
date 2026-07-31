<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Container;
use Mk\Framework\Controller;

final class LoginController extends Controller
{
    private ?string $errorMessage = null;

    public function withError(string $message): self
    {
        $this->errorMessage = $message;
        return $this;
    }

    public function handle(): void
    {
        $this->render('login', [
            'layout' => $this->layout(['title' => 'Login']),
            'error_msg' => $this->errorMessage,
            'no_users' => $this->noUsersExist(),
        ]);
    }

    /**
     * True when auth is on but no account exists yet, which usually means the
     * admin seed failed (short password, typo in the env). Surfacing that on
     * the login page beats a generic "wrong password" that can never succeed.
     * Safe to reveal: with zero accounts there is nothing to break into.
     */
    private function noUsersExist(): bool
    {
        try {
            return (int) Container::db()->getDibi()
                ->select('COUNT(*)')->from('users')->fetchSingle() === 0;
        } catch (\Throwable) {
            // Table missing entirely counts as "no users" too.
            return true;
        }
    }
}
