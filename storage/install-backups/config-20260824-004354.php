<?php

declare(strict_types=1);

define('APP_NAME', 'Portal IECLB Parobé');
define('APP_VERSION', '0.41.0');
define('APP_ENV', 'production');
define('APP_DEBUG', false);
define('BASE_URL', 'http://localhost/portal_ieclb_parobe');
define('TIMEZONE', 'America/Sao_Paulo');
define('UPLOAD_MAX_SIZE', 300 * 1024 * 1024);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'wp902');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set(TIMEZONE);
