<?php
/**
 * InfinityFree production settings.
 * >>> Put your MySQL password in 'pass' below before uploading. <<<
 */
if (!defined('EDUTRACK_DB_CONFIG')) {
    define('EDUTRACK_DB_CONFIG', [
        'host' => 'sql204.infinityfree.com',
        'port' => '3306',
        'name' => 'if0_43015886_if0_43015886_edutrack',
        'user' => 'if0_43015886',
        'pass' => '7FJ5luTW4I9nbIJ',
    ]);
}

if (!defined('DB_HOST')) define('DB_HOST', EDUTRACK_DB_CONFIG['host']);
if (!defined('DB_PORT')) define('DB_PORT', EDUTRACK_DB_CONFIG['port']);
if (!defined('DB_NAME')) define('DB_NAME', EDUTRACK_DB_CONFIG['name']);
if (!defined('DB_USER')) define('DB_USER', EDUTRACK_DB_CONFIG['user']);
if (!defined('DB_PASS')) define('DB_PASS', EDUTRACK_DB_CONFIG['pass']);
