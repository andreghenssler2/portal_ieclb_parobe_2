<?php

declare(strict_types=1);

final class SchedulerService
{
    private const LOCK_FILE = 'storage/locks/portal-scheduler.lock';

    /** @return array<string,array{name:string,description:string,interval:int,enabled:bool,priority:int}> */
    public static function registry(): array
    {
        return [
            'publicar_conteudos_agendados' => [
                'name' => 'Publicar conteúdos agendados',
                'description' => 'Publica automaticamente Posts / Notícias e Páginas cuja data programada já chegou.',
                'interval' => 5,
                'enabled' => true,
                'priority' => 10,
            ],
            'limpar_cache_expirado' => [
                'name' => 'Limpar cache expirado',
                'description' => 'Remove arquivos de cache vencidos sem apagar o cache ainda válido.',
                'interval' => 60,
                'enabled' => true,
                'priority' => 20,
            ],
            'limpar_logs_email' => [
                'name' => 'Limpar histórico de e-mail',
                'description' => 'Aplica automaticamente o período de retenção configurado em Configurações > E-mail.',
                'interval' => 1440,
                'enabled' => true,
                'priority' => 30,
            ],
            'limpar_logs_auditoria' => [
                'name' => 'Limpar auditoria antiga',
                'description' => 'Remove registros de auditoria mais antigos que o período configurado em Segurança.',
                'interval' => 1440,
                'enabled' => true,
                'priority' => 40,
            ],
                    'verificar_integridade_midia' => [
                'name' => 'Verificar integridade da mídia',
                'description' => 'Compara diariamente registros da Biblioteca de Mídia com os arquivos físicos e salva um relatório operacional.',
                'interval' => 1440,
                'enabled' => true,
                'priority' => 50,
            ],
            'limpar_derivados_midia' => [
                'name' => 'Limpeza segura da mídia',
                'description' => 'Remove semanalmente apenas registros de variantes quebrados e derivados WebP órfãos; arquivos originais nunca são apagados automaticamente.',
                'interval' => 10080,
                'enabled' => true,
                'priority' => 60,
            ],
            'receber_respostas_email' => [
                'name' => 'Receber respostas por e-mail',
                'description' => 'Consulta a caixa IMAP e vincula respostas dos contatos às conversas dos formulários.',
                'interval' => 5,
                'enabled' => true,
                'priority' => 35,
            ],
];
    }

    public static function ensureRegistry(PDO $pdo): void
    {
        $sql = "INSERT INTO tarefas_agendadas
                    (slug,nome,descricao,intervalo_minutos,ativa,prioridade,proxima_execucao_em,created_at,updated_at)
                VALUES
                    (:slug,:nome,:descricao,:intervalo,:ativa,:prioridade,:proxima,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                    nome=VALUES(nome),
                    descricao=VALUES(descricao),
                    prioridade=VALUES(prioridade),
                    updated_at=NOW()";
        $stmt = $pdo->prepare($sql);
        $now = date('Y-m-d H:i:s');

        foreach (self::registry() as $slug => $task) {
            $stmt->execute([
                'slug' => $slug,
                'nome' => $task['name'],
                'descricao' => $task['description'],
                'intervalo' => $task['interval'],
                'ativa' => $task['enabled'] ? 1 : 0,
                'prioridade' => $task['priority'],
                'proxima' => $now,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function tasks(PDO $pdo): array
    {
        self::ensureRegistry($pdo);
        return $pdo->query(
            "SELECT *
             FROM tarefas_agendadas
             ORDER BY prioridade ASC,nome ASC,id ASC"
        )->fetchAll() ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $pdo->prepare(
            "SELECT e.*,t.nome AS tarefa_nome
             FROM tarefas_execucoes e
             LEFT JOIN tarefas_agendadas t ON t.id=e.tarefa_id
             ORDER BY e.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function saveSettings(PDO $pdo, array $activeSlugs, array $intervals): void
    {
        self::ensureRegistry($pdo);
        $known = self::registry();
        $activeMap = array_fill_keys(array_map('strval', $activeSlugs), true);

        $stmt = $pdo->prepare(
            "UPDATE tarefas_agendadas
             SET ativa=:ativa,
                 intervalo_minutos=:intervalo,
                 proxima_execucao_em=CASE
                     WHEN :ativa2=1 AND (proxima_execucao_em IS NULL OR proxima_execucao_em<NOW())
                     THEN NOW()
                     ELSE proxima_execucao_em
                 END,
                 updated_at=NOW()
             WHERE slug=:slug"
        );

        foreach ($known as $slug => $defaults) {
            $interval = isset($intervals[$slug]) ? (int)$intervals[$slug] : (int)$defaults['interval'];
            $interval = max(1, min(10080, $interval));
            $active = isset($activeMap[$slug]) ? 1 : 0;

            $stmt->execute([
                'ativa' => $active,
                'ativa2' => $active,
                'intervalo' => $interval,
                'slug' => $slug,
            ]);
        }
    }

    /**
     * Executa somente tarefas vencidas.
     *
     * @return array{ok:bool,locked:bool,results:array<int,array<string,mixed>>,message:string}
     */
    public static function runDue(PDO $pdo, string $origin = 'cron'): array
    {
        self::ensureRegistry($pdo);
        $lock = self::acquireLock();
        if ($lock === null) {
            return [
                'ok' => true,
                'locked' => true,
                'results' => [],
                'message' => 'Outra execução do agendador já está em andamento.',
            ];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM tarefas_agendadas
                 WHERE ativa=1
                   AND (proxima_execucao_em IS NULL OR proxima_execucao_em<=:agora)
                 ORDER BY prioridade ASC,id ASC
                 LIMIT 20"
            );
            $stmt->execute(['agora' => $now]);
            $tasks = $stmt->fetchAll() ?: [];

            $results = [];
            foreach ($tasks as $task) {
                $results[] = self::executeTaskRow($pdo, $task, $origin);
            }

            return [
                'ok' => !array_filter($results, static fn(array $r): bool => ($r['status'] ?? '') === 'erro'),
                'locked' => false,
                'results' => $results,
                'message' => $tasks ? count($tasks) . ' tarefa(s) processada(s).' : 'Nenhuma tarefa estava vencida.',
            ];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Executa uma tarefa imediatamente, mesmo que ainda não esteja vencida.
     *
     * @return array<string,mixed>
     */
    public static function runOne(PDO $pdo, string $slug, string $origin = 'manual'): array
    {
        self::ensureRegistry($pdo);
        if (!isset(self::registry()[$slug])) {
            throw new InvalidArgumentException('Tarefa desconhecida.');
        }

        $lock = self::acquireLock();
        if ($lock === null) {
            throw new RuntimeException('Outra execução do agendador já está em andamento.');
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM tarefas_agendadas WHERE slug=:slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $task = $stmt->fetch();
            if (!$task) {
                throw new RuntimeException('Tarefa não encontrada no banco de dados.');
            }

            return self::executeTaskRow($pdo, $task, $origin);
        } finally {
            self::releaseLock($lock);
        }
    }

    /** @return array<string,mixed> */
    private static function executeTaskRow(PDO $pdo, array $task, string $origin): array
    {
        $slug = (string)$task['slug'];
        $start = microtime(true);
        $startedAt = date('Y-m-d H:i:s');
        $executionId = 0;

        try {
            $insert = $pdo->prepare(
                "INSERT INTO tarefas_execucoes
                    (tarefa_id,tarefa_slug,origem,status,iniciada_em,created_at)
                 VALUES
                    (:tarefa_id,:slug,:origem,'executando',:inicio,NOW())"
            );
            $insert->execute([
                'tarefa_id' => (int)$task['id'],
                'slug' => $slug,
                'origem' => self::normalizeOrigin($origin),
                'inicio' => $startedAt,
            ]);
            $executionId = (int)$pdo->lastInsertId();

            $result = self::dispatch($pdo, $slug);
            $message = trim((string)($result['message'] ?? 'Tarefa concluída.'));
            $status = (string)($result['status'] ?? 'ok');
            if (!in_array($status, ['ok', 'ignorado'], true)) {
                $status = 'ok';
            }

            $finishedAt = date('Y-m-d H:i:s');
            $durationMs = max(0, (int)round((microtime(true) - $start) * 1000));
            self::finishExecution($pdo, $executionId, $status, $message, $finishedAt, $durationMs);
            self::finishTask($pdo, $task, $status, $message, $finishedAt, false);

            return [
                'slug' => $slug,
                'name' => (string)$task['nome'],
                'status' => $status,
                'message' => $message,
                'duration_ms' => $durationMs,
            ];
        } catch (Throwable $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $durationMs = max(0, (int)round((microtime(true) - $start) * 1000));
            $message = self::cut($e->getMessage(), 2000);

            if ($executionId > 0) {
                self::finishExecution($pdo, $executionId, 'erro', $message, $finishedAt, $durationMs);
            }
            self::finishTask($pdo, $task, 'erro', $message, $finishedAt, true);

            return [
                'slug' => $slug,
                'name' => (string)$task['nome'],
                'status' => 'erro',
                'message' => $message,
                'duration_ms' => $durationMs,
            ];
        }
    }

    /** @return array{status:string,message:string} */
    private static function dispatch(PDO $pdo, string $slug): array
    {
        return match ($slug) {
            'publicar_conteudos_agendados' => self::publishScheduledContent($pdo),
            'limpar_cache_expirado' => self::cleanupExpiredCache($pdo),
            'limpar_logs_email' => self::cleanupMailLogs($pdo),
            'limpar_logs_auditoria' => self::cleanupAuditLogs($pdo),
            'verificar_integridade_midia' => self::verifyMediaIntegrity($pdo),
            'receber_respostas_email' => self::syncInboundFormReplies($pdo),
            'limpar_derivados_midia' => self::cleanupMediaDerivedFiles($pdo),            default => throw new RuntimeException('Handler da tarefa não encontrado: ' . $slug),
        };
    }

    /** @return array{status:string,message:string} */
    /** @return array{status:string,message:string} */
    private static function syncInboundFormReplies(PDO $pdo): array
    {
        if (!class_exists('InboundMailService')) {
            return [
                'status' => 'ignorado',
                'message' => 'InboundMailService não está disponível.',
            ];
        }

        $result =
            InboundMailService::sync(
                $pdo,
                false
            );

        return [
            'status' =>
                (string)(
                    $result['status']
                    ?? 'ok'
                ),
            'message' =>
                (string)(
                    $result['message']
                    ?? 'Sincronização IMAP concluída.'
                ),
        ];
    }
    private static function publishScheduledContent(PDO $pdo): array
    {
        $now = date('Y-m-d H:i:s');
        $posts = 0;
        $pages = 0;

        if (self::tableExists($pdo, 'posts') && self::columnExists($pdo, 'posts', 'publicado_em')) {
            $stmt = $pdo->prepare(
                "UPDATE posts
                 SET status='publicado'
                 WHERE status='agendado'
                   AND publicado_em IS NOT NULL
                   AND publicado_em<=:agora"
            );
            $stmt->execute(['agora' => $now]);
            $posts = $stmt->rowCount();
        }

        if (self::tableExists($pdo, 'paginas') && self::columnExists($pdo, 'paginas', 'publicado_em')) {
            $stmt = $pdo->prepare(
                "UPDATE paginas
                 SET status='publicado'
                 WHERE status='agendado'
                   AND publicado_em IS NOT NULL
                   AND publicado_em<=:agora"
            );
            $stmt->execute(['agora' => $now]);
            $pages = $stmt->rowCount();
        }

        if (($posts + $pages) > 0 && class_exists('CacheService')) {
            CacheService::clearGroup('page');
            CacheService::clearGroup('public');
        }

        return [
            'status' => 'ok',
            'message' => "Publicados automaticamente: {$posts} post(s) e {$pages} página(s).",
        ];
    }

    /** @return array{status:string,message:string} */
    private static function cleanupExpiredCache(PDO $pdo): array
    {
        if (!class_exists('CacheService')) {
            return ['status' => 'ignorado', 'message' => 'CacheService não está disponível.'];
        }

        CacheService::configure($pdo);
        $removed = CacheService::cleanupExpired();

        return [
            'status' => 'ok',
            'message' => $removed . ' arquivo(s) de cache expirado removido(s).',
        ];
    }

    /** @return array{status:string,message:string} */
    private static function cleanupMailLogs(PDO $pdo): array
    {
        if (!class_exists('MailService') || !self::tableExists($pdo, 'email_envios')) {
            return ['status' => 'ignorado', 'message' => 'Histórico de e-mail não está disponível nesta instalação.'];
        }

        $before = (int)$pdo->query('SELECT COUNT(*) FROM email_envios')->fetchColumn();
        MailService::cleanupLogs($pdo);
        $after = (int)$pdo->query('SELECT COUNT(*) FROM email_envios')->fetchColumn();
        $removed = max(0, $before - $after);

        return [
            'status' => 'ok',
            'message' => $removed . ' registro(s) antigo(s) de e-mail removido(s).',
        ];
    }

    /** @return array{status:string,message:string} */
    private static function cleanupAuditLogs(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'logs') || !self::columnExists($pdo, 'logs', 'created_at')) {
            return ['status' => 'ignorado', 'message' => 'Tabela de auditoria não está disponível.'];
        }

        $days = 180;
        try {
            $days = max(7, min(3650, (int)siteConfig($pdo, 'security_audit_retention_days', '180')));
        } catch (Throwable $e) {
        }

        $cutoff = (new DateTimeImmutable('now'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('DELETE FROM logs WHERE created_at<:cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return [
            'status' => 'ok',
            'message' => $stmt->rowCount() . " registro(s) de auditoria com mais de {$days} dias removido(s).",
        ];
    }


        /** @return array{status:string,message:string} */
    private static function verifyMediaIntegrity(PDO $pdo): array
    {
        if (
            !class_exists('MediaIntegrityReportService')
            || !class_exists('MediaIntegrityService')
        ) {
            return [
                'status' => 'ignorado',
                'message' => 'Serviços de integridade da mídia não estão disponíveis.',
            ];
        }

        $result =
            MediaIntegrityReportService::run(
                $pdo,
                dirname(__DIR__, 2),
                'scheduler',
                false
            );

        return [
            'status' =>
                ($result['status'] ?? 'ok') === 'erro'
                    ? 'ok'
                    : 'ok',
            'message' =>
                'Integridade da mídia: '
                . (string)($result['message'] ?? 'verificação concluída.'),
        ];
    }

    /** @return array{status:string,message:string} */
    private static function cleanupMediaDerivedFiles(PDO $pdo): array
    {
        if (
            !class_exists('MediaIntegrityReportService')
            || !class_exists('MediaIntegrityService')
        ) {
            return [
                'status' => 'ignorado',
                'message' => 'Serviços de integridade da mídia não estão disponíveis.',
            ];
        }

        $result =
            MediaIntegrityReportService::run(
                $pdo,
                dirname(__DIR__, 2),
                'scheduler-cleanup',
                true
            );

        $generated =
            (array)(
                $result['cleaned_generated']
                ?? []
            );

        return [
            'status' => 'ok',
            'message' =>
                'Manutenção segura da mídia: '
                . (int)($result['cleaned_variant_records'] ?? 0)
                . ' registro(s) inválido(s) e '
                . (int)($generated['removed'] ?? 0)
                . ' derivado(s) órfão(s) removido(s); estado atual: '
                . (string)($result['message'] ?? 'verificado.'),
        ];
    }

private static function finishExecution(
        PDO $pdo,
        int $executionId,
        string $status,
        string $message,
        string $finishedAt,
        int $durationMs
    ): void {
        $stmt = $pdo->prepare(
            "UPDATE tarefas_execucoes
             SET status=:status,
                 mensagem=:mensagem,
                 finalizada_em=:fim,
                 duracao_ms=:duracao
             WHERE id=:id"
        );
        $stmt->execute([
            'status' => $status,
            'mensagem' => self::cut($message, 4000),
            'fim' => $finishedAt,
            'duracao' => $durationMs,
            'id' => $executionId,
        ]);
    }

    private static function finishTask(
        PDO $pdo,
        array $task,
        string $status,
        string $message,
        string $finishedAt,
        bool $error
    ): void {
        $interval = max(1, min(10080, (int)($task['intervalo_minutos'] ?? 60)));
        $next = (new DateTimeImmutable($finishedAt))->modify('+' . $interval . ' minutes')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "UPDATE tarefas_agendadas
             SET ultima_execucao_em=:ultima,
                 proxima_execucao_em=:proxima,
                 ultimo_status=:status,
                 ultima_mensagem=:mensagem,
                 total_execucoes=total_execucoes+1,
                 total_erros=total_erros + :erro,
                 erros_consecutivos=:consecutivos,
                 updated_at=NOW()
             WHERE id=:id"
        );
        $stmt->execute([
            'ultima' => $finishedAt,
            'proxima' => $next,
            'status' => $status,
            'mensagem' => self::cut($message, 2000),
            'erro' => $error ? 1 : 0,
            'consecutivos' => $error ? ((int)($task['erros_consecutivos'] ?? 0) + 1) : 0,
            'id' => (int)$task['id'],
        ]);
    }

    /** @return resource|null */
    private static function acquireLock()
    {
        $root = dirname(__DIR__, 2);
        $file = $root . '/' . self::LOCK_FILE;
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar storage/locks.');
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o lock do agendador.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid() . ' ' . date(DATE_ATOM));
        fflush($handle);

        return $handle;
    }

    /** @param resource $handle */
    private static function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private static function normalizeOrigin(string $origin): string
    {
        $origin = strtolower(trim($origin));
        return in_array($origin, ['cron', 'manual', 'cli'], true) ? $origin : 'cron';
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
