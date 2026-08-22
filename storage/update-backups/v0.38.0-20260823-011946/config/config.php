<?php

declare(strict_types=1);

// Copie este arquivo para config/config.php e ajuste os dados do ambiente.

define('APP_NAME', 'Portal IECLB Parobé');
define('APP_VERSION', '0.37.0');
define('APP_ENV', 'development');
define('APP_DEBUG', true);
define('BASE_URL', 'http://localhost/portal_ieclb_parobe');
// define('BASE_URL', 'https://ieclbparobe.com.br/portal_ieclb_parobe');
define('TIMEZONE', 'America/Sao_Paulo');
define('UPLOAD_MAX_SIZE', 35 * 1024 * 1024); // 35 MB por arquivo

// define('DB_HOST', 'localhost');
// define('DB_PORT', '3306');
// define('DB_NAME', 'portal_ieclb');
// define('DB_USER', 'root');
// define('DB_PASS', '');
// define('DB_CHARSET', 'utf8mb4');

# root_portal
# portal_ieclb!@3

define('DB_HOST', '108.167.151.39');
define('DB_PORT', '3306');
define('DB_NAME', 'ieclbp28_portal_ieclb'); # 
define('DB_USER', 'ieclbp28_root_portal');
define('DB_PASS', 'portal_ieclb!@3');
define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set(TIMEZONE);
