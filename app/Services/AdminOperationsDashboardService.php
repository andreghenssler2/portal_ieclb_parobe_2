<?php

declare(strict_types=1);

/**
 * Painel operacional adicional da Administração v1.1.10.
 *
 * Somente leitura: não cria tabelas, não altera configurações e não executa
 * tarefas. Falhas de módulos opcionais são convertidas em avisos para que o
 * Dashboard continue disponível.
 */
final class AdminOperationsDashboardService
{
    public function __construct(
        private PDO $pdo,
        private string $rootPath
    ) {
        $this->rootPath =
            rtrim(
                $this->rootPath,
                DIRECTORY_SEPARATOR
            );
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        return [
            'numbers' =>
                $this->numbers(),
            'accesses' =>
                $this->recentAccesses(),
            'waiting_news' =>
                $this->waitingNews(),
            'events' =>
                $this->upcomingEvents(),
            'media' =>
                $this->mediaStats(),
            'alerts' =>
                $this->operationalAlerts(),
            'quick_links' =>
                $this->quickLinks(),
            'readiness' =>
                $this->readiness(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function numbers(): array
    {
        $cards = [];

        if (
            Auth::can('noticias.gerenciar')
            || Auth::isAdmin()
        ) {
            $cards[] =
                $this->metric(
                    'Notícias publicadas',
                    $this->count(
                        "SELECT COUNT(*)
                         FROM posts
                         WHERE status='publicado'
                           AND (publicado_em IS NULL OR publicado_em<=NOW())"
                    ),
                    'bi-newspaper',
                    'primary',
                    'admin/noticias/index.php'
                );

            $cards[] =
                $this->metric(
                    'Aguardando publicação',
                    $this->count(
                        "SELECT COUNT(*)
                         FROM posts
                         WHERE status <> 'lixeira'
                           AND (
                                status IN ('rascunho','agendado')
                                OR workflow_status IN ('revisao','aprovado')
                                OR (
                                    publicado_em IS NOT NULL
                                    AND publicado_em > NOW()
                                )
                           )"
                    ),
                    'bi-hourglass-split',
                    'warning',
                    'admin/noticias/index.php'
                );
        }

        if (
            Auth::can('eventos.gerenciar')
            || Auth::isAdmin()
        ) {
            $cards[] =
                $this->metric(
                    'Eventos futuros',
                    $this->count(
                        "SELECT COUNT(*)
                         FROM eventos
                         WHERE status='publicado'
                           AND data_inicio>=NOW()"
                    ),
                    'bi-calendar-event',
                    'success',
                    'admin/eventos/index.php'
                );
        }

        if (
            Auth::can('comunidades.gerenciar')
            || Auth::isAdmin()
        ) {
            $cards[] =
                $this->metric(
                    'Comunidades ativas',
                    $this->count(
                        'SELECT COUNT(*)
                         FROM comunidades
                         WHERE ativa=1'
                    ),
                    'bi-buildings',
                    'info',
                    'admin/comunidades/index.php'
                );
        }

        if (
            Auth::can('midias.gerenciar')
            || Auth::isAdmin()
        ) {
            $cards[] =
                $this->metric(
                    'Arquivos de mídia',
                    $this->count(
                        'SELECT COUNT(*)
                         FROM midias'
                    ),
                    'bi-collection-play',
                    'secondary',
                    'admin/midias/index.php'
                );
        }

        if (
            Auth::can('usuarios.gerenciar')
            || Auth::isAdmin()
        ) {
            $cards[] =
                $this->metric(
                    'Usuários ativos',
                    $this->count(
                        'SELECT COUNT(*)
                         FROM usuarios
                         WHERE ativo=1'
                    ),
                    'bi-people',
                    'secondary',
                    'admin/usuarios/index.php'
                );
        }

        return
            array_slice(
                $cards,
                0,
                6
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentAccesses(): array
    {
        if (
            !Auth::can('usuarios.gerenciar')
            && !Auth::can('auditoria.visualizar')
            && !Auth::isAdmin()
        ) {
            return [];
        }

        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        id,
                        nome,
                        email,
                        ultimo_login
                     FROM usuarios
                     WHERE ativo=1
                       AND ultimo_login IS NOT NULL
                     ORDER BY ultimo_login DESC,id DESC
                     LIMIT 6"
                );

            return
                $stmt->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            /*
             * Compatibilidade com instalações que não possuem email na
             * projeção esperada.
             */
            try {
                $stmt =
                    $this->pdo->query(
                        "SELECT
                            id,
                            nome,
                            ultimo_login
                         FROM usuarios
                         WHERE ativo=1
                           AND ultimo_login IS NOT NULL
                         ORDER BY ultimo_login DESC,id DESC
                         LIMIT 6"
                    );

                return
                    $stmt->fetchAll(PDO::FETCH_ASSOC)
                    ?: [];
            } catch (Throwable $ignored) {
                return [];
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function waitingNews(): array
    {
        if (
            !Auth::can('noticias.gerenciar')
            && !Auth::can('noticias.revisar')
            && !Auth::can('noticias.publicar')
            && !Auth::isAdmin()
        ) {
            return [];
        }

        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        id,
                        titulo,
                        status,
                        workflow_status,
                        publicado_em,
                        updated_at,
                        created_at
                     FROM posts
                     WHERE status <> 'lixeira'
                       AND (
                            status IN ('rascunho','agendado')
                            OR workflow_status IN ('revisao','aprovado')
                            OR (
                                publicado_em IS NOT NULL
                                AND publicado_em > NOW()
                            )
                       )
                     ORDER BY
                        CASE
                            WHEN workflow_status='revisao' THEN 1
                            WHEN status='agendado' THEN 2
                            WHEN publicado_em > NOW() THEN 3
                            ELSE 4
                        END,
                        COALESCE(publicado_em,updated_at,created_at) ASC,
                        id ASC
                     LIMIT 8"
                );

            return
                $stmt->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function upcomingEvents(): array
    {
        if (
            !Auth::can('eventos.gerenciar')
            && !Auth::isAdmin()
        ) {
            return [];
        }

        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        e.id,
                        e.titulo,
                        e.tipo,
                        e.data_inicio,
                        e.local,
                        c.nome AS comunidade_nome
                     FROM eventos e
                     LEFT JOIN comunidades c
                        ON c.id=e.comunidade_id
                     WHERE e.status='publicado'
                       AND e.data_inicio>=NOW()
                     ORDER BY e.data_inicio,e.id
                     LIMIT 6"
                );

            return
                $stmt->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function mediaStats(): array
    {
        $empty = [
            'count' => 0,
            'bytes' => 0,
            'images' => 0,
            'videos' => 0,
            'documents' => 0,
        ];

        if (
            !Auth::can('midias.gerenciar')
            && !Auth::isAdmin()
        ) {
            return $empty;
        }

        try {
            $row =
                $this->pdo
                    ->query(
                        "SELECT
                            COUNT(*) AS total,
                            COALESCE(SUM(tamanho),0) AS bytes,
                            SUM(
                                CASE
                                    WHEN mime_type LIKE 'image/%'
                                    THEN 1 ELSE 0
                                END
                            ) AS images,
                            SUM(
                                CASE
                                    WHEN mime_type IN ('video/mp4','application/mp4')
                                    THEN 1 ELSE 0
                                END
                            ) AS videos,
                            SUM(
                                CASE
                                    WHEN mime_type NOT LIKE 'image/%'
                                     AND mime_type NOT IN ('video/mp4','application/mp4')
                                    THEN 1 ELSE 0
                                END
                            ) AS documents
                         FROM midias"
                    )
                    ->fetch(PDO::FETCH_ASSOC)
                ?: [];

            return [
                'count' =>
                    (int)($row['total'] ?? 0),
                'bytes' =>
                    (int)($row['bytes'] ?? 0),
                'images' =>
                    (int)($row['images'] ?? 0),
                'videos' =>
                    (int)($row['videos'] ?? 0),
                'documents' =>
                    (int)($row['documents'] ?? 0),
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function operationalAlerts(): array
    {
        $alerts = [];

        /*
         * Manutenção.
         */
        try {
            $maintenance =
                maintenanceSettings(
                    $this->pdo
                );

            $maintenanceEnabled =
                !empty(
                    $maintenance['enabled']
                );

            $alerts[] =
                $this->alert(
                    'Manutenção',
                    $maintenanceEnabled
                        ? 'warning'
                        : 'success',
                    'bi-cone-striped',
                    $maintenanceEnabled
                        ? 'Modo manutenção ativo.'
                        : 'Portal público liberado.',
                    'admin/ferramentas/manutencao.php'
                );
        } catch (Throwable $e) {
            $alerts[] =
                $this->alert(
                    'Manutenção',
                    'secondary',
                    'bi-cone-striped',
                    'Status não disponível.',
                    'admin/ferramentas/manutencao.php'
                );
        }

        /*
         * Cron / scheduler.
         */
        if (class_exists('CronHealthService')) {
            try {
                $cron =
                    CronHealthService::status(
                        $this->pdo,
                        $this->rootPath
                    );

                $heartbeatState =
                    (string)(
                        $cron['heartbeat']['state']
                        ?? 'never'
                    );

                $errors =
                    (int)(
                        $cron['tasks']['consecutive_errors']
                        ?? 0
                    )
                    + (int)(
                        $cron['history']['stale_running']
                        ?? 0
                    );

                $status =
                    $errors > 0
                        ? 'danger'
                        : (
                            $heartbeatState === 'healthy'
                                ? 'success'
                                : 'warning'
                        );

                $detail =
                    (string)(
                        $cron['heartbeat']['label']
                        ?? 'Heartbeat não identificado.'
                    );

                if ($errors > 0) {
                    $detail .=
                        ' '
                        . $errors
                        . ' ocorrência(s) para revisão.';
                }

                $alerts[] =
                    $this->alert(
                        'Cron',
                        $status,
                        'bi-clock-history',
                        $detail,
                        'admin/ferramentas/tarefas-agendadas.php'
                    );
            } catch (Throwable $e) {
                $alerts[] =
                    $this->alert(
                        'Cron',
                        'warning',
                        'bi-clock-history',
                        'Não foi possível consultar a saúde do agendador.',
                        'admin/ferramentas/tarefas-agendadas.php'
                    );
            }
        } else {
            $alerts[] =
                $this->alert(
                    'Cron',
                    'secondary',
                    'bi-clock-history',
                    'Diagnóstico do cron indisponível.',
                    'admin/ferramentas/tarefas-agendadas.php'
                );
        }

        /*
         * Backup de banco.
         */
        if (class_exists('BackupService')) {
            try {
                $backupService =
                    new BackupService(
                        $this->pdo,
                        $this->rootPath
                    );

                $backups =
                    $backupService
                        ->listDatabaseBackups();

                $latest =
                    $backups[0]
                    ?? null;

                if (!$latest) {
                    $alerts[] =
                        $this->alert(
                            'Backup',
                            'warning',
                            'bi-database-down',
                            'Nenhum backup de banco encontrado.',
                            'admin/ferramentas/backups.php'
                        );
                } else {
                    $mtime =
                        (int)(
                            $latest['mtime']
                            ?? 0
                        );

                    $ageHours =
                        $mtime > 0
                            ? max(
                                0,
                                (int)floor(
                                    (time() - $mtime)
                                    / 3600
                                )
                            )
                            : 999999;

                    $status =
                        $ageHours <= 36
                            ? 'success'
                            : (
                                $ageHours <= 72
                                    ? 'warning'
                                    : 'danger'
                            );

                    $alerts[] =
                        $this->alert(
                            'Backup',
                            $status,
                            'bi-database-check',
                            $mtime > 0
                                ? (
                                    'Último backup: '
                                    . date(
                                        'd/m/Y H:i',
                                        $mtime
                                    )
                                    . '.'
                                )
                                : 'Backup encontrado sem data válida.',
                            'admin/ferramentas/backups.php'
                        );
                }
            } catch (Throwable $e) {
                $alerts[] =
                    $this->alert(
                        'Backup',
                        'warning',
                        'bi-database-exclamation',
                        'Não foi possível consultar os backups.',
                        'admin/ferramentas/backups.php'
                    );
            }
        }

        /*
         * E-mail / DNS.
         */
        if (class_exists('MailDnsHealthService')) {
            try {
                $mail =
                    MailDnsHealthService::report(
                        $this->pdo
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

                $status =
                    $score >= $maxScore
                        ? 'success'
                        : (
                            $score > 0
                                ? 'warning'
                                : 'danger'
                        );

                $alerts[] =
                    $this->alert(
                        'E-mail',
                        $status,
                        'bi-envelope-check',
                        'SPF/DKIM/DMARC/MX: '
                        . $score
                        . '/'
                        . $maxScore
                        . '.',
                        'admin/configuracoes/email.php'
                    );
            } catch (Throwable $e) {
                $alerts[] =
                    $this->alert(
                        'E-mail',
                        'warning',
                        'bi-envelope-exclamation',
                        'Diagnóstico de e-mail indisponível.',
                        'admin/configuracoes/email.php'
                    );
            }
        }

        return $alerts;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function quickLinks(): array
    {
        $links = [];

        $definitions = [
            [
                'permission' =>
                    'noticias.gerenciar',
                'label' =>
                    'Nova notícia',
                'url' =>
                    'admin/noticias/form.php',
                'icon' =>
                    'bi-plus-circle',
            ],
            [
                'permission' =>
                    'eventos.gerenciar',
                'label' =>
                    'Novo evento',
                'url' =>
                    'admin/eventos/form.php',
                'icon' =>
                    'bi-calendar-plus',
            ],
            [
                'permission' =>
                    'midias.gerenciar',
                'label' =>
                    'Mídias',
                'url' =>
                    'admin/midias/index.php',
                'icon' =>
                    'bi-images',
            ],
            [
                'permission' =>
                    'backups.gerenciar',
                'label' =>
                    'Backups',
                'url' =>
                    'admin/ferramentas/backups.php',
                'icon' =>
                    'bi-database-down',
            ],
            [
                'permission' =>
                    'tarefas.gerenciar',
                'label' =>
                    'Tarefas',
                'url' =>
                    'admin/ferramentas/tarefas-agendadas.php',
                'icon' =>
                    'bi-clock-history',
            ],
            [
                'permission' =>
                    'configuracoes.gerenciar',
                'label' =>
                    'E-mail',
                'url' =>
                    'admin/configuracoes/email.php',
                'icon' =>
                    'bi-envelope-gear',
            ],
            [
                'permission' =>
                    'manutencao.gerenciar',
                'label' =>
                    'Manutenção',
                'url' =>
                    'admin/ferramentas/manutencao.php',
                'icon' =>
                    'bi-cone-striped',
            ],
            [
                'permission' =>
                    'auditoria.visualizar',
                'label' =>
                    'Auditoria',
                'url' =>
                    'admin/auditoria/index.php',
                'icon' =>
                    'bi-shield-check',
            ],
        ];

        foreach ($definitions as $item) {
            if (
                !Auth::isAdmin()
                && !Auth::can(
                    (string)$item['permission']
                )
            ) {
                continue;
            }

            $links[] = [
                'label' =>
                    (string)$item['label'],
                'url' =>
                    (string)$item['url'],
                'icon' =>
                    (string)$item['icon'],
            ];
        }

        return
            array_slice(
                $links,
                0,
                8
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function readiness(): array
    {
        if (
            !class_exists(
                'ProductionReadinessService'
            )
        ) {
            return [
                'available' => false,
                'score' => 0,
                'checks' => 0,
                'blockers' => 0,
                'warnings' => 0,
                'state' => 'unknown',
            ];
        }

        try {
            $report =
                ProductionReadinessService::report(
                    $this->pdo,
                    $this->rootPath
                );

            return [
                'available' => true,
                'score' =>
                    (int)(
                        $report['score']
                        ?? 0
                    ),
                'checks' =>
                    (int)(
                        $report['checks']
                        ?? 0
                    ),
                'blockers' =>
                    count(
                        (array)(
                            $report['blockers']
                            ?? []
                        )
                    ),
                'warnings' =>
                    count(
                        (array)(
                            $report['warnings']
                            ?? []
                        )
                    ),
                'state' =>
                    (string)(
                        $report['state']
                        ?? 'unknown'
                    ),
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'score' => 0,
                'checks' => 0,
                'blockers' => 0,
                'warnings' => 0,
                'state' => 'error',
            ];
        }
    }

    private function count(string $sql): int
    {
        try {
            return
                max(
                    0,
                    (int)$this->pdo
                        ->query($sql)
                        ->fetchColumn()
                );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function metric(
        string $label,
        int $value,
        string $icon,
        string $class,
        string $url
    ): array {
        return [
            'label' => $label,
            'value' => $value,
            'icon' => $icon,
            'class' => $class,
            'url' => $url,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function alert(
        string $label,
        string $status,
        string $icon,
        string $detail,
        string $url
    ): array {
        return [
            'label' => $label,
            'status' => $status,
            'icon' => $icon,
            'detail' => $detail,
            'url' => $url,
        ];
    }
}
