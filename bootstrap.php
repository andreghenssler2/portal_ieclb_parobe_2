<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/mod/db/Database.php';
require_once __DIR__ . '/mod/auth/Session.php';
require_once __DIR__ . '/mod/security/Csrf.php';
require_once __DIR__ . '/mod/auth/Auth.php';
require_once __DIR__ . '/app/Helpers/functions.php';
require_once __DIR__ . '/app/Services/MediaService.php';
require_once __DIR__ . '/app/Services/RevisionService.php';

Session::start();

// A partir da v0.12.0 o fuso horário pode ser ajustado pelo painel.
// Em instalações ainda não migradas, permanece o TIMEZONE do config.php.
try {
    $bootstrapPdo = Database::connection();
    $portalTimezone = siteConfig($bootstrapPdo, 'site_timezone', defined('TIMEZONE') ? (string)TIMEZONE : 'America/Sao_Paulo');
    if (in_array($portalTimezone, DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($portalTimezone);
    }
} catch (Throwable $e) {
    // Mantém o portal funcionando durante instalação/migração.
}
