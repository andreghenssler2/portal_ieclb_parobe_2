<?php

declare(strict_types=1);

/**
 * Diagnóstico operacional do Portal.
 *
 * Complementa SiteHealthService com informações úteis para produção:
 * - espaço em disco;
 * - tamanho do banco;
 * - cache;
 * - backups;
 * - tarefas agendadas;
 * - erros/alertas recentes;
 * - uploads;
 * - situação básica do SMTP.
 *
 * Não altera configurações nem executa tarefas.
 */
final class ProductionDiagnosticsService
{
    public function __construct(
        private PDO $pdo,
        private string $root
    ) {
        $this->root = rtrim(
            $this->root,
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $metrics = [
            'disk' => $this->diskMetric(),
            'database' => $this->databaseMetric(),
            'uploads' => $this->uploadsMetric(),
            'cache' => $this->cacheMetric(),
            'backup' => $this->backupMetric(),
            'scheduler' => $this->schedulerMetric(),
            'errors' => $this->errorsMetric(),
            'smtp' => $this->smtpMetric(),
        ];

        $summary = [
            'ok' => 0,
            'warn' => 0,
            'error' => 0,
            'info' => 0,
            'total' => count($metrics),
            'overall' => 'ok',
        ];

        foreach ($metrics as $metric) {
            $status = (string)($metric['status'] ?? 'info');

            if (!isset($summary[$status])) {
                $status = 'info';
            }

            $summary[$status]++;
        }

        $summary['overall'] =
            $summary['error'] > 0
                ? 'error'
                : (
                    $summary['warn'] > 0
                        ? 'warn'
                        : 'ok'
                );

        return [
            'metrics' => $metrics,
            'summary' => $summary,
            'tasks' => $this->taskRows(),
            'recent_errors' => $this->recentErrors(),
            'backups' => $this->recentBackups(),
            'database_tables' => $this->largestTables(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function diskMetric(): array
    {
        $total = @disk_total_space($this->root);
        $free = @disk_free_space($this->root);

        if (
            !is_float($total)
            && !is_int($total)
        ) {
            return $this->metric(
                'info',
                'Espaço em disco',
                'Não foi possível consultar o espaço em disco.',
                'bi-device-hdd',
                [
                    'total_bytes' => 0,
                    'free_bytes' => 0,
                    'used_percent' => null,
                ]
            );
        }

        $total = max(0, (int)$total);
        $free = (
            is_float($free)
            || is_int($free)
        )
            ? max(0, (int)$free)
            : 0;

        $used =
            $total > 0
                ? max(0, $total - $free)
                : 0;

        $percent =
            $total > 0
                ? ($used / $total) * 100
                : 0.0;

        $status =
            $percent >= 95
                ? 'error'
                : (
                    $percent >= 85
                        ? 'warn'
                        : 'ok'
                );

        return $this->metric(
            $status,
            'Espaço em disco',
            number_format(
                $percent,
                1,
                ',',
                '.'
            )
            . '% utilizado · '
            . $this->formatBytes($free)
            . ' livres de '
            . $this->formatBytes($total),
            'bi-device-hdd',
            [
                'total_bytes' => $total,
                'free_bytes' => $free,
                'used_bytes' => $used,
                'used_percent' => $percent,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function databaseMetric(): array
    {
        try {
            $database =
                (string)$this->pdo
                    ->query('SELECT DATABASE()')
                    ->fetchColumn();

            $version =
                (string)$this->pdo
                    ->query('SELECT VERSION()')
                    ->fetchColumn();

            $stmt = $this->pdo->prepare(
                'SELECT
                    COALESCE(
                        SUM(data_length + index_length),
                        0
                    )
                 FROM information_schema.tables
                 WHERE table_schema=:schema'
            );

            $stmt->execute([
                'schema' => $database,
            ]);

            $bytes =
                (int)$stmt->fetchColumn();

            return $this->metric(
                'ok',
                'Banco de dados',
                ($database !== '' ? $database : '(sem nome)')
                . ' · '
                . $this->formatBytes($bytes)
                . ' · '
                . $version,
                'bi-database-check',
                [
                    'name' => $database,
                    'version' => $version,
                    'bytes' => $bytes,
                ]
            );
        } catch (Throwable $e) {
            return $this->metric(
                'error',
                'Banco de dados',
                'Falha ao consultar tamanho/versão: '
                . $e->getMessage(),
                'bi-database-x'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadsMetric(): array
    {
        $path =
            $this->root
            . DIRECTORY_SEPARATOR
            . 'uploads';

        $exists = is_dir($path);
        $writable =
            $exists
            && is_writable($path);

        $bytes =
            $exists
                ? $this->directoryBytes(
                    $path,
                    25000
                )
                : 0;

        $status =
            !$exists
                ? 'error'
                : (
                    !$writable
                        ? 'error'
                        : 'ok'
                );

        $detail =
            !$exists
                ? 'A pasta uploads não existe.'
                : (
                    !$writable
                        ? 'A pasta uploads existe, mas não é gravável pelo PHP.'
                        : 'Gravável · aproximadamente '
                            . $this->formatBytes($bytes)
                            . ' armazenados.'
                );

        return $this->metric(
            $status,
            'Uploads',
            $detail,
            'bi-cloud-arrow-up',
            [
                'path' => $path,
                'exists' => $exists,
                'writable' => $writable,
                'bytes' => $bytes,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function cacheMetric(): array
    {
        if (!class_exists('CacheService')) {
            return $this->metric(
                'warn',
                'Cache',
                'CacheService não está carregado.',
                'bi-lightning-charge'
            );
        }

        try {
            CacheService::configure($this->pdo);

            $stats =
                CacheService::stats();

            $enabled =
                CacheService::enabled();

            $pageEnabled =
                CacheService::pageCacheEnabled();

            $writable =
                (bool)($stats['writable'] ?? false);

            $expired =
                (int)($stats['expired'] ?? 0);

            $status =
                !$writable
                    ? 'error'
                    : (
                        !$enabled
                            ? 'warn'
                            : (
                                $expired > 50
                                    ? 'warn'
                                    : 'ok'
                            )
                    );

            $detail =
                ($enabled ? 'Ativo' : 'Desativado')
                . ' · cache de página '
                . ($pageEnabled ? 'ativo' : 'desativado')
                . ' · '
                . (int)($stats['files'] ?? 0)
                . ' arquivo(s) · '
                . $this->formatBytes(
                    (int)($stats['bytes'] ?? 0)
                )
                . (
                    $expired > 0
                        ? ' · '
                            . $expired
                            . ' expirado(s)'
                        : ''
                );

            return $this->metric(
                $status,
                'Cache',
                $detail,
                'bi-lightning-charge',
                [
                    'enabled' => $enabled,
                    'page_enabled' => $pageEnabled,
                    'stats' => $stats,
                    'default_ttl' =>
                        CacheService::defaultTtl(),
                    'page_ttl' =>
                        CacheService::pageTtl(),
                ]
            );
        } catch (Throwable $e) {
            return $this->metric(
                'warn',
                'Cache',
                'Não foi possível consultar o cache: '
                . $e->getMessage(),
                'bi-lightning-charge'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function backupMetric(): array
    {
        $backups = $this->recentBackups();

        if (!$backups) {
            return $this->metric(
                'warn',
                'Backups',
                'Nenhum backup SQL/completo foi encontrado em storage/backups.',
                'bi-database-down',
                [
                    'count' => 0,
                    'latest' => null,
                ]
            );
        }

        $latest = $backups[0];
        $age =
            time()
            - (int)$latest['mtime'];

        $days =
            max(
                0,
                (int)floor(
                    $age / 86400
                )
            );

        $status =
            $days >= 14
                ? 'error'
                : (
                    $days >= 7
                        ? 'warn'
                        : 'ok'
                );

        return $this->metric(
            $status,
            'Último backup',
            $latest['name']
            . ' · '
            . $this->formatBytes(
                (int)$latest['size']
            )
            . ' · há '
            . $days
            . ' dia(s)',
            'bi-database-down',
            [
                'count' => count($backups),
                'latest' => $latest,
                'days_old' => $days,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function schedulerMetric(): array
    {
        if (
            !$this->tableExists(
                'tarefas_agendadas'
            )
        ) {
            return $this->metric(
                'warn',
                'Tarefas agendadas',
                'Tabela tarefas_agendadas não encontrada.',
                'bi-clock-history'
            );
        }

        try {
            $active =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM tarefas_agendadas
                         WHERE ativa=1"
                    )
                    ->fetchColumn();

            $overdue =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM tarefas_agendadas
                         WHERE ativa=1
                           AND proxima_execucao_em IS NOT NULL
                           AND proxima_execucao_em < DATE_SUB(
                                NOW(),
                                INTERVAL 30 MINUTE
                           )"
                    )
                    ->fetchColumn();

            $errors = 0;

            if (
                $this->tableExists(
                    'tarefas_execucoes'
                )
            ) {
                $errors =
                    (int)$this->pdo
                        ->query(
                            "SELECT COUNT(*)
                             FROM tarefas_execucoes
                             WHERE status='erro'
                               AND created_at >= DATE_SUB(
                                    NOW(),
                                    INTERVAL 24 HOUR
                               )"
                        )
                        ->fetchColumn();
            }

            $status =
                $errors > 0
                    ? 'error'
                    : (
                        $overdue > 0
                            ? 'warn'
                            : 'ok'
                    );

            return $this->metric(
                $status,
                'Tarefas agendadas',
                $active
                . ' ativa(s) · '
                . $overdue
                . ' atrasada(s) · '
                . $errors
                . ' erro(s) nas últimas 24h',
                'bi-clock-history',
                [
                    'active' => $active,
                    'overdue' => $overdue,
                    'errors_24h' => $errors,
                ]
            );
        } catch (Throwable $e) {
            return $this->metric(
                'warn',
                'Tarefas agendadas',
                'Não foi possível consultar o agendador: '
                . $e->getMessage(),
                'bi-clock-history'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function errorsMetric(): array
    {
        if (!$this->tableExists('logs')) {
            return $this->metric(
                'warn',
                'Erros recentes',
                'Tabela de auditoria não encontrada.',
                'bi-exclamation-octagon'
            );
        }

        try {
            $warning =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM logs
                         WHERE COALESCE(nivel,'info')
                            IN ('warning','critical')
                           AND created_at >= DATE_SUB(
                                NOW(),
                                INTERVAL 24 HOUR
                           )"
                    )
                    ->fetchColumn();

            $critical =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM logs
                         WHERE COALESCE(nivel,'info')='critical'
                           AND created_at >= DATE_SUB(
                                NOW(),
                                INTERVAL 24 HOUR
                           )"
                    )
                    ->fetchColumn();

            $status =
                $critical > 0
                    ? 'error'
                    : (
                        $warning > 0
                            ? 'warn'
                            : 'ok'
                    );

            return $this->metric(
                $status,
                'Alertas recentes',
                $warning
                . ' warning/critical nas últimas 24h'
                . (
                    $critical > 0
                        ? ' · '
                            . $critical
                            . ' crítico(s)'
                        : ''
                ),
                'bi-exclamation-octagon',
                [
                    'warning_critical_24h' => $warning,
                    'critical_24h' => $critical,
                ]
            );
        } catch (Throwable $e) {
            return $this->metric(
                'warn',
                'Alertas recentes',
                'Não foi possível consultar a auditoria.',
                'bi-exclamation-octagon'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function smtpMetric(): array
    {
        if (!class_exists('MailService')) {
            return $this->metric(
                'warn',
                'E-mail / SMTP',
                'MailService não está carregado.',
                'bi-envelope-exclamation'
            );
        }

        try {
            $transport =
                MailService::transport(
                    $this->pdo
                );

            $library =
                method_exists(
                    'MailService',
                    'libraryInstalled'
                )
                    ? MailService::libraryInstalled()
                    : true;

            $status =
                !$library
                    ? 'error'
                    : 'ok';

            $detail =
                'Transporte: '
                . strtoupper(
                    (string)$transport
                )
                . (
                    $library
                        ? ' · PHPMailer disponível'
                        : ' · PHPMailer não disponível'
                );

            return $this->metric(
                $status,
                'E-mail / SMTP',
                $detail,
                'bi-envelope-check',
                [
                    'transport' => $transport,
                    'library_installed' => $library,
                ]
            );
        } catch (Throwable $e) {
            return $this->metric(
                'warn',
                'E-mail / SMTP',
                'Não foi possível consultar a configuração de e-mail.',
                'bi-envelope-exclamation'
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function taskRows(): array
    {
        if (
            !$this->tableExists(
                'tarefas_agendadas'
            )
        ) {
            return [];
        }

        try {
            $columns =
                $this->columns(
                    'tarefas_agendadas'
                );

            $wanted = [
                'id',
                'slug',
                'nome',
                'descricao',
                'intervalo_minutos',
                'ativa',
                'ultima_execucao_em',
                'ultimo_status',
                'ultima_mensagem',
                'proxima_execucao_em',
            ];

            $select = [];

            foreach ($wanted as $column) {
                if (
                    in_array(
                        $column,
                        $columns,
                        true
                    )
                ) {
                    $select[] = '`'
                        . $column
                        . '`';
                }
            }

            if (!$select) {
                return [];
            }

            return $this->pdo
                ->query(
                    'SELECT '
                    . implode(',', $select)
                    . '
                     FROM tarefas_agendadas
                     ORDER BY
                        ativa DESC,
                        prioridade ASC,
                        id ASC'
                )
                ->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentErrors(): array
    {
        if (!$this->tableExists('logs')) {
            return [];
        }

        try {
            return $this->pdo
                ->query(
                    "SELECT
                        l.id,
                        l.acao,
                        l.detalhes,
                        l.ip,
                        l.nivel,
                        l.created_at,
                        u.nome AS usuario_nome
                     FROM logs l
                     LEFT JOIN usuarios u
                        ON u.id=l.usuario_id
                     WHERE COALESCE(l.nivel,'info')
                        IN ('warning','critical')
                     ORDER BY l.id DESC
                     LIMIT 8"
                )
                ->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array{name:string,path:string,size:int,mtime:int,type:string}>
     */
    private function recentBackups(): array
    {
        $dir =
            $this->root
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'backups';

        if (!is_dir($dir)) {
            return [];
        }

        $rows = [];

        foreach (
            scandir($dir) ?: []
            as $name
        ) {
            if (
                $name === '.'
                || $name === '..'
                || str_starts_with(
                    $name,
                    '.'
                )
                || $name === 'index.php'
            ) {
                continue;
            }

            $path =
                $dir
                . DIRECTORY_SEPARATOR
                . $name;

            if (!is_file($path)) {
                continue;
            }

            if (
                !preg_match(
                    '/\.(?:sql|sql\.gz|zip)$/i',
                    $name
                )
            ) {
                continue;
            }

            $size =
                @filesize($path);

            $mtime =
                @filemtime($path);

            $rows[] = [
                'name' => $name,
                'path' => $path,
                'size' =>
                    is_int($size)
                        ? $size
                        : 0,
                'mtime' =>
                    is_int($mtime)
                        ? $mtime
                        : 0,
                'type' =>
                    str_ends_with(
                        strtolower($name),
                        '.zip'
                    )
                        ? 'completo'
                        : 'banco',
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int =>
                (int)$b['mtime']
                <=>
                (int)$a['mtime']
        );

        return array_slice(
            $rows,
            0,
            8
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function largestTables(): array
    {
        try {
            $database =
                (string)$this->pdo
                    ->query(
                        'SELECT DATABASE()'
                    )
                    ->fetchColumn();

            $stmt = $this->pdo->prepare(
                'SELECT
                    table_name,
                    table_rows,
                    data_length,
                    index_length,
                    (data_length + index_length) AS total_bytes
                 FROM information_schema.tables
                 WHERE table_schema=:schema
                 ORDER BY total_bytes DESC
                 LIMIT 10'
            );

            $stmt->execute([
                'schema' => $database,
            ]);

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    private function columns(
        string $table
    ): array {
        if (
            !preg_match(
                '/^[a-zA-Z0-9_]+$/',
                $table
            )
        ) {
            return [];
        }

        try {
            $rows =
                $this->pdo
                    ->query(
                        'SHOW COLUMNS FROM `'
                        . $table
                        . '`'
                    )
                    ->fetchAll(PDO::FETCH_ASSOC)
                    ?: [];

            return array_values(
                array_filter(
                    array_map(
                        static fn(array $row): string =>
                            (string)($row['Field'] ?? ''),
                        $rows
                    )
                )
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    private function tableExists(
        string $table
    ): bool {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=:table_name'
            );

            $stmt->execute([
                'table_name' => $table,
            ]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function directoryBytes(
        string $path,
        int $maxFiles
    ): int {
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        $files = 0;

        try {
            $iterator =
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $path,
                        FilesystemIterator::SKIP_DOTS
                    )
                );

            foreach ($iterator as $item) {
                if (
                    $item->isFile()
                    && !$item->isLink()
                ) {
                    $bytes +=
                        (int)$item->getSize();

                    $files++;

                    if ($files >= $maxFiles) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            return $bytes;
        }

        return $bytes;
    }

    /**
     * @return array<string,mixed>
     */
    private function metric(
        string $status,
        string $label,
        string $detail,
        string $icon,
        array $data = []
    ): array {
        if (
            !in_array(
                $status,
                ['ok', 'warn', 'error', 'info'],
                true
            )
        ) {
            $status = 'info';
        }

        return [
            'status' => $status,
            'label' => $label,
            'detail' => $detail,
            'icon' => $icon,
            'data' => $data,
        ];
    }

    private function formatBytes(
        int $bytes
    ): string {
        $bytes = max(0, $bytes);

        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
        ];

        $value =
            (float)$bytes;

        $unit = 0;

        while (
            $value >= 1024
            && $unit < count($units) - 1
        ) {
            $value /= 1024;
            $unit++;
        }

        return number_format(
            $value,
            $unit === 0 ? 0 : 1,
            ',',
            '.'
        ) . ' ' . $units[$unit];
    }
}
