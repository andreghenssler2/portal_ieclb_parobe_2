<?php

declare(strict_types=1);

/**
 * Linha do tempo de atividades por usuário.
 *
 * Unifica:
 * - logs de auditoria;
 * - início/fim das sessões administrativas da v0.83.
 *
 * Não cria uma nova cópia dos eventos. A linha do tempo consulta as fontes
 * existentes, preservando a auditoria como fonte oficial.
 */
final class UserActivityService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function user(
        int $userId
    ): ?array {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.id,
                u.nome,
                u.email,
                u.ativo,
                u.created_at,
                p.nome AS perfil_nome,
                p.slug AS perfil_slug
             FROM usuarios u
             LEFT JOIN perfis p
                ON p.id=u.perfil_id
             WHERE u.id=:id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function users(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                u.id,
                u.nome,
                u.email,
                u.ativo,
                p.nome AS perfil_nome
             FROM usuarios u
             LEFT JOIN perfis p
                ON p.id=u.perfil_id
             ORDER BY u.nome ASC,u.id ASC"
        );

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];
    }

    /**
     * @return array{
     *   total_logs:int,
     *   today:int,
     *   last_30_days:int,
     *   warnings_30:int,
     *   critical_30:int,
     *   active_sessions:int,
     *   last_activity:?string
     * }
     */
    public function summary(
        int $userId
    ): array {
        $stats = [
            'total_logs' => 0,
            'today' => 0,
            'last_30_days' => 0,
            'warnings_30' => 0,
            'critical_30' => 0,
            'active_sessions' => 0,
            'last_activity' => null,
        ];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COUNT(*) AS total_logs,
                    SUM(
                        CASE
                            WHEN created_at >= CURDATE()
                            THEN 1 ELSE 0
                        END
                    ) AS today_count,
                    SUM(
                        CASE
                            WHEN created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
                            THEN 1 ELSE 0
                        END
                    ) AS last_30_days,
                    SUM(
                        CASE
                            WHEN COALESCE(nivel,'info')='warning'
                             AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
                            THEN 1 ELSE 0
                        END
                    ) AS warnings_30,
                    SUM(
                        CASE
                            WHEN COALESCE(nivel,'info')='critical'
                             AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
                            THEN 1 ELSE 0
                        END
                    ) AS critical_30,
                    MAX(created_at) AS last_activity
                 FROM logs
                 WHERE usuario_id=:user_id"
            );

            $stmt->execute([
                'user_id' => $userId,
            ]);

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                ) ?: [];

            $stats['total_logs'] =
                (int)(
                    $row['total_logs']
                    ?? 0
                );

            $stats['today'] =
                (int)(
                    $row['today_count']
                    ?? 0
                );

            $stats['last_30_days'] =
                (int)(
                    $row['last_30_days']
                    ?? 0
                );

            $stats['warnings_30'] =
                (int)(
                    $row['warnings_30']
                    ?? 0
                );

            $stats['critical_30'] =
                (int)(
                    $row['critical_30']
                    ?? 0
                );

            $stats['last_activity'] =
                !empty(
                    $row['last_activity']
                )
                    ? (string)$row['last_activity']
                    : null;
        } catch (Throwable $ignored) {
        }

        if (
            $this->tableExists(
                'user_sessions'
            )
        ) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT
                        COUNT(*) AS active_sessions,
                        MAX(last_seen_at) AS last_seen
                     FROM user_sessions
                     WHERE user_id=:user_id
                       AND revoked_at IS NULL"
                );

                $stmt->execute([
                    'user_id' => $userId,
                ]);

                $row =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    ) ?: [];

                $stats['active_sessions'] =
                    (int)(
                        $row['active_sessions']
                        ?? 0
                    );

                $lastSeen =
                    !empty(
                        $row['last_seen']
                    )
                        ? (string)$row['last_seen']
                        : null;

                if (
                    $lastSeen !== null
                    && (
                        $stats['last_activity'] === null
                        || strtotime($lastSeen)
                            > strtotime(
                                (string)$stats['last_activity']
                            )
                    )
                ) {
                    $stats['last_activity'] =
                        $lastSeen;
                }
            } catch (Throwable $ignored) {
            }
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   total:int,
     *   page:int,
     *   per_page:int,
     *   pages:int,
     *   items:array<int,array<string,mixed>>
     * }
     */
    public function timeline(
        int $userId,
        array $filters,
        int $page = 1,
        int $perPage = 40
    ): array {
        $page =
            max(
                1,
                $page
            );

        $perPage =
            max(
                10,
                min(
                    100,
                    $perPage
                )
            );

        $query =
            trim(
                (string)(
                    $filters['q']
                    ?? ''
                )
            );

        $category =
            trim(
                (string)(
                    $filters['categoria']
                    ?? ''
                )
            );

        $level =
            trim(
                (string)(
                    $filters['nivel']
                    ?? ''
                )
            );

        $dateFrom =
            $this->validDate(
                (string)(
                    $filters['data_de']
                    ?? ''
                )
            );

        $dateTo =
            $this->validDate(
                (string)(
                    $filters['data_ate']
                    ?? ''
                )
            );

        $items =
            $this->auditItems(
                $userId,
                $query,
                $level,
                $dateFrom,
                $dateTo,
                1200
            );

        if (
            $this->tableExists(
                'user_sessions'
            )
        ) {
            $items =
                array_merge(
                    $items,
                    $this->sessionItems(
                        $userId,
                        $query,
                        $dateFrom,
                        $dateTo,
                        300
                    )
                );
        }

        if ($category !== '') {
            $items =
                array_values(
                    array_filter(
                        $items,
                        static fn(array $item): bool =>
                            (string)(
                                $item['category']
                                ?? ''
                            ) === $category
                    )
                );
        }

        usort(
            $items,
            static function (
                array $a,
                array $b
            ): int {
                $aTime =
                    strtotime(
                        (string)(
                            $a['created_at']
                            ?? ''
                        )
                    )
                    ?: 0;

                $bTime =
                    strtotime(
                        (string)(
                            $b['created_at']
                            ?? ''
                        )
                    )
                    ?: 0;

                if ($aTime === $bTime) {
                    return
                        strcmp(
                            (string)(
                                $b['source_id']
                                ?? ''
                            ),
                            (string)(
                                $a['source_id']
                                ?? ''
                            )
                        );
                }

                return
                    $bTime <=> $aTime;
            }
        );

        $total =
            count($items);

        $pages =
            max(
                1,
                (int)ceil(
                    $total
                    / $perPage
                )
            );

        $page =
            min(
                $page,
                $pages
            );

        $items =
            array_slice(
                $items,
                ($page - 1)
                * $perPage,
                $perPage
            );

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function categories(): array
    {
        return [
            '' =>
                'Todas as categorias',
            'conteudo' =>
                'Conteúdo',
            'conta' =>
                'Conta e usuários',
            'seguranca' =>
                'Segurança e sessões',
            'configuracao' =>
                'Configurações',
            'midia' =>
                'Mídia e arquivos',
            'formularios' =>
                'Formulários',
            'sistema' =>
                'Sistema',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function auditItems(
        int $userId,
        string $query,
        string $level,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit
    ): array {
        $where = [
            'usuario_id=:user_id',
        ];

        $params = [
            'user_id' =>
                $userId,
        ];

        if ($query !== '') {
            $where[] =
                "(
                    acao LIKE :search
                    OR COALESCE(entidade,'') LIKE :search
                    OR COALESCE(detalhes,'') LIKE :search
                    OR COALESCE(ip,'') LIKE :search
                    OR COALESCE(rota,'') LIKE :search
                )";

            $params['search'] =
                '%'
                . $query
                . '%';
        }

        if (
            in_array(
                $level,
                [
                    'info',
                    'warning',
                    'critical',
                ],
                true
            )
        ) {
            $where[] =
                "COALESCE(nivel,'info')=:nivel";

            $params['nivel'] =
                $level;
        }

        if ($dateFrom !== null) {
            $where[] =
                'created_at >= :data_de';

            $params['data_de'] =
                $dateFrom
                . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] =
                'created_at <= :data_ate';

            $params['data_ate'] =
                $dateTo
                . ' 23:59:59';
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                acao,
                entidade,
                entidade_id,
                detalhes,
                ip,
                user_agent,
                request_id,
                metodo,
                rota,
                COALESCE(nivel,'info') AS nivel,
                created_at
             FROM logs
             WHERE "
             . implode(
                ' AND ',
                $where
             )
             . "
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        $items = [];

        foreach ($rows as $row) {
            $action =
                (string)(
                    $row['acao']
                    ?? ''
                );

            $meta =
                $this->actionMeta(
                    $action,
                    (string)(
                        $row['entidade']
                        ?? ''
                    )
                );

            $details =
                trim(
                    (string)(
                        $row['detalhes']
                        ?? ''
                    )
                );

            $items[] = [
                'source' =>
                    'audit',
                'source_id' =>
                    'L'
                    . (int)$row['id'],
                'category' =>
                    $meta['category'],
                'category_label' =>
                    $meta['category_label'],
                'title' =>
                    $meta['title'],
                'action' =>
                    $action,
                'details' =>
                    $details,
                'entity' =>
                    (string)(
                        $row['entidade']
                        ?? ''
                    ),
                'entity_id' =>
                    (int)(
                        $row['entidade_id']
                        ?? 0
                    ),
                'level' =>
                    (string)(
                        $row['nivel']
                        ?? 'info'
                    ),
                'icon' =>
                    $meta['icon'],
                'created_at' =>
                    (string)$row['created_at'],
                'ip' =>
                    (string)(
                        $row['ip']
                        ?? ''
                    ),
                'user_agent' =>
                    (string)(
                        $row['user_agent']
                        ?? ''
                    ),
                'request_id' =>
                    (string)(
                        $row['request_id']
                        ?? ''
                    ),
                'route' =>
                    trim(
                        (string)(
                            $row['metodo']
                            ?? ''
                        )
                        . ' '
                        . (string)(
                            $row['rota']
                            ?? ''
                        )
                    ),
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sessionItems(
        int $userId,
        string $query,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit
    ): array {
        $where = [
            'user_id=:user_id',
        ];

        $params = [
            'user_id' =>
                $userId,
        ];

        if ($query !== '') {
            $where[] =
                "(
                    COALESCE(ip,'') LIKE :search
                    OR COALESCE(device_label,'') LIKE :search
                    OR COALESCE(user_agent,'') LIKE :search
                    OR COALESCE(revoke_reason,'') LIKE :search
                )";

            $params['search'] =
                '%'
                . $query
                . '%';
        }

        if ($dateFrom !== null) {
            $where[] =
                "(
                    created_at >= :data_de
                    OR revoked_at >= :data_de
                    OR last_seen_at >= :data_de
                )";

            $params['data_de'] =
                $dateFrom
                . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] =
                'created_at <= :data_ate';

            $params['data_ate'] =
                $dateTo
                . ' 23:59:59';
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                ip,
                user_agent,
                device_label,
                created_at,
                last_seen_at,
                revoked_at,
                revoke_reason
             FROM user_sessions
             WHERE "
             . implode(
                ' AND ',
                $where
             )
             . "
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        $items = [];

        foreach ($rows as $row) {
            $device =
                trim(
                    (string)(
                        $row['device_label']
                        ?? ''
                    )
                );

            $ip =
                trim(
                    (string)(
                        $row['ip']
                        ?? ''
                    )
                );

            $items[] = [
                'source' =>
                    'session',
                'source_id' =>
                    'S'
                    . (int)$row['id']
                    . '-start',
                'category' =>
                    'seguranca',
                'category_label' =>
                    'Segurança e sessões',
                'title' =>
                    'Sessão administrativa iniciada',
                'action' =>
                    'sessao.iniciada',
                'details' =>
                    trim(
                        $device
                        . (
                            $device !== ''
                            && $ip !== ''
                                ? ' · '
                                : ''
                        )
                        . (
                            $ip !== ''
                                ? 'IP '
                                . $ip
                                : ''
                        )
                    ),
                'entity' =>
                    'user_sessions',
                'entity_id' =>
                    (int)$row['id'],
                'level' =>
                    'info',
                'icon' =>
                    'bi-box-arrow-in-right',
                'created_at' =>
                    (string)$row['created_at'],
                'ip' =>
                    $ip,
                'user_agent' =>
                    (string)(
                        $row['user_agent']
                        ?? ''
                    ),
                'request_id' =>
                    '',
                'route' =>
                    '',
            ];

            if (
                !empty(
                    $row['revoked_at']
                )
            ) {
                $reason =
                    trim(
                        (string)(
                            $row['revoke_reason']
                            ?? ''
                        )
                    );

                $items[] = [
                    'source' =>
                        'session',
                    'source_id' =>
                        'S'
                        . (int)$row['id']
                        . '-end',
                    'category' =>
                        'seguranca',
                    'category_label' =>
                        'Segurança e sessões',
                    'title' =>
                        'Sessão administrativa encerrada',
                    'action' =>
                        'sessao.encerrada',
                    'details' =>
                        $reason !== ''
                            ? 'Motivo: '
                                . $reason
                            : 'Sessão encerrada.',
                    'entity' =>
                        'user_sessions',
                    'entity_id' =>
                        (int)$row['id'],
                    'level' =>
                        in_array(
                            $reason,
                            [
                                'inatividade',
                                'admin',
                                'manual',
                            ],
                            true
                        )
                            ? 'warning'
                            : 'info',
                    'icon' =>
                        'bi-box-arrow-right',
                    'created_at' =>
                        (string)$row['revoked_at'],
                    'ip' =>
                        $ip,
                    'user_agent' =>
                        (string)(
                            $row['user_agent']
                            ?? ''
                        ),
                    'request_id' =>
                        '',
                    'route' =>
                        '',
                ];
            }
        }

        return $items;
    }

    /**
     * @return array{
     *   category:string,
     *   category_label:string,
     *   title:string,
     *   icon:string
     * }
     */
    private function actionMeta(
        string $action,
        string $entity
    ): array {
        $needle =
            strtolower(
                $action
                . ' '
                . $entity
            );

        if (
            str_contains(
                $needle,
                'sessao'
            )
            || str_contains(
                $needle,
                'login'
            )
            || str_contains(
                $needle,
                'logout'
            )
            || str_contains(
                $needle,
                'seguranca'
            )
            || str_contains(
                $needle,
                '2fa'
            )
        ) {
            return [
                'category' =>
                    'seguranca',
                'category_label' =>
                    'Segurança e sessões',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-shield-lock',
            ];
        }

        if (
            str_contains(
                $needle,
                'usuario'
            )
            || str_contains(
                $needle,
                'perfil'
            )
            || str_contains(
                $needle,
                'senha'
            )
            || str_contains(
                $needle,
                'conta'
            )
        ) {
            return [
                'category' =>
                    'conta',
                'category_label' =>
                    'Conta e usuários',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-person-gear',
            ];
        }

        if (
            str_contains(
                $needle,
                'midia'
            )
            || str_contains(
                $needle,
                'media'
            )
            || str_contains(
                $needle,
                'galeria'
            )
            || str_contains(
                $needle,
                'documento'
            )
        ) {
            return [
                'category' =>
                    'midia',
                'category_label' =>
                    'Mídia e arquivos',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-images',
            ];
        }

        if (
            str_contains(
                $needle,
                'formulario'
            )
        ) {
            return [
                'category' =>
                    'formularios',
                'category_label' =>
                    'Formulários',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-ui-checks-grid',
            ];
        }

        if (
            str_contains(
                $needle,
                'config'
            )
            || str_contains(
                $needle,
                'tema'
            )
            || str_contains(
                $needle,
                'menu'
            )
            || str_contains(
                $needle,
                'banner'
            )
        ) {
            return [
                'category' =>
                    'configuracao',
                'category_label' =>
                    'Configurações',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-sliders',
            ];
        }

        if (
            str_contains(
                $needle,
                'noticia'
            )
            || str_contains(
                $needle,
                'post'
            )
            || str_contains(
                $needle,
                'pagina'
            )
            || str_contains(
                $needle,
                'evento'
            )
            || str_contains(
                $needle,
                'comentario'
            )
            || str_contains(
                $needle,
                'categoria'
            )
            || str_contains(
                $needle,
                'tag'
            )
        ) {
            return [
                'category' =>
                    'conteudo',
                'category_label' =>
                    'Conteúdo',
                'title' =>
                    $this->humanTitle(
                        $action
                    ),
                'icon' =>
                    'bi-file-earmark-text',
            ];
        }

        return [
            'category' =>
                'sistema',
            'category_label' =>
                'Sistema',
            'title' =>
                $this->humanTitle(
                    $action
                ),
            'icon' =>
                'bi-activity',
        ];
    }

    private function humanTitle(
        string $action
    ): string {
        $action =
            trim(
                $action
            );

        if ($action === '') {
            return
                'Atividade administrativa';
        }

        $known = [
            'usuario.editar' =>
                'Usuário atualizado',
            'usuario.criar' =>
                'Usuário criado',
            'sessao.encerrar' =>
                'Sessão encerrada',
            'sessao.encerrar_outras' =>
                'Outras sessões encerradas',
            'seguranca.sessao.encerrar' =>
                'Sessão encerrada pelo administrador',
            'seguranca.sessoes.usuario.encerrar' =>
                'Sessões do usuário encerradas',
            'configuracoes.midia' =>
                'Configurações de mídia atualizadas',
        ];

        if (
            isset(
                $known[$action]
            )
        ) {
            return
                $known[$action];
        }

        $text =
            str_replace(
                [
                    '.',
                    '_',
                    '-',
                ],
                ' ',
                $action
            );

        $text =
            preg_replace(
                '/\s+/u',
                ' ',
                trim($text)
            )
            ?? trim($text);

        return
            function_exists(
                'mb_convert_case'
            )
                ? mb_convert_case(
                    $text,
                    MB_CASE_TITLE,
                    'UTF-8'
                )
                : ucfirst($text);
    }

    private function tableExists(
        string $table
    ): bool {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=:table_name"
            );

            $stmt->execute([
                'table_name' =>
                    $table,
            ]);

            return
                (int)$stmt->fetchColumn()
                > 0;
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private function validDate(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
            || !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
        ) {
            return null;
        }

        $date =
            DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $value
            );

        if (
            !$date
            || $date->format(
                'Y-m-d'
            ) !== $value
        ) {
            return null;
        }

        return $value;
    }
}
