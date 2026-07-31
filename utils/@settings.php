<?php

declare(strict_types=1);

use Mk\Framework\Config;
use Mk\Framework\Pager;

// Database: credentials come from the environment (.env / .env.example)
define('DATABASE_NAME', Config::get('DB_NAME', 'framework'));
define('DATABASE_HOST', Config::get('DB_HOST', 'localhost'));
define('DATABASE_PORT', Config::get('DB_PORT'));
define('DATABASE_DRIVER_DIBI', Config::get('DB_DRIVER', 'mysqli'));
define('DATABASE_USERNAME', Config::get('DB_USER', 'root'));
define('DATABASE_PASSWORD', Config::get('DB_PASS', ''));

$secondsInMonth = 30 * 24 * 60 * 60;

// SESSION, COOKIES
// Harden the session cookie. `secure` follows the actual connection so local
// HTTP development still works, while production over HTTPS gets the flag.
// (Made env-driven in a later phase; see docs/ROADMAP.md.)
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Own cookie name so Jellydash never fights other PHP apps (or a second
// Jellydash instance) on the same host over the default PHPSESSID cookie.
// Cookies ignore ports, so two instances on one IP would clobber each other's
// session, which surfaces as failing CSRF checks.
session_name('jellydash_session');

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => $secondsInMonth,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Session lifecycle for logged-in users (id regeneration on login, idle/absolute
// timeouts) is handled by Authorization.

// TIMEZONE SETTINGS
date_default_timezone_set(\Mk\Framework\Config::get('APP_TIMEZONE', TIMEZONE_DEFAULT) ?? TIMEZONE_DEFAULT);

// FEATURE MODULES: discover manifests and register their autoloaders.
\Mk\Framework\Modules::boot();

// PAGES AND CATEGORIES
$page = Pager::getPage();
$category = Pager::getCategory();

// GLOBAL constants
define('PAGE', $page);
define('CATEGORY', $category);
