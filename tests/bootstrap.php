<?php

declare(strict_types=1);

use Mk\Framework\Config;

/**
 * PHPUnit bootstrap.
 *
 * Define ROOT_DIR, load Composer (PSR-4 autoloads the Mk\Framework classes),
 * load the path constants, and resolve the DB constants from .env so
 * database-backed tests can connect (they skip themselves if the DB is down).
 */

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/vendor/autoload.php';
require_once ROOT_DIR . '/utils/@constants.php';

Dotenv\Dotenv::createImmutable(ROOT_DIR)->safeLoad();

define('DATABASE_NAME', Config::get('DB_NAME', 'framework'));
define('DATABASE_HOST', Config::get('DB_HOST', 'localhost'));
define('DATABASE_PORT', Config::get('DB_PORT'));
define('DATABASE_DRIVER_DIBI', Config::get('DB_DRIVER', 'mysqli'));
define('DATABASE_USERNAME', Config::get('DB_USER', 'root'));
define('DATABASE_PASSWORD', Config::get('DB_PASS', ''));

if (!defined('PAGE')) {
    define('PAGE', null);
}

if (!defined('CATEGORY')) {
    define('CATEGORY', null);
}
