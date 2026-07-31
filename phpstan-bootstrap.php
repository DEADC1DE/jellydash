<?php

/**
 * PHPStan bootstrap.
 *
 * Defines the global constants the framework relies on so static analysis can
 * resolve them. Reuses the real constants file, then adds the values that are
 * normally defined at runtime (in utils/@settings.php) which PHPStan cannot see.
 */

define('ROOT_DIR', __DIR__);

require_once ROOT_DIR . '/utils/@constants.php';

// Runtime constants defined in utils/@settings.php (DB config + resolved page/category)
define('DATABASE_NAME', 'framework');
define('DATABASE_HOST', 'localhost');
define('DATABASE_DRIVER_DIBI', 'mysqli');
define('DATABASE_USERNAME', 'root');
define('DATABASE_PASSWORD', '');
define('PAGE', null);
define('CATEGORY', null);
