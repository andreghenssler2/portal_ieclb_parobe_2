<?php

declare(strict_types=1);

// Copie este arquivo para config/config.php e ajuste os dados do ambiente.
define('APP_NAME', 'Portal IECLB Parobé');
define('APP_VERSION', '1.1.2');
define('APP_ENV', 'development');
define('APP_DEBUG', true);
define('BASE_URL', 'http://localhost/portal_ieclb_parobe');
define('TIMEZONE', 'America/Sao_Paulo');
define('UPLOAD_MAX_SIZE', 35 * 1024 * 1024);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'portal_ieclb');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set(TIMEZONE);
