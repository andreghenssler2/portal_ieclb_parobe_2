<?php

declare(strict_types=1);

/**
 * Diagnóstico operacional do cron e das tarefas agendadas.
 *
 * O heartbeat é gravado somente quando cron.php é executado no modo padrão
 * (tarefas vencidas). Execuções manuais --task/--all e --health não contam
 * como heartbeat do cron configurado no servidor.
 */
final class CronHealthService
{
    private const HEARTBEAT_FILE = 'storage/cron/last-run.json';
    private const HEALTHY_SECONDS = 900;   // 15 min
    private const WARNING_SECONDS = 1800;  // 30 min

    public static function heartbeatPath(string $rootPath): string
    {
        return rtrim($rootPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::HEARTBEAT_FILE);
    }

    public static function recordHeartbeat(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $file = self::heartbeatPath($rootPath);
        $dir = dirname($file);

        if (
            !is_dir($dir)
            && !mkdir($dir, 0750, true)
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Não foi possível criar storage/cron para o heartbeat.'
            );
        }

        if (!is_writable($dir)) {
            throw new RuntimeException(
                'storage/cron não possui permissão de escrita.'
            );
        }

        $payload = [
            'format' => 'portal-ieclb-cron-heartbeat',
            'format_version' => 1,
            'seen_at' => date(DATE_ATOM),
            'seen_at_local' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'pid' => function_exists('getmypid') ? (int)getmypid() : 0,
            'php_sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'app_version' => defined('APP_VERSION') ? (string)APP_VERSION : '',
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(
                'Não foi possível serializar o heartbeat do cron.'
            );
        }

        $tmp = $file . '.tmp';

        if (
            file_put_contents(
                $tmp,
                $json . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Não foi possível gravar o heartbeat temporário do cron.'
            );
        }

        if (
            DIRECTORY_SEPARATOR === '\\'
            && is_file($file)
        ) {
            @unlink($file);
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException(
                'Não foi possível finalizar o heartbeat do cron.'
            );
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public static function status(
        PDO $pdo,
        string $rootPath
    ): array {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        $heartbeat = self::readHeartbeat($rootPath);
        $heartbeatAge = null;
        $heartbeatState = 'never';
        $heartbeatLabel = 'Nunca registrado';

        if (is_array($heartbeat)) {
            $timestamp = (int)($heartbeat['timestamp'] ?? 0);

            if ($timestamp <= 0 && !empty($heartbeat['seen_at'])) {
                $parsed = strtotime((string)$heartbeat['seen_at']);
                $timestamp = $parsed === false ? 0 : $parsed;
            }

            if ($timestamp > 0) {
                $heartbeatAge = max(0, time() - $timestamp);

                if ($heartbeatAge <= self::HEALTHY_SECONDS) {
                    $heartbeatState = 'healthy';
                    $heartbeatLabel = 'Saudável';
                } elseif ($heartbeatAge <= self::WARNING_SECONDS) {
                    $heartbeatState = 'warning';
                    $heartbeatLabel = 'Atrasado';
                } else {
                    $heartbeatState = 'stale';
                    $heartbeatLabel = 'Sem execução recente';
                }
            }
        }

        $taskTable = self::tableExists($pdo, 'tarefas_agendadas');
        $historyTable = self::tableExists($pdo, 'tarefas_execucoes');

        $taskStats = [
            'total' => 0,
            'active' => 0,
            'overdue' => 0,
            'consecutive_errors' => 0,
            'never_run_active' => 0,
            'next_run' => null,
            'registry_missing' => [],
        ];

        if ($taskTable) {
            $row = $pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN ativa=1 THEN 1 ELSE 0 END) AS active,
                    SUM(
                        CASE
                            WHEN ativa=1
                             AND (
                                proxima_execucao_em IS NULL
                                OR proxima_execucao_em<=NOW()
                             )
                            THEN 1 ELSE 0
                        END
                    ) AS overdue,
                    SUM(
                        CASE
                            WHEN ativa=1
                             AND COALESCE(erros_consecutivos,0)>0
                            THEN 1 ELSE 0
                        END
                    ) AS consecutive_errors,
                    SUM(
                        CASE
                            WHEN ativa=1
                             AND ultima_execucao_em IS NULL
                            THEN 1 ELSE 0
                        END
                    ) AS never_run_active,
                    MIN(
                        CASE
                            WHEN ativa=1
                            THEN proxima_execucao_em
                            ELSE NULL
                        END
                    ) AS next_run
                 FROM tarefas_agendadas"
            )->fetch(PDO::FETCH_ASSOC) ?: [];

            $taskStats['total'] = (int)($row['total'] ?? 0);
            $taskStats['active'] = (int)($row['active'] ?? 0);
            $taskStats['overdue'] = (int)($row['overdue'] ?? 0);
            $taskStats['consecutive_errors'] =
                (int)($row['consecutive_errors'] ?? 0);
            $taskStats['never_run_active'] =
                (int)($row['never_run_active'] ?? 0);
            $taskStats['next_run'] =
                !empty($row['next_run'])
                    ? (string)$row['next_run']
                    : null;

            try {
                $known = array_keys(
                    class_exists('SchedulerService')
                        ? SchedulerService::registry()
                        : []
                );

                if ($known) {
                    $registered = $pdo->query(
                        'SELECT slug FROM tarefas_agendadas'
                    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

                    $taskStats['registry_missing'] = array_values(
                        array_diff(
                            array_map('strval', $known),
                            array_map('strval', $registered)
                        )
                    );
                }
            } catch (Throwable $ignored) {
            }
        }

        $historyStats = [
            'last_cron' => null,
            'last_any' => null,
            'stale_running' => 0,
            'recent_errors_24h' => 0,
        ];

        if ($historyTable) {
            try {
                $stmt = $pdo->query(
                    "SELECT *
                     FROM tarefas_execucoes
                     WHERE origem='cron'
                     ORDER BY id DESC
                     LIMIT 1"
                );
                $historyStats['last_cron'] =
                    $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $ignored) {
            }

            try {
                $stmt = $pdo->query(
                    "SELECT *
                     FROM tarefas_execucoes
                     ORDER BY id DESC
                     LIMIT 1"
                );
                $historyStats['last_any'] =
                    $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $ignored) {
            }

            try {
                $historyStats['stale_running'] = (int)$pdo->query(
                    "SELECT COUNT(*)
                     FROM tarefas_execucoes
                     WHERE status='executando'
                       AND iniciada_em < DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
                )->fetchColumn();
            } catch (Throwable $ignored) {
            }

            try {
                $historyStats['recent_errors_24h'] = (int)$pdo->query(
                    "SELECT COUNT(*)
                     FROM tarefas_execucoes
                     WHERE status='erro'
                       AND iniciada_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                )->fetchColumn();
            } catch (Throwable $ignored) {
            }
        }

        $storageCronDir =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'cron';

        $storageParent =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'storage';

        $cronFile =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'cron.php';

        $lockFile =
            $rootPath
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'locks'
            . DIRECTORY_SEPARATOR
            . 'portal-scheduler.lock';

        $structuralIssues = [];

        if (!is_file($cronFile)) {
            $structuralIssues[] = 'cron.php não foi encontrado na raiz.';
        }

        if (!$taskTable) {
            $structuralIssues[] = 'Tabela tarefas_agendadas não encontrada.';
        }

        if (!$historyTable) {
            $structuralIssues[] = 'Tabela tarefas_execucoes não encontrada.';
        }

        $canWriteHeartbeat =
            (
                is_dir($storageCronDir)
                && is_writable($storageCronDir)
            )
            || (
                !is_dir($storageCronDir)
                && is_dir($storageParent)
                && is_writable($storageParent)
            );

        if (!$canWriteHeartbeat) {
            $structuralIssues[] =
                'storage não permite criar/gravar o heartbeat do cron.';
        }

        $warnings = [];

        if ($heartbeatState === 'never') {
            $warnings[] =
                'Ainda não existe heartbeat do cron. Execute cron.php sem parâmetros ou aguarde o Cron Job configurado.';
        } elseif ($heartbeatState === 'warning') {
            $warnings[] =
                'O heartbeat está atrasado em relação ao intervalo recomendado de 5 minutos.';
        } elseif ($heartbeatState === 'stale') {
            $warnings[] =
                'O heartbeat está antigo; o Cron Job do servidor pode não estar sendo executado.';
        }

        if ($taskStats['overdue'] > 0) {
            $warnings[] =
                $taskStats['overdue']
                . ' tarefa(s) ativa(s) estão vencidas.';
        }

        if ($taskStats['consecutive_errors'] > 0) {
            $warnings[] =
                $taskStats['consecutive_errors']
                . ' tarefa(s) ativa(s) possuem erro(s) consecutivo(s).';
        }

        if ($historyStats['stale_running'] > 0) {
            $warnings[] =
                $historyStats['stale_running']
                . ' execução(ões) estão marcadas como executando há mais de 60 minutos.';
        }

        if ($taskStats['registry_missing']) {
            $warnings[] =
                'Existem tarefas do código ainda não registradas no banco: '
                . implode(', ', $taskStats['registry_missing'])
                . '.';
        }

        $overall = 'healthy';

        if ($structuralIssues) {
            $overall = 'error';
        } elseif (
            $heartbeatState === 'stale'
            || $taskStats['consecutive_errors'] > 0
            || $historyStats['stale_running'] > 0
        ) {
            $overall = 'warning';
        } elseif (
            $heartbeatState === 'warning'
            || $heartbeatState === 'never'
            || $taskStats['overdue'] > 0
        ) {
            $overall = 'attention';
        }

        return [
            'overall' => $overall,
            'heartbeat' => [
                'state' => $heartbeatState,
                'label' => $heartbeatLabel,
                'age_seconds' => $heartbeatAge,
                'data' => $heartbeat,
                'path' => self::heartbeatPath($rootPath),
            ],
            'tasks' => $taskStats,
            'history' => $historyStats,
            'filesystem' => [
                'cron_file' => $cronFile,
                'cron_exists' => is_file($cronFile),
                'heartbeat_writable' => $canWriteHeartbeat,
                'lock_file' => $lockFile,
                'lock_exists' => is_file($lockFile),
            ],
            'issues' => $structuralIssues,
            'warnings' => $warnings,
        ];
    }

    public static function cliReport(
        PDO $pdo,
        string $rootPath
    ): string {
        $status = self::status($pdo, $rootPath);

        $lines = [
            'Portal IECLB Parobé - Saúde do Cron',
            str_repeat('=', 72),
            'Estado geral: ' . strtoupper((string)$status['overall']),
            'Heartbeat: ' . (string)$status['heartbeat']['label'],
        ];

        $age =
            $status['heartbeat']['age_seconds'];

        if (is_int($age)) {
            $lines[] =
                'Idade do heartbeat: '
                . self::formatAge($age);
        }

        $lines[] =
            'Tarefas: '
            . (int)$status['tasks']['active']
            . ' ativa(s) de '
            . (int)$status['tasks']['total'];

        $lines[] =
            'Vencidas: '
            . (int)$status['tasks']['overdue'];

        $lines[] =
            'Com erros consecutivos: '
            . (int)$status['tasks']['consecutive_errors'];

        $lines[] =
            'Execuções órfãs (>60 min): '
            . (int)$status['history']['stale_running'];

        $lines[] =
            'Erros nas últimas 24h: '
            . (int)$status['history']['recent_errors_24h'];

        foreach ((array)$status['issues'] as $message) {
            $lines[] = '[ERRO] ' . (string)$message;
        }

        foreach ((array)$status['warnings'] as $message) {
            $lines[] = '[AVISO] ' . (string)$message;
        }

        $lines[] = str_repeat('=', 72);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function isStructurallyReady(array $status): bool
    {
        return empty($status['issues']);
    }

    public static function formatAge(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds . ' s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $hours
                . ' h'
                . (
                    $remainingMinutes > 0
                        ? ' ' . $remainingMinutes . ' min'
                        : ''
                );
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $days
            . ' d'
            . (
                $remainingHours > 0
                    ? ' ' . $remainingHours . ' h'
                    : ''
            );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function readHeartbeat(string $rootPath): ?array
    {
        $file = self::heartbeatPath($rootPath);

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if (
            !is_string($raw)
            || trim($raw) === ''
        ) {
            return null;
        }

        try {
            $data = json_decode(
                $raw,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return null;
        }

        if (
            !is_array($data)
            || ($data['format'] ?? '') !== 'portal-ieclb-cron-heartbeat'
        ) {
            return null;
        }

        return $data;
    }

    private static function tableExists(
        PDO $pdo,
        string $table
    ): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=:table'
        );

        $stmt->execute([
            'table' => $table,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
