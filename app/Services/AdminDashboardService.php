<?php

declare(strict_types=1);

/**
 * Dados do Dashboard administrativo.
 *
 * O serviço monta o painel conforme as permissões do usuário.
 * Uma falha em um módulo opcional não deve derrubar a página inicial
 * administrativa: consultas individuais são protegidas.
 */
final class AdminDashboardService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        return [
            'profile' => $this->profile(),
            'summary' => $this->summaryCards(),
            'quick_actions' => $this->quickActions(),
            'news' => $this->recentNews(),
            'events' => $this->upcomingEvents(),
            'comments' => $this->pendingComments(),
            'forms' => $this->newFormResponses(),
            'audit' => $this->recentAudit(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(): array
    {
        $user = Auth::user() ?? [];

        return [
            'id' => (int)($user['id'] ?? 0),
            'name' => trim((string)($user['nome'] ?? '')),
            'profile_name' => trim((string)($user['perfil_nome'] ?? '')),
            'profile_slug' => trim((string)($user['perfil_slug'] ?? '')),
            'last_login' => $user['ultimo_login'] ?? null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function summaryCards(): array
    {
        $cards = [];

        if (Auth::can('noticias.gerenciar')) {
            $cards[] = $this->card(
                'Notícias publicadas',
                $this->count(
                    "SELECT COUNT(*)
                     FROM posts
                     WHERE status='publicado'"
                ),
                'bi-newspaper',
                'primary',
                'admin/noticias/index.php'
            );

            $cards[] = $this->card(
                'Rascunhos',
                $this->count(
                    "SELECT COUNT(*)
                     FROM posts
                     WHERE status='rascunho'"
                ),
                'bi-file-earmark-text',
                'secondary',
                'admin/noticias/index.php?status=rascunho'
            );
        }

        if (
            Auth::can('noticias.revisar')
            || Auth::can('noticias.publicar')
            || Auth::isAdmin()
        ) {
            $cards[] = $this->card(
                'Em revisão',
                $this->count(
                    "SELECT COUNT(*)
                     FROM posts
                     WHERE workflow_status='revisao'
                       AND status <> 'lixeira'"
                ),
                'bi-clipboard-check',
                'warning',
                'admin/noticias/revisao.php?status=revisao'
            );
        }

        if (Auth::can('eventos.gerenciar')) {
            $cards[] = $this->card(
                'Próximos eventos',
                $this->count(
                    "SELECT COUNT(*)
                     FROM eventos
                     WHERE status='publicado'
                       AND data_inicio >= NOW()"
                ),
                'bi-calendar-event',
                'success',
                'admin/eventos/index.php'
            );
        }

        if (Auth::can('comentarios.gerenciar')) {
            $cards[] = $this->card(
                'Comentários pendentes',
                $this->count(
                    "SELECT COUNT(*)
                     FROM comentarios
                     WHERE status='pendente'"
                ),
                'bi-chat-left-text',
                'warning',
                'admin/comentarios/index.php?status=pendente'
            );
        }

        if (Auth::can('formularios.gerenciar')) {
            $cards[] = $this->card(
                'Respostas novas',
                $this->count(
                    "SELECT COUNT(*)
                     FROM formulario_respostas
                     WHERE status='nova'"
                ),
                'bi-ui-checks-grid',
                'info',
                'admin/formularios/index.php'
            );
        }

        if (Auth::can('paginas.gerenciar')) {
            $cards[] = $this->card(
                'Páginas publicadas',
                $this->count(
                    "SELECT COUNT(*)
                     FROM paginas
                     WHERE status='publicado'"
                ),
                'bi-file-earmark-richtext',
                'primary',
                'admin/paginas/index.php'
            );
        }

        if (Auth::can('midias.gerenciar')) {
            $cards[] = $this->card(
                'Arquivos na mídia',
                $this->count(
                    'SELECT COUNT(*) FROM midias'
                ),
                'bi-images',
                'secondary',
                'admin/midias/index.php'
            );
        }

        if (Auth::can('documentos.gerenciar')) {
            $cards[] = $this->card(
                'Documentos publicados',
                $this->count(
                    "SELECT COUNT(*)
                     FROM documentos
                     WHERE status='publicado'"
                ),
                'bi-file-earmark-arrow-down',
                'success',
                'admin/documentos/index.php'
            );
        }

        if (Auth::can('usuarios.gerenciar')) {
            $cards[] = $this->card(
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

        if (Auth::can('auditoria.visualizar')) {
            $cards[] = $this->card(
                'Alertas · 24h',
                $this->count(
                    "SELECT COUNT(*)
                     FROM logs
                     WHERE COALESCE(nivel,'info')
                        IN ('warning','critical')
                       AND created_at >= DATE_SUB(
                            NOW(),
                            INTERVAL 24 HOUR
                       )"
                ),
                'bi-shield-exclamation',
                'danger',
                'admin/auditoria/index.php?nivel=warning'
            );
        }

        return $cards;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function quickActions(): array
    {
        $actions = [];

        if (Auth::can('noticias.gerenciar')) {
            $actions[] = $this->action(
                'Nova notícia',
                'Crie um novo conteúdo para o portal.',
                'bi-plus-circle',
                'primary',
                'admin/noticias/form.php'
            );
        }

        if (
            Auth::can('noticias.revisar')
            || Auth::can('noticias.publicar')
            || Auth::isAdmin()
        ) {
            $actions[] = $this->action(
                'Fila de revisão',
                'Revise, aprove e publique conteúdos.',
                'bi-clipboard-check',
                'warning',
                'admin/noticias/revisao.php'
            );
        }

        if (Auth::can('eventos.gerenciar')) {
            $actions[] = $this->action(
                'Novo evento/culto',
                'Cadastre um item na agenda.',
                'bi-calendar-plus',
                'success',
                'admin/eventos/form.php'
            );
        }

        if (Auth::can('paginas.gerenciar')) {
            $actions[] = $this->action(
                'Nova página',
                'Crie uma página institucional.',
                'bi-file-earmark-plus',
                'primary',
                'admin/paginas/form.php'
            );
        }

        if (Auth::can('midias.gerenciar')) {
            $actions[] = $this->action(
                'Biblioteca de mídia',
                'Envie ou gerencie arquivos.',
                'bi-cloud-arrow-up',
                'secondary',
                'admin/midias/index.php'
            );
        }

        if (Auth::can('comentarios.gerenciar')) {
            $actions[] = $this->action(
                'Moderar comentários',
                'Avalie mensagens pendentes.',
                'bi-chat-square-dots',
                'warning',
                'admin/comentarios/index.php?status=pendente'
            );
        }

        if (Auth::can('formularios.gerenciar')) {
            $actions[] = $this->action(
                'Formulários',
                'Veja formulários e respostas.',
                'bi-ui-checks',
                'info',
                'admin/formularios/index.php'
            );
        }

        if (Auth::can('backups.gerenciar')) {
            $actions[] = $this->action(
                'Backups',
                'Crie e confira cópias de segurança.',
                'bi-database-down',
                'secondary',
                'admin/ferramentas/backups.php'
            );
        }

        if (Auth::can('saude.visualizar')) {
            $actions[] = $this->action(
                'Diagnóstico',
                'Confira a saúde operacional do Portal.',
                'bi-heart-pulse',
                'danger',
                'admin/ferramentas/diagnostico.php'
            );
        }

        if (Auth::can('configuracoes.gerenciar')) {
            $actions[] = $this->action(
                'Configurações',
                'Ajuste as opções gerais do Portal.',
                'bi-gear',
                'secondary',
                'admin/configuracoes/index.php'
            );
        }

        return array_slice(
            $actions,
            0,
            8
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentNews(): array
    {
        if (!Auth::can('noticias.gerenciar')) {
            return [];
        }

        return $this->rows(
            "SELECT
                id,
                titulo,
                status,
                workflow_status,
                publicado_em,
                created_at,
                updated_at
             FROM posts
             WHERE status <> 'lixeira'
             ORDER BY
                COALESCE(
                    publicado_em,
                    updated_at,
                    created_at
                ) DESC,
                id DESC
             LIMIT 6"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function upcomingEvents(): array
    {
        if (!Auth::can('eventos.gerenciar')) {
            return [];
        }

        return $this->rows(
            "SELECT
                e.id,
                e.tipo,
                e.titulo,
                e.data_inicio,
                e.santa_ceia,
                c.nome AS comunidade_nome
             FROM eventos e
             LEFT JOIN comunidades c
                ON c.id=e.comunidade_id
             WHERE e.status='publicado'
               AND e.data_inicio >= NOW()
             ORDER BY e.data_inicio ASC,e.id ASC
             LIMIT 6"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pendingComments(): array
    {
        if (!Auth::can('comentarios.gerenciar')) {
            return [];
        }

        return $this->rows(
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
             LIMIT 5"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function newFormResponses(): array
    {
        if (!Auth::can('formularios.gerenciar')) {
            return [];
        }

        return $this->rows(
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
             LIMIT 5"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentAudit(): array
    {
        if (!Auth::can('auditoria.visualizar')) {
            return [];
        }

        return $this->rows(
            "SELECT
                l.id,
                l.acao,
                l.detalhes,
                l.nivel,
                l.created_at,
                u.nome AS usuario_nome
             FROM logs l
             LEFT JOIN usuarios u
                ON u.id=l.usuario_id
             ORDER BY l.id DESC
             LIMIT 6"
        );
    }

    private function count(string $sql): int
    {
        try {
            return max(
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
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            return $this->pdo
                ->query($sql)
                ->fetchAll(PDO::FETCH_ASSOC)
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function card(
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
    private function action(
        string $label,
        string $description,
        string $icon,
        string $class,
        string $url
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
            'class' => $class,
            'url' => $url,
        ];
    }
}
