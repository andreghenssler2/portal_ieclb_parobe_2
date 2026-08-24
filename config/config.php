<?php

declare(strict_types=1);

define('APP_NAME', 'Portal IECLB Parobé');
define('APP_VERSION', '0.45.0');
define('APP_ENV', 'production');
define('APP_DEBUG', false);
// define('BASE_URL', 'http://localhost/portal_ieclb_parobe');
define('BASE_URL', 'https://ieclbparobe.com.br');
define('TIMEZONE', 'America/Sao_Paulo');
define('UPLOAD_MAX_SIZE', 300 * 1024 * 1024);

define('DB_HOST', '108.167.151.39');
define('DB_PORT', '3306');
define('DB_NAME', 'ieclbp28_portal_ieclb'); # 
define('DB_USER', 'ieclbp28_root_portal');
define('DB_PASS', 'portal_ieclb!@3');
define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set(TIMEZONE);
