<?php

declare(strict_types=1);

/**
 * Auditoria de consolidação do Portal IECLB Parobé v1.2.0.
 *
 * Esta classe não altera conteúdo nem estrutura do banco. Ela apenas verifica
 * se a distribuição consolidada possui os componentes obrigatórios e se o
 * ambiente consegue executá-los.
 */
final class ReleaseAuditService
{
    /**
     * @return array{
     *   checks:array<int,array{id:string,label:string,status:string,detail:string}>,
     *   ok:int,
     *   warnings:int,
     *   blockers:int
     * }
     */
    public static function run(PDO $pdo, string $root): array
    {
        $root = rtrim($root, '/\\');
        $checks = [];

        self::check(
            $checks,
            'version',
            'Versão do Portal',
            defined('APP_VERSION') && (string)APP_VERSION === '1.2.0',
            defined('APP_VERSION')
                ? 'APP_VERSION=' . (string)APP_VERSION
                : 'APP_VERSION não definida',
            true
        );

        self::check(
            $checks,
            'php',
            'PHP 8.2 ou superior',
            version_compare(PHP_VERSION, '8.2.0', '>='),
            'PHP ' . PHP_VERSION,
            true
        );

        foreach (
            [
                'pdo' => true,
                'pdo_mysql' => true,
                'mbstring' => true,
                'json' => true,
                'openssl' => true,
                'fileinfo' => true,
                'session' => true,
                'zip' => false,
                'gd' => false,
            ]
            as $extension => $blocking
        ) {
            self::check(
                $checks,
                'ext-' . $extension,
                'Extensão ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension)
                    ? 'disponível'
                    : ($blocking ? 'ausente' : 'opcional/ausente'),
                $blocking
            );
        }

        $requiredFiles = [
            'bootstrap.php',
            'app/Helpers/functions.php',
            'app/Services/ContentPageCacheService.php',
            'app/Services/ContentAutosaveService.php',
            'app/Services/AdminAdvancedSearchService.php',
            'app/Services/AdminNotificationService.php',
            'app/Services/UserActivityService.php',
            'app/Services/MaintenanceExpiryService.php',
            'app/Services/TrustedEmbedService.php',
            'app/Services/NewsFeatureService.php',
            'app/Services/CommunityProfileService.php',
            'app/Services/SecurityCenterService.php',
            'app/Services/BackupIntegrityService.php',
            'app/Services/MailRetryQueueService.php',
            'app/Services/MailOperationsService.php',
            'app/Services/SeoSharingService.php',
            'admin/eventos/duplicar.php',
            'admin/seguranca.php',
            'admin/ferramentas/backup-integridade.php',
            'admin/configuracoes/email-fila.php',
            'admin/configuracoes/email-saude.php',
            'admin/seo/redirects.php',
            'social-image.php',
            'theme/ieclb/header.php',
            'sitemap.php',
        ];

        foreach ($requiredFiles as $relative) {
            $file = $root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            self::check(
                $checks,
                'file-' . md5($relative),
                'Arquivo ' . $relative,
                is_file($file),
                is_file($file) ? 'presente' : 'ausente',
                true
            );
        }

        $requiredClasses = [
            'SecurityHeadersService',
            'CacheService',
            'ContentPageCacheService',
            'ContentAutosaveService',
            'AdminAdvancedSearchService',
            'AdminNotificationService',
            'UserActivityService',
            'MaintenanceExpiryService',
            'TrustedEmbedService',
            'MediaService',
            'EventCalendarService',
            'NewsFeatureService',
            'CommunityProfileService',
            'SecurityCenterService',
            'BackupIntegrityService',
            'MailRetryQueueService',
            'MailOperationsService',
            'SeoSharingService',
            'SchedulerService',
            'ProductionReadinessService',
            'PortalHealthSnapshotService',
            'ReleaseAuditService',
        ];

        foreach ($requiredClasses as $class) {
            self::check(
                $checks,
                'class-' . strtolower($class),
                'Classe ' . $class,
                class_exists($class),
                class_exists($class) ? 'carregada' : 'não carregada',
                true
            );
        }

        $coreTables = [
            'configuracoes',
            'posts',
            'paginas',
            'eventos',
            'comunidades',
            'midias',
        ];

        foreach ($coreTables as $table) {
            $exists = self::tableExists($pdo, $table);

            self::check(
                $checks,
                'table-' . $table,
                'Tabela ' . $table,
                $exists,
                $exists ? 'presente' : 'ausente',
                true
            );
        }

        /*
         * Estruturas criadas ao longo do ciclo 0.x/1.1.x. Elas fazem parte da
         * distribuição consolidada, mas são classificadas como aviso para não
         * impedir um ambiente de desenvolvimento ainda sem dados de exemplo.
         */
        $featureTables = [
            'content_autosaves',
            'admin_notifications',
            'user_sessions',
            'post_publicacao_extras',
            'post_galeria_midias',
            'post_anexos_midias',
            'comunidade_perfis',
            'comunidade_fotos',
            'email_fila_falhas',
            'seo_redirects',
        ];

        foreach ($featureTables as $table) {
            $exists = self::tableExists($pdo, $table);

            self::check(
                $checks,
                'feature-table-' . $table,
                'Estrutura ' . $table,
                $exists,
                $exists
                    ? 'presente'
                    : 'não encontrada; execute/abra o módulo correspondente para inicializá-la',
                false
            );
        }

        $bootstrap = self::read(
            $root . DIRECTORY_SEPARATOR . 'bootstrap.php'
        );

        $legacyWrappers = [
            '$contentPageCacheServiceFile',
            '$autosaveServiceFile',
            '$adminAdvancedSearchServiceFile',
            '$adminNotificationServiceFile',
            '$userActivityServiceFile',
        ];

        $legacyFound = [];

        foreach ($legacyWrappers as $needle) {
            if (str_contains($bootstrap, $needle)) {
                $legacyFound[] = $needle;
            }
        }

        self::check(
            $checks,
            'bootstrap-consolidated',
            'Bootstrap consolidado',
            !$legacyFound
                && str_contains(
                    $bootstrap,
                    'PORTAL_CONSOLIDATED_BOOTSTRAP_V120'
                ),
            $legacyFound
                ? 'wrappers antigos ainda presentes: ' . implode(', ', $legacyFound)
                : 'serviços consolidados como dependências diretas',
            true
        );

        $markers = [
            'app/Services/MaintenanceExpiryService.php' => [
                'class MaintenanceExpiryService',
            ],
            'app/Services/TrustedEmbedService.php' => [
                'class TrustedEmbedService',
            ],
            'app/Services/NewsFeatureService.php' => [
                'class NewsFeatureService',
                'post_publicacao_extras',
            ],
            'app/Services/CommunityProfileService.php' => [
                'class CommunityProfileService',
                'comunidade_perfis',
            ],
            'app/Services/SecurityCenterService.php' => [
                'class SecurityCenterService',
            ],
            'app/Services/BackupIntegrityService.php' => [
                'class BackupIntegrityService',
            ],
            'app/Services/MailRetryQueueService.php' => [
                'class MailRetryQueueService',
                'email_fila_falhas',
            ],
            'app/Services/SeoSharingService.php' => [
                'class SeoSharingService',
                'seo_redirects',
            ],
        ];

        foreach ($markers as $relative => $needles) {
            $file = $root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $content = self::read($file);

            foreach ($needles as $needle) {
                self::check(
                    $checks,
                    'marker-' . md5($relative . '|' . $needle),
                    'Consolidação ' . $relative,
                    str_contains($content, $needle),
                    $needle,
                    true
                );
            }
        }

        foreach (
            [
                'storage',
                'storage/cache',
                'storage/update-backups',
            ]
            as $relative
        ) {
            $dir = $root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            $exists = is_dir($dir);
            $writable = $exists && is_writable($dir);

            self::check(
                $checks,
                'dir-' . md5($relative),
                'Diretório gravável ' . $relative,
                $writable,
                !$exists
                    ? 'diretório ausente'
                    : ($writable ? 'gravável' : 'sem permissão de escrita'),
                $relative === 'storage'
            );
        }

        $ok = 0;
        $warnings = 0;
        $blockers = 0;

        foreach ($checks as $check) {
            if ($check['status'] === 'ok') {
                $ok++;
            } elseif ($check['status'] === 'warning') {
                $warnings++;
            } else {
                $blockers++;
            }
        }

        return [
            'checks' => $checks,
            'ok' => $ok,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<int,array{id:string,label:string,status:string,detail:string}> $checks
     */
    private static function check(
        array &$checks,
        string $id,
        string $label,
        bool $ok,
        string $detail,
        bool $blocking
    ): void {
        $checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $ok
                ? 'ok'
                : ($blocking ? 'blocker' : 'warning'),
            'detail' => $detail,
        ];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );
            $stmt->execute([$table]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private static function read(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        $content = @file_get_contents($file);

        return is_string($content)
            ? str_replace(["\r\n", "\r"], "\n", $content)
            : '';
    }
}
