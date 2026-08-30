<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$composerAutoload = __DIR__ . '/lib/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once __DIR__ . '/mod/db/Database.php';
require_once __DIR__ . '/mod/auth/Session.php';
require_once __DIR__ . '/mod/security/Csrf.php';
require_once __DIR__ . '/mod/auth/Auth.php';
require_once __DIR__ . '/app/Helpers/functions.php';
require_once __DIR__ . '/app/Services/CacheService.php';
require_once __DIR__ . '/app/Services/MediaService.php';
require_once __DIR__ . '/app/Services/ImageOptimizationService.php';
require_once __DIR__ . '/app/Services/MediaIntegrityService.php';
require_once __DIR__ . '/app/Services/MediaIntegrityReportService.php';
require_once __DIR__ . '/app/Services/MediaUsageService.php';
require_once __DIR__ . '/app/Services/CategoryService.php';
require_once __DIR__ . '/app/Services/PageHierarchyService.php';
require_once __DIR__ . '/app/Services/MenuHierarchyService.php';
require_once __DIR__ . '/app/Services/ContentBlockService.php';
require_once __DIR__ . '/app/Services/DynamicContentBlockService.php';
require_once __DIR__ . '/app/Services/ContentPatternService.php';
require_once __DIR__ . '/app/Services/EditorialBulkService.php';
require_once __DIR__ . '/app/Services/RevisionService.php';
require_once __DIR__ . '/app/Services/EditorialWorkflowService.php';
require_once __DIR__ . '/app/Services/AdminPendingService.php';
require_once __DIR__ . '/app/Services/MailService.php';
require_once __DIR__ . '/app/Services/TwoFactorService.php';
require_once __DIR__ . '/app/Services/FormNotificationService.php';
require_once __DIR__ . '/app/Services/SearchService.php';
require_once __DIR__ . '/app/Services/EventCalendarService.php';
require_once __DIR__ . '/app/Services/NewsAnalyticsService.php';
require_once __DIR__ . '/app/Services/NewsEngagementService.php';
require_once __DIR__ . '/app/Services/NewsletterService.php';
require_once __DIR__ . '/app/Services/WordPressImportService.php';
require_once __DIR__ . '/app/Services/HomeService.php';
require_once __DIR__ . '/app/Services/DocumentService.php';
require_once __DIR__ . '/app/Services/LeadershipService.php';
require_once __DIR__ . '/app/Services/SchedulerService.php';

Session::start();

// A partir da v0.12.0 o fuso horário pode ser ajustado pelo painel.
// Em instalações ainda não migradas, permanece o TIMEZONE do config.php.
try {
    $bootstrapPdo = Database::connection();
    CacheService::configure($bootstrapPdo);
    $portalTimezone = siteConfig($bootstrapPdo, 'site_timezone', defined('TIMEZONE') ? (string)TIMEZONE : 'America/Sao_Paulo');
    if (in_array($portalTimezone, DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($portalTimezone);
    }

    // v0.20.0: expiração por inatividade e limpeza periódica dos registros de auditoria.
    if (Auth::check()) {
        $sessionTimeout = (int)siteConfig($bootstrapPdo, 'security_session_timeout_minutes', '60');
        Session::enforceIdleTimeout($sessionTimeout);

        if (Auth::check()) {
            $retentionDays = (int)siteConfig($bootstrapPdo, 'security_audit_retention_days', '180');
            cleanupAuditLogs($bootstrapPdo, $retentionDays);
        }
    }

    // v0.21.0: bloqueia apenas a área pública quando o modo manutenção estiver ativo.
    enforceMaintenanceMode($bootstrapPdo);

    // v0.31.0: cache seguro apenas para a Home pública e visitantes anônimos.
    CacheService::bootstrapPublicPageCache($bootstrapPdo);
} catch (Throwable $e) {
    // Mantém o portal funcionando durante instalação/migração.
}
