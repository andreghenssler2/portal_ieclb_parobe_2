<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/mod/db/Database.php';
require_once __DIR__ . '/mod/auth/Session.php';
require_once __DIR__ . '/mod/security/Csrf.php';
require_once __DIR__ . '/mod/auth/Auth.php';
require_once __DIR__ . '/app/Helpers/functions.php';

Session::start();
