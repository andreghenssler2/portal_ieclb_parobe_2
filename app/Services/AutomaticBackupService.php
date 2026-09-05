<?php

declare(strict_types=1);

/**
 * Backups automáticos da v0.89.
 *
 * Reutiliza os serviços de backup já existentes no Portal e apenas os
 * conecta ao Scheduler.
 */
final class AutomaticBackupService
{
    /**
     * @return array{status:string,message:string}
     */
    public static function runDatabase(
        PDO $pdo,
        string $rootPath
    ): array {
        self::loadDependencies(
            $rootPath
        );

        $service =
            new BackupService(
                $pdo,
                $rootPath
            );

        $retention =
            max(
                1,
                min(
                    100,
                    (int)siteConfig(
                        $pdo,
                        'backup_retention_count',
                        '10'
                    )
                )
            );

        $info =
            $service->createDatabaseBackup(
                'automatico'
            );

        $removed =
            $service->pruneDatabaseBackups(
                $retention
            );

        return [
            'status' =>
                'ok',
            'message' =>
                'Backup automático do banco criado: '
                . (string)$info['name']
                . ' ('
                . formatBytes(
                    (int)$info['size']
                )
                . '). Retenção: '
                . $retention
                . '. Removidos: '
                . $removed
                . '.',
        ];
    }

    /**
     * @return array{status:string,message:string}
     */
    public static function runFull(
        PDO $pdo,
        string $rootPath
    ): array {
        self::loadDependencies(
            $rootPath
        );

        $service =
            new FullBackupService(
                $pdo,
                $rootPath
            );

        if (
            !$service->isSupported()
        ) {
            return [
                'status' =>
                    'ignorado',
                'message' =>
                    'Backup completo automático ignorado: '
                    . 'ZipArchive não está disponível.',
            ];
        }

        $retention =
            max(
                1,
                min(
                    50,
                    (int)siteConfig(
                        $pdo,
                        'backup_full_retention_count',
                        '5'
                    )
                )
            );

        $includeUploads =
            siteConfig(
                $pdo,
                'backup_full_include_uploads',
                '1'
            ) === '1';

        $includeThemes =
            siteConfig(
                $pdo,
                'backup_full_include_themes',
                '1'
            ) === '1';

        $info =
            $service->createFullBackup(
                'automatico',
                $includeUploads,
                $includeThemes
            );

        $removed =
            $service->pruneFullBackups(
                $retention
            );

        return [
            'status' =>
                'ok',
            'message' =>
                'Backup completo automático criado: '
                . (string)$info['name']
                . ' ('
                . formatBytes(
                    (int)$info['size']
                )
                . '). Retenção: '
                . $retention
                . '. Removidos: '
                . $removed
                . '.',
        ];
    }

    /**
     * Resumo para Ferramentas > Backups.
     *
     * @return array{
     *   database:array<string,mixed>,
     *   full:array<string,mixed>,
     *   zip_supported:bool
     * }
     */
    public static function status(
        PDO $pdo,
        string $rootPath
    ): array {
        self::loadDependencies(
            $rootPath
        );

        $database =
            new BackupService(
                $pdo,
                $rootPath
            );

        $full =
            new FullBackupService(
                $pdo,
                $rootPath
            );

        $dbLatest =
            self::latestAutomatic(
                $database->listDatabaseBackups()
            );

        $fullLatest =
            self::latestAutomatic(
                $full->listFullBackups()
            );

        return [
            'database' =>
                array_merge(
                    self::taskStatus(
                        $pdo,
                        'backup_banco_automatico'
                    ),
                    [
                        'latest_file' =>
                            $dbLatest,
                    ]
                ),

            'full' =>
                array_merge(
                    self::taskStatus(
                        $pdo,
                        'backup_completo_automatico'
                    ),
                    [
                        'latest_file' =>
                            $fullLatest,
                    ]
                ),

            'zip_supported' =>
                $full->isSupported(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function latestAutomatic(
        array $items
    ): ?array {
        foreach ($items as $item) {
            if (
                str_contains(
                    (string)(
                        $item['name']
                        ?? ''
                    ),
                    '-automatico-'
                )
            ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function taskStatus(
        PDO $pdo,
        string $slug
    ): array {
        $result = [
            'registered' =>
                false,
            'active' =>
                false,
            'interval' =>
                null,
            'next_run' =>
                null,
            'last_status' =>
                null,
            'last_message' =>
                null,
            'last_finished' =>
                null,
        ];

        try {
            $stmt =
                $pdo->prepare(
                    "SELECT *
                     FROM tarefas_agendadas
                     WHERE slug=:slug
                     LIMIT 1"
                );

            $stmt->execute([
                'slug' =>
                    $slug,
            ]);

            $task =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($task) {
                $result['registered'] =
                    true;

                $result['active'] =
                    (int)(
                        $task['ativa']
                        ?? 0
                    ) === 1;

                $result['interval'] =
                    isset(
                        $task['intervalo_minutos']
                    )
                        ? (int)$task['intervalo_minutos']
                        : null;

                $result['next_run'] =
                    !empty(
                        $task['proxima_execucao_em']
                    )
                        ? (string)$task['proxima_execucao_em']
                        : null;
            }
        } catch (Throwable $ignored) {
        }

        try {
            $stmt =
                $pdo->prepare(
                    "SELECT *
                     FROM tarefas_execucoes
                     WHERE tarefa_slug=:slug
                     ORDER BY id DESC
                     LIMIT 1"
                );

            $stmt->execute([
                'slug' =>
                    $slug,
            ]);

            $last =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($last) {
                $result['last_status'] =
                    !empty(
                        $last['status']
                    )
                        ? (string)$last['status']
                        : null;

                $result['last_message'] =
                    !empty(
                        $last['mensagem']
                    )
                        ? (string)$last['mensagem']
                        : null;

                $result['last_finished'] =
                    !empty(
                        $last['finalizada_em']
                    )
                        ? (string)$last['finalizada_em']
                        : (
                            !empty(
                                $last['iniciada_em']
                            )
                                ? (string)$last['iniciada_em']
                                : null
                        );
            }
        } catch (Throwable $ignored) {
        }

        return $result;
    }

    private static function loadDependencies(
        string $rootPath
    ): void {
        $rootPath =
            rtrim(
                $rootPath,
                DIRECTORY_SEPARATOR
            );

        $backupFile =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'app'
            . DIRECTORY_SEPARATOR
            . 'Services'
            . DIRECTORY_SEPARATOR
            . 'BackupService.php';

        $fullFile =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'app'
            . DIRECTORY_SEPARATOR
            . 'Services'
            . DIRECTORY_SEPARATOR
            . 'FullBackupService.php';

        if (
            !class_exists(
                'BackupService'
            )
            && is_file(
                $backupFile
            )
        ) {
            require_once $backupFile;
        }

        if (
            !class_exists(
                'FullBackupService'
            )
            && is_file(
                $fullFile
            )
        ) {
            require_once $fullFile;
        }

        if (
            !class_exists(
                'BackupService'
            )
            || !class_exists(
                'FullBackupService'
            )
        ) {
            throw new RuntimeException(
                'Os serviços de backup do Portal não estão disponíveis.'
            );
        }
    }
}
