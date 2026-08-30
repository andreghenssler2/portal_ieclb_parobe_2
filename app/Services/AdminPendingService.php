<?php

declare(strict_types=1);

/**
 * Central de pendências do painel administrativo.
 *
 * Reúne itens que exigem atenção sem alterar os módulos de origem.
 * Todas as consultas são protegidas para que um módulo opcional/antigo
 * não derrube o Dashboard.
 */
final class AdminPendingService
{
    /**
     * @return array{
     *   total:int,
     *   items:array<int,array{
     *     key:string,
     *     label:string,
     *     count:int,
     *     description:string,
     *     icon:string,
     *     class:string,
     *     url:string,
     *     priority:int
     *   }>
     * }
     */
    public static function overview(PDO $pdo): array
    {
        $items = [];

        /*
         * Fluxo editorial v0.61.
         */
        if (
            Auth::can('noticias.gerenciar')
            || Auth::can('noticias.revisar')
            || Auth::can('noticias.publicar')
            || Auth::isAdmin()
        ) {
            $review = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM posts
                 WHERE workflow_status='revisao'
                   AND status <> 'lixeira'"
            );

            if ($review > 0) {
                $items[] = self::item(
                    'revisao',
                    'Notícias aguardando revisão',
                    $review,
                    'Conteúdos enviados por autores/editores e ainda não avaliados.',
                    'bi-clipboard-check',
                    'warning',
                    'admin/noticias/revisao.php?status=revisao',
                    10
                );
            }

            $changes = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM posts
                 WHERE workflow_status='ajustes'
                   AND status <> 'lixeira'"
            );

            if ($changes > 0) {
                $items[] = self::item(
                    'ajustes',
                    'Notícias com ajustes solicitados',
                    $changes,
                    'Conteúdos devolvidos pela revisão e aguardando correções.',
                    'bi-pencil-square',
                    'danger',
                    'admin/noticias/revisao.php?status=ajustes',
                    20
                );
            }

            $approved = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM posts
                 WHERE workflow_status='aprovado'
                   AND status <> 'lixeira'"
            );

            if ($approved > 0) {
                $items[] = self::item(
                    'aprovados',
                    'Notícias aprovadas para publicação',
                    $approved,
                    'Versões aprovadas que ainda não foram publicadas.',
                    'bi-check2-circle',
                    'success',
                    'admin/noticias/revisao.php?status=aprovados',
                    15
                );
            }

            /*
             * Agendamentos são mostrados como pendência operacional.
             */
            $scheduled = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM posts
                 WHERE status='agendado'
                   AND publicado_em >= NOW()"
            );

            if ($scheduled > 0) {
                $items[] = self::item(
                    'agendados',
                    'Notícias agendadas',
                    $scheduled,
                    'Publicações futuras que merecem acompanhamento.',
                    'bi-clock-history',
                    'primary',
                    'admin/noticias/index.php?status=agenda',
                    60
                );
            }
        }

        if (Auth::can('comentarios.gerenciar')) {
            $comments = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM comentarios co
                 INNER JOIN posts p
                    ON p.id=co.post_id
                 WHERE co.status='pendente'
                   AND p.status <> 'lixeira'"
            );

            if ($comments > 0) {
                $items[] = self::item(
                    'comentarios',
                    'Comentários aguardando moderação',
                    $comments,
                    'Comentários ainda não aprovados ou recusados.',
                    'bi-chat-left-text',
                    'warning',
                    'admin/comentarios/index.php?status=pendente',
                    30
                );
            }
        }

        if (Auth::can('formularios.gerenciar')) {
            $responses = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM formulario_respostas
                 WHERE status='nova'"
            );

            if ($responses > 0) {
                $items[] = self::item(
                    'formularios',
                    'Novas respostas de formulários',
                    $responses,
                    'Respostas recebidas e ainda marcadas como novas.',
                    'bi-ui-checks-grid',
                    'info',
                    'admin/formularios/index.php',
                    40
                );
            }
        }

        if (Auth::can('auditoria.visualizar')) {
            $security = self::countSafe(
                $pdo,
                "SELECT COUNT(*)
                 FROM logs
                 WHERE COALESCE(nivel,'info')
                    IN ('warning','critical')
                   AND created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL 24 HOUR
                   )"
            );

            if ($security > 0) {
                $items[] = self::item(
                    'seguranca',
                    'Alertas de segurança nas últimas 24h',
                    $security,
                    'Eventos warning/critical registrados pela auditoria.',
                    'bi-shield-exclamation',
                    'danger',
                    'admin/auditoria/index.php?nivel=warning',
                    5
                );
            }
        }

        if (
            Auth::can('midias.gerenciar')
            && class_exists('MediaIntegrityReportService')
        ) {
            try {
                $mediaIntegrityCount =
                    MediaIntegrityReportService::reviewCount(
                        $pdo
                    );

                if ($mediaIntegrityCount > 0) {
                    $items[] = self::item(
                        'integridade_midia',
                        'Biblioteca de Mídia precisa de revisão',
                        $mediaIntegrityCount,
                        'O último diagnóstico encontrou arquivos ausentes, divergências ou arquivos físicos sem registro.',
                        'bi-hdd-stack',
                        'warning',
                        'admin/midias/monitoramento.php',
                        25
                    );
                }
            } catch (Throwable $ignored) {
            }
        }

        usort(
            $items,
            static fn(array $a, array $b): int =>
                ((int)$a['priority'] <=> (int)$b['priority'])
                ?: strcmp(
                    (string)$a['label'],
                    (string)$b['label']
                )
        );

        return [
            'total' => array_sum(
                array_map(
                    static fn(array $item): int =>
                        (int)$item['count'],
                    $items
                )
            ),
            'items' => $items,
        ];
    }

    /**
     * Itens editoriais que ajudam a agir sem sair da Central.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function editorialQueue(
        PDO $pdo,
        int $limit = 8
    ): array {
        if (
            !Auth::can('noticias.gerenciar')
            && !Auth::can('noticias.revisar')
            && !Auth::can('noticias.publicar')
            && !Auth::isAdmin()
        ) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            return $pdo->query(
                "SELECT
                    p.id,
                    p.titulo,
                    p.status,
                    p.workflow_status,
                    p.workflow_enviado_em,
                    p.workflow_revisado_em,
                    p.workflow_observacao,
                    p.updated_at,
                    u.nome AS autor_nome
                 FROM posts p
                 LEFT JOIN usuarios u
                    ON u.id=p.autor_id
                 WHERE p.workflow_status
                    IN ('revisao','ajustes','aprovado')
                   AND p.status <> 'lixeira'
                 ORDER BY
                    CASE p.workflow_status
                        WHEN 'revisao' THEN 1
                        WHEN 'aprovado' THEN 2
                        WHEN 'ajustes' THEN 3
                        ELSE 4
                    END,
                    COALESCE(
                        p.workflow_enviado_em,
                        p.updated_at,
                        p.created_at
                    ) ASC,
                    p.id ASC
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function pendingComments(
        PDO $pdo,
        int $limit = 6
    ): array {
        if (!Auth::can('comentarios.gerenciar')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            return $pdo->query(
                "SELECT
                    co.id,
                    co.autor_nome,
                    co.conteudo,
                    co.created_at,
                    p.titulo AS post_titulo
                 FROM comentarios co
                 INNER JOIN posts p
                    ON p.id=co.post_id
                 WHERE co.status='pendente'
                   AND p.status <> 'lixeira'
                 ORDER BY co.created_at DESC
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function newFormResponses(
        PDO $pdo,
        int $limit = 6
    ): array {
        if (!Auth::can('formularios.gerenciar')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            return $pdo->query(
                "SELECT
                    r.id,
                    r.formulario_id,
                    r.created_at,
                    f.titulo AS formulario_titulo
                 FROM formulario_respostas r
                 INNER JOIN formularios f
                    ON f.id=r.formulario_id
                 WHERE r.status='nova'
                 ORDER BY r.created_at DESC
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function securityAlerts(
        PDO $pdo,
        int $limit = 6
    ): array {
        if (!Auth::can('auditoria.visualizar')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            return $pdo->query(
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
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function scheduledPosts(
        PDO $pdo,
        int $limit = 6
    ): array {
        if (!Auth::can('noticias.gerenciar')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            return $pdo->query(
                "SELECT
                    id,
                    titulo,
                    slug,
                    publicado_em
                 FROM posts
                 WHERE status='agendado'
                   AND publicado_em >= NOW()
                 ORDER BY publicado_em ASC,id ASC
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function countSafe(
        PDO $pdo,
        string $sql
    ): int {
        try {
            return max(
                0,
                (int)$pdo->query($sql)->fetchColumn()
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   count:int,
     *   description:string,
     *   icon:string,
     *   class:string,
     *   url:string,
     *   priority:int
     * }
     */
    private static function item(
        string $key,
        string $label,
        int $count,
        string $description,
        string $icon,
        string $class,
        string $url,
        int $priority
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'count' => max(0, $count),
            'description' => $description,
            'icon' => $icon,
            'class' => $class,
            'url' => $url,
            'priority' => $priority,
        ];
    }
}
