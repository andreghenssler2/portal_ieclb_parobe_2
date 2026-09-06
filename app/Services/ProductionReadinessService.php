<?php

declare(strict_types=1);

/**
 * Central de pré-produção v0.99.0.
 *
 * Agrega verificações já existentes no Portal e adiciona checagens de ambiente.
 * Não altera configuração, banco, DNS, cache ou arquivos do site.
 */
final class ProductionReadinessService
{
    /**
     * @return array<string,mixed>
     */
    public static function report(
        PDO $pdo,
        string $rootPath
    ): array {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        $sections = [];
        $blockers = [];
        $warnings = [];
        $passed = 0;
        $checks = 0;

        $add = static function (
            string $section,
            string $label,
            string $status,
            string $detail
        ) use (
            &$sections,
            &$blockers,
            &$warnings,
            &$passed,
            &$checks
        ): void {
            $checks++;

            $item = [
                'label' => $label,
                'status' => $status,
                'detail' => $detail,
            ];

            $sections[$section][] = $item;

            if ($status === 'ok') {
                $passed++;
            } elseif ($status === 'error') {
                $blockers[] = $label . ': ' . $detail;
            } else {
                $warnings[] = $label . ': ' . $detail;
            }
        };

        /*
        |--------------------------------------------------------------------------
        | Aplicação e ambiente
        |--------------------------------------------------------------------------
        */

        $version =
            defined('APP_VERSION')
                ? (string)APP_VERSION
                : '';

        $add(
            'Aplicação',
            'Versão do Portal',
            version_compare($version, '0.99.0', '>=') ? 'ok' : 'error',
            $version !== '' ? $version : 'APP_VERSION não definida.'
        );

        $phpOk =
            version_compare(
                PHP_VERSION,
                '8.2.0',
                '>='
            );

        $add(
            'Aplicação',
            'PHP >= 8.2',
            $phpOk ? 'ok' : 'error',
            PHP_VERSION
        );

        $env =
            defined('APP_ENV')
                ? strtolower(
                    trim(
                        (string)APP_ENV
                    )
                )
                : '';

        $add(
            'Produção',
            'APP_ENV',
            $env === 'production' ? 'ok' : 'warning',
            $env !== ''
                ? $env
                : 'APP_ENV não definida.'
        );

        $debug =
            defined('APP_DEBUG')
                ? (bool)APP_DEBUG
                : false;

        $add(
            'Produção',
            'APP_DEBUG',
            !$debug ? 'ok' : 'warning',
            $debug
                ? 'Ativo. Para produção, use false.'
                : 'Desativado.'
        );

        $baseUrl =
            defined('BASE_URL')
                ? trim(
                    (string)BASE_URL
                )
                : '';

        $isHttps =
            str_starts_with(
                strtolower($baseUrl),
                'https://'
            );

        $localBase =
            $baseUrl === ''
            || preg_match(
                '#(?:localhost|127\.0\.0\.1|/portal_ieclb)#i',
                $baseUrl
            );

        $add(
            'Produção',
            'BASE_URL pública',
            (
                $baseUrl !== ''
                && !$localBase
            )
                ? 'ok'
                : 'warning',
            $baseUrl !== ''
                ? $baseUrl
                : 'BASE_URL não definida.'
        );

        $add(
            'Produção',
            'HTTPS',
            $isHttps ? 'ok' : 'warning',
            $isHttps
                ? 'BASE_URL usa HTTPS.'
                : 'A BASE_URL não usa HTTPS.'
        );

        $timezone =
            defined('TIMEZONE')
                ? (string)TIMEZONE
                : date_default_timezone_get();

        $add(
            'Aplicação',
            'Fuso horário',
            $timezone !== '' ? 'ok' : 'warning',
            $timezone !== '' ? $timezone : 'Não identificado.'
        );

        /*
        |--------------------------------------------------------------------------
        | Arquivos e escrita
        |--------------------------------------------------------------------------
        */

        $storage =
            self::path(
                $rootPath,
                'storage'
            );

        $add(
            'Arquivos',
            'storage gravável',
            (
                is_dir($storage)
                && is_writable($storage)
            )
                ? 'ok'
                : 'error',
            is_dir($storage)
                ? (
                    is_writable($storage)
                        ? $storage
                        : 'Pasta existe, mas não possui escrita.'
                )
                : 'Pasta storage ausente.'
        );

        $configFile =
            self::path(
                $rootPath,
                'config/config.php'
            );

        $add(
            'Arquivos',
            'config/config.php',
            is_file($configFile) ? 'ok' : 'error',
            is_file($configFile)
                ? 'Arquivo presente.'
                : 'Arquivo ausente.'
        );

        $htaccess =
            self::path(
                $rootPath,
                '.htaccess'
            );

        if (is_file($htaccess)) {
            $ht =
                file_get_contents(
                    $htaccess
                )
                ?: '';

            $hasUpdaterProtection =
                stripos(
                    $ht,
                    'atualizar'
                ) !== false
                || stripos(
                    $ht,
                    'diagnosticar'
                ) !== false;

            $add(
                'Segurança',
                'Proteção de scripts de manutenção',
                $hasUpdaterProtection ? 'ok' : 'warning',
                $hasUpdaterProtection
                    ? '.htaccess contém regra para scripts de manutenção.'
                    : 'Não foi possível confirmar regra para atualizar/diagnosticar no .htaccess.'
            );
        } else {
            $add(
                'Segurança',
                '.htaccess',
                'warning',
                'Arquivo não encontrado na raiz.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Extensões PHP úteis
        |--------------------------------------------------------------------------
        */

        foreach (
            [
                'pdo_mysql' => 'PDO MySQL',
                'mbstring' => 'mbstring',
                'json' => 'JSON',
            ]
            as $extension => $label
        ) {
            $add(
                'PHP',
                $label,
                extension_loaded($extension) ? 'ok' : 'error',
                extension_loaded($extension)
                    ? 'Disponível.'
                    : "Extensão {$extension} ausente."
            );
        }

        foreach (
            [
                'openssl' => 'OpenSSL',
                'curl' => 'cURL',
                'zip' => 'ZipArchive',
            ]
            as $extension => $label
        ) {
            $loaded =
                $extension === 'zip'
                    ? class_exists('ZipArchive')
                    : extension_loaded($extension);

            $add(
                'PHP',
                $label,
                $loaded ? 'ok' : 'warning',
                $loaded
                    ? 'Disponível.'
                    : "Extensão {$extension} indisponível."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Permissões
        |--------------------------------------------------------------------------
        */

        if (class_exists('PermissionAuditService')) {
            try {
                $permission =
                    PermissionAuditService::report(
                        $pdo,
                        $rootPath
                    );

                $permissionErrors =
                    count(
                        (array)(
                            $permission['errors']
                            ?? []
                        )
                    );

                $permissionWarnings =
                    count(
                        (array)(
                            $permission['warnings']
                            ?? []
                        )
                    );

                $add(
                    'Perfis e permissões',
                    'Integridade',
                    $permissionErrors === 0 ? 'ok' : 'error',
                    $permissionErrors === 0
                        ? 'Sem erros de integridade.'
                        : "{$permissionErrors} erro(s) encontrados."
                );

                if ($permissionWarnings > 0) {
                    $add(
                        'Perfis e permissões',
                        'Avisos da auditoria',
                        'warning',
                        "{$permissionWarnings} aviso(s) para revisão."
                    );
                } else {
                    $add(
                        'Perfis e permissões',
                        'Avisos da auditoria',
                        'ok',
                        'Nenhum aviso.'
                    );
                }
            } catch (Throwable $e) {
                $add(
                    'Perfis e permissões',
                    'Auditoria',
                    'error',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'Perfis e permissões',
                'Auditoria',
                'warning',
                'PermissionAuditService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Backups
        |--------------------------------------------------------------------------
        */

        if (class_exists('BackupRestoreTestService')) {
            try {
                $backup =
                    new BackupRestoreTestService(
                        $pdo,
                        $rootPath
                    );

                $backupQuick =
                    $backup->quickCheck();

                $backupIssues =
                    count(
                        (array)(
                            $backupQuick['issues']
                            ?? []
                        )
                    );

                $add(
                    'Backups',
                    'Estrutura de backup/restauração',
                    $backupIssues === 0 ? 'ok' : 'error',
                    $backupIssues === 0
                        ? 'Serviços e pasta de backup disponíveis.'
                        : "{$backupIssues} problema(s) encontrados."
                );

                $dbBackups =
                    (int)(
                        $backupQuick['database_backups']
                        ?? 0
                    );

                $fullBackups =
                    (int)(
                        $backupQuick['full_backups']
                        ?? 0
                    );

                $add(
                    'Backups',
                    'Backups existentes',
                    (
                        $dbBackups > 0
                        || $fullBackups > 0
                    )
                        ? 'ok'
                        : 'warning',
                    "Banco: {$dbBackups}; completos: {$fullBackups}."
                );
            } catch (Throwable $e) {
                $add(
                    'Backups',
                    'Estrutura de backup/restauração',
                    'error',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'Backups',
                'Teste de restaurabilidade',
                'warning',
                'BackupRestoreTestService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Cron
        |--------------------------------------------------------------------------
        */

        if (class_exists('CronHealthService')) {
            try {
                $cron =
                    CronHealthService::status(
                        $pdo,
                        $rootPath
                    );

                $cronIssues =
                    count(
                        (array)(
                            $cron['issues']
                            ?? []
                        )
                    );

                $heartbeatState =
                    (string)(
                        $cron['heartbeat']['state']
                        ?? 'never'
                    );

                $add(
                    'Cron',
                    'Estrutura do agendador',
                    $cronIssues === 0 ? 'ok' : 'error',
                    $cronIssues === 0
                        ? 'Estrutura disponível.'
                        : "{$cronIssues} problema(s) estruturais."
                );

                $add(
                    'Cron',
                    'Heartbeat',
                    $heartbeatState === 'healthy'
                        ? 'ok'
                        : 'warning',
                    (string)(
                        $cron['heartbeat']['label']
                        ?? 'Não identificado'
                    )
                );

                $cronErrors =
                    (int)(
                        $cron['tasks']['consecutive_errors']
                        ?? 0
                    )
                    + (int)(
                        $cron['history']['stale_running']
                        ?? 0
                    );

                $add(
                    'Cron',
                    'Fila sem erros operacionais',
                    $cronErrors === 0 ? 'ok' : 'warning',
                    $cronErrors === 0
                        ? 'Sem erro consecutivo ou execução órfã.'
                        : "{$cronErrors} ocorrência(s) para revisão."
                );
            } catch (Throwable $e) {
                $add(
                    'Cron',
                    'Saúde do cron',
                    'warning',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'Cron',
                'Saúde do cron',
                'warning',
                'CronHealthService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | E-mail
        |--------------------------------------------------------------------------
        */

        if (class_exists('MailDnsHealthService')) {
            try {
                $mail =
                    MailDnsHealthService::report(
                        $pdo
                    );

                $domain =
                    (string)(
                        $mail['domain']
                        ?? ''
                    );

                $add(
                    'E-mail',
                    'Domínio remetente',
                    $domain !== '' ? 'ok' : 'warning',
                    $domain !== ''
                        ? $domain
                        : 'Domínio não identificado.'
                );

                $score =
                    (int)(
                        $mail['score']
                        ?? 0
                    );

                $maxScore =
                    max(
                        1,
                        (int)(
                            $mail['max_score']
                            ?? 4
                        )
                    );

                $add(
                    'E-mail',
                    'SPF/DKIM/DMARC/MX',
                    $score >= $maxScore ? 'ok' : 'warning',
                    "Pontuação técnica: {$score}/{$maxScore}."
                );
            } catch (Throwable $e) {
                $add(
                    'E-mail',
                    'Diagnóstico DNS',
                    'warning',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'E-mail',
                'Diagnóstico DNS',
                'warning',
                'MailDnsHealthService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Desempenho
        |--------------------------------------------------------------------------
        */

        if (class_exists('PerformanceHealthService')) {
            try {
                $performance =
                    PerformanceHealthService::report(
                        $pdo
                    );

                $performanceErrors =
                    count(
                        (array)(
                            $performance['errors']
                            ?? []
                        )
                    );

                $add(
                    'Desempenho',
                    'Estrutura de cache',
                    $performanceErrors === 0 ? 'ok' : 'error',
                    $performanceErrors === 0
                        ? 'Sem erro estrutural.'
                        : "{$performanceErrors} erro(s)."
                );

                $add(
                    'Desempenho',
                    'Cache de dados',
                    !empty(
                        $performance['cache']['enabled']
                    )
                        ? 'ok'
                        : 'warning',
                    !empty(
                        $performance['cache']['enabled']
                    )
                        ? 'Ativo.'
                        : 'Desativado.'
                );

                $add(
                    'Desempenho',
                    'OPcache',
                    !empty(
                        $performance['php']['opcache_enabled']
                    )
                        ? 'ok'
                        : 'warning',
                    !empty(
                        $performance['php']['opcache_enabled']
                    )
                        ? 'Ativo.'
                        : 'Inativo.'
                );
            } catch (Throwable $e) {
                $add(
                    'Desempenho',
                    'Diagnóstico',
                    'warning',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'Desempenho',
                'Diagnóstico',
                'warning',
                'PerformanceHealthService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Acessibilidade
        |--------------------------------------------------------------------------
        */

        if (class_exists('AccessibilityAuditService')) {
            try {
                $access =
                    AccessibilityAuditService::report(
                        $rootPath
                    );

                $accessErrors =
                    count(
                        (array)(
                            $access['errors']
                            ?? []
                        )
                    );

                $accessWarnings =
                    count(
                        (array)(
                            $access['warnings']
                            ?? []
                        )
                    );

                $add(
                    'Acessibilidade',
                    'Estrutura',
                    $accessErrors === 0 ? 'ok' : 'error',
                    $accessErrors === 0
                        ? 'Estrutura aprovada.'
                        : "{$accessErrors} erro(s)."
                );

                $add(
                    'Acessibilidade',
                    'Revisão editorial',
                    $accessWarnings === 0 ? 'ok' : 'warning',
                    $accessWarnings === 0
                        ? 'Sem avisos automáticos.'
                        : "{$accessWarnings} aviso(s) para revisão manual."
                );
            } catch (Throwable $e) {
                $add(
                    'Acessibilidade',
                    'Auditoria',
                    'warning',
                    $e->getMessage()
                );
            }
        } else {
            $add(
                'Acessibilidade',
                'Auditoria',
                'warning',
                'AccessibilityAuditService não disponível.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Estado final
        |--------------------------------------------------------------------------
        */

        $state =
            $blockers
                ? 'blocked'
                : (
                    $warnings
                        ? 'attention'
                        : 'ready'
                );

        $score =
            $checks > 0
                ? (int)round(
                    ($passed / $checks) * 100
                )
                : 0;

        return [
            'state' => $state,
            'score' => $score,
            'checks' => $checks,
            'passed' => $passed,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'sections' => $sections,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function path(
        string $rootPath,
        string $relative
    ): string {
        return
            $rootPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            );
    }
}
