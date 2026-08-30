<?php

declare(strict_types=1);

/**
 * Fluxo editorial de Notícias.
 *
 * O status público do post continua usando:
 * rascunho, agendado, publicado, arquivado e lixeira.
 *
 * O fluxo de revisão é armazenado separadamente em workflow_status:
 * rascunho -> revisao -> ajustes -> aprovado -> publicado
 */
final class EditorialWorkflowService
{
    public const DRAFT = 'rascunho';
    public const REVIEW = 'revisao';
    public const CHANGES = 'ajustes';
    public const APPROVED = 'aprovado';
    public const PUBLISHED = 'publicado';

    public static function requiresReview(PDO $pdo): bool
    {
        return siteConfig(
            $pdo,
            'writing_require_review',
            '1'
        ) === '1';
    }

    public static function label(?string $status): string
    {
        return match ((string)$status) {
            self::REVIEW => 'Em revisão',
            self::CHANGES => 'Ajustes solicitados',
            self::APPROVED => 'Aprovado',
            self::PUBLISHED => 'Publicado',
            default => 'Rascunho editorial',
        };
    }

    public static function badgeClass(?string $status): string
    {
        return match ((string)$status) {
            self::REVIEW => 'warning',
            self::CHANGES => 'danger',
            self::APPROVED => 'success',
            self::PUBLISHED => 'primary',
            default => 'secondary',
        };
    }

    public static function status(PDO $pdo, int $postId): string
    {
        if ($postId <= 0) {
            return self::DRAFT;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT workflow_status
                 FROM posts
                 WHERE id=:id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $postId]);

            $status = (string)$stmt->fetchColumn();

            return in_array(
                $status,
                [
                    self::DRAFT,
                    self::REVIEW,
                    self::CHANGES,
                    self::APPROVED,
                    self::PUBLISHED,
                ],
                true
            )
                ? $status
                : self::DRAFT;
        } catch (Throwable $e) {
            return self::DRAFT;
        }
    }

    public static function canSubmit(PDO $pdo, int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT status
                 FROM posts
                 WHERE id=:id
                 LIMIT 1"
            );
            $stmt->execute(['id' => $postId]);
            $publicStatus = (string)$stmt->fetchColumn();

            return !in_array(
                $publicStatus,
                ['publicado', 'lixeira'],
                true
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function submit(
        PDO $pdo,
        int $postId,
        int $actorId
    ): void {
        $post = self::findPost($pdo, $postId);

        if (
            in_array(
                (string)$post['status'],
                ['publicado', 'lixeira'],
                true
            )
        ) {
            throw new RuntimeException(
                'Somente notícias ainda não publicadas podem ser enviadas para revisão.'
            );
        }

        $pdo->beginTransaction();

        try {
            RevisionService::create(
                $pdo,
                'post',
                $postId,
                $actorId
            );

            $pdo->prepare(
                "UPDATE posts
                 SET workflow_status=:workflow_status,
                     workflow_enviado_por=:actor,
                     workflow_enviado_em=NOW(),
                     workflow_revisado_por=NULL,
                     workflow_revisado_em=NULL,
                     workflow_hash=NULL,
                     workflow_observacao=NULL,
                     status='rascunho',
                     publicado_em=NULL
                 WHERE id=:id"
            )->execute([
                'workflow_status' => self::REVIEW,
                'actor' => $actorId,
                'id' => $postId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        logAction(
            $pdo,
            'noticia.workflow.enviar_revisao',
            'posts',
            $postId,
            (string)$post['titulo']
        );
    }

    public static function requestChanges(
        PDO $pdo,
        int $postId,
        int $actorId,
        string $note
    ): void {
        self::requireReviewer();

        $post = self::findPost($pdo, $postId);
        $workflowStatus = (string)($post['workflow_status'] ?? self::DRAFT);

        if (
            !in_array(
                $workflowStatus,
                [self::REVIEW, self::APPROVED],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta notícia não está aguardando revisão.'
            );
        }

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException(
                'Informe quais ajustes precisam ser realizados.'
            );
        }

        $note = mb_substr($note, 0, 4000);

        $pdo->prepare(
            'UPDATE posts
             SET workflow_status=:workflow_status,
                 workflow_revisado_por=:actor,
                 workflow_revisado_em=NOW(),
                 workflow_hash=NULL,
                 workflow_observacao=:note
             WHERE id=:id'
        )->execute([
            'workflow_status' => self::CHANGES,
            'actor' => $actorId,
            'note' => $note,
            'id' => $postId,
        ]);

        logAction(
            $pdo,
            'noticia.workflow.solicitar_ajustes',
            'posts',
            $postId,
            $note,
            'warning'
        );
    }

    public static function approve(
        PDO $pdo,
        int $postId,
        int $actorId,
        string $note = ''
    ): void {
        self::requireReviewer();

        $post = self::findPost($pdo, $postId);
        $workflowStatus = (string)($post['workflow_status'] ?? self::DRAFT);

        if (
            !in_array(
                $workflowStatus,
                [self::REVIEW, self::CHANGES],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta notícia precisa estar em revisão antes de ser aprovada.'
            );
        }

        $hash = self::contentHash(
            $pdo,
            $postId
        );

        $note = trim($note);
        $note = $note !== ''
            ? mb_substr($note, 0, 4000)
            : null;

        $pdo->prepare(
            'UPDATE posts
             SET workflow_status=:workflow_status,
                 workflow_revisado_por=:actor,
                 workflow_revisado_em=NOW(),
                 workflow_hash=:workflow_hash,
                 workflow_observacao=:note
             WHERE id=:id'
        )->execute([
            'workflow_status' => self::APPROVED,
            'actor' => $actorId,
            'workflow_hash' => $hash,
            'note' => $note,
            'id' => $postId,
        ]);

        logAction(
            $pdo,
            'noticia.workflow.aprovar',
            'posts',
            $postId,
            $note ?: (string)$post['titulo']
        );
    }

    public static function publish(
        PDO $pdo,
        int $postId,
        int $actorId
    ): void {
        self::requirePublisher();

        $post = self::findPost($pdo, $postId);

        if (
            self::requiresReview($pdo)
            && !self::isApprovalCurrent($pdo, $postId)
        ) {
            throw new RuntimeException(
                'A notícia não possui uma aprovação válida para a versão atual do conteúdo.'
            );
        }

        $pdo->beginTransaction();

        try {
            RevisionService::create(
                $pdo,
                'post',
                $postId,
                $actorId
            );

            $pdo->prepare(
                "UPDATE posts
                 SET status='publicado',
                     publicado_em=COALESCE(publicado_em,NOW()),
                     workflow_status=:workflow_status
                 WHERE id=:id"
            )->execute([
                'workflow_status' => self::PUBLISHED,
                'id' => $postId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        logAction(
            $pdo,
            'noticia.workflow.publicar',
            'posts',
            $postId,
            (string)$post['titulo']
        );
    }

    public static function isApprovalCurrent(
        PDO $pdo,
        int $postId
    ): bool {
        if ($postId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT workflow_status, workflow_hash
                 FROM posts
                 WHERE id=:id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $postId]);
            $row = $stmt->fetch();

            if (
                !$row
                || (string)$row['workflow_status'] !== self::APPROVED
                || trim((string)($row['workflow_hash'] ?? '')) === ''
            ) {
                return false;
            }

            return hash_equals(
                (string)$row['workflow_hash'],
                self::contentHash(
                    $pdo,
                    $postId
                )
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Protege publicação direta pelo editor.
     *
     * Conteúdos já publicados que estão apenas sendo editados continuam
     * funcionando como antes; esta primeira versão do workflow controla
     * principalmente a entrada de novos conteúdos em produção.
     */
    public static function assertStatusTransitionAllowed(
        PDO $pdo,
        ?int $postId,
        string $requestedStatus,
        string $currentPublicStatus
    ): void {
        if (
            !in_array(
                $requestedStatus,
                ['publicado', 'agendado'],
                true
            )
        ) {
            return;
        }

        /*
         * Evita quebrar o fluxo atual de edição de uma notícia que já
         * está publicada. Um fluxo de "nova revisão de conteúdo publicado"
         * pode ser adicionado em versão posterior.
         */
        if ($currentPublicStatus === 'publicado') {
            return;
        }

        self::requirePublisher();

        if (
            self::requiresReview($pdo)
            && (
                !$postId
                || !self::isApprovalCurrent(
                    $pdo,
                    $postId
                )
            )
        ) {
            throw new RuntimeException(
                'Antes de publicar ou agendar, envie a notícia para revisão e obtenha aprovação.'
            );
        }
    }

    /**
     * Validação das ações em massa de publicação.
     *
     * @param array<int|string,mixed> $ids
     */
    public static function assertBulkPublishAllowed(
        PDO $pdo,
        array $ids
    ): void {
        self::requirePublisher();

        if (!self::requiresReview($pdo)) {
            return;
        }

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $ids
                    ),
                    static fn(int $id): bool => $id > 0
                )
            )
        );

        foreach ($ids as $id) {
            $post = self::findPost(
                $pdo,
                $id
            );

            if ((string)$post['status'] === 'publicado') {
                continue;
            }

            if (!self::isApprovalCurrent($pdo, $id)) {
                throw new RuntimeException(
                    'A publicação em massa foi cancelada: "'
                    . (string)$post['titulo']
                    . '" ainda não possui aprovação válida.'
                );
            }
        }
    }

    /**
     * @param array<int|string,mixed> $ids
     */
    public static function markBulkPublished(
        PDO $pdo,
        array $ids
    ): void {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $ids
                    ),
                    static fn(int $id): bool => $id > 0
                )
            )
        );

        if (!$ids) {
            return;
        }

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($ids),
                '?'
            )
        );

        $stmt = $pdo->prepare(
            "UPDATE posts
             SET workflow_status=?
             WHERE id IN ({$placeholders})
               AND status='publicado'"
        );

        $stmt->execute(
            array_merge(
                [self::PUBLISHED],
                $ids
            )
        );
    }

    public static function syncAfterSave(
        PDO $pdo,
        int $postId,
        string $publicStatus
    ): void {
        if ($postId <= 0) {
            return;
        }

        if (
            in_array(
                $publicStatus,
                ['publicado', 'agendado'],
                true
            )
        ) {
            $pdo->prepare(
                'UPDATE posts
                 SET workflow_status=:workflow_status
                 WHERE id=:id'
            )->execute([
                'workflow_status' => self::PUBLISHED,
                'id' => $postId,
            ]);

            return;
        }

        /*
         * Não resetamos automaticamente revisão/aprovação ao salvar.
         * A validade da aprovação é calculada por hash. Se o conteúdo
         * mudar depois da aprovação, a publicação será bloqueada.
         */
    }

    public static function contentHash(
        PDO $pdo,
        int $postId
    ): string {
        $stmt = $pdo->prepare(
            'SELECT
                titulo,
                slug,
                resumo,
                conteudo,
                comunidade_id,
                categoria_id,
                imagem_capa_id,
                seo_titulo,
                seo_descricao,
                seo_noindex,
                destaque,
                comentarios_ativos
             FROM posts
             WHERE id=:id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $postId,
        ]);

        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            throw new RuntimeException(
                'Notícia não encontrada.'
            );
        }

        $categories = [];
        try {
            $st = $pdo->prepare(
                'SELECT categoria_id
                 FROM post_categorias
                 WHERE post_id=:id
                 ORDER BY principal DESC,categoria_id'
            );
            $st->execute(['id' => $postId]);
            $categories = array_map(
                'intval',
                $st->fetchAll(PDO::FETCH_COLUMN)
            );
        } catch (Throwable $ignored) {
        }

        $tags = [];
        try {
            $st = $pdo->prepare(
                'SELECT tag_id
                 FROM post_tags
                 WHERE post_id=:id
                 ORDER BY tag_id'
            );
            $st->execute(['id' => $postId]);
            $tags = array_map(
                'intval',
                $st->fetchAll(PDO::FETCH_COLUMN)
            );
        } catch (Throwable $ignored) {
        }

        $blocks = [];
        try {
            $st = $pdo->prepare(
                'SELECT tipo,ordem,configuracao
                 FROM conteudo_blocos
                 WHERE conteudo_tipo=:tipo
                   AND conteudo_id=:id
                 ORDER BY ordem,id'
            );
            $st->execute([
                'tipo' => 'post',
                'id' => $postId,
            ]);
            $blocks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ignored) {
            /*
             * Algumas versões podem usar outro nome de tabela;
             * os campos principais do post continuam protegidos.
             */
        }

        $data = [
            'post' => $post,
            'categories' => $categories,
            'tags' => $tags,
            'blocks' => $blocks,
        ];

        return hash(
            'sha256',
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: ''
        );
    }

    private static function findPost(
        PDO $pdo,
        int $postId
    ): array {
        if ($postId <= 0) {
            throw new RuntimeException(
                'Notícia inválida.'
            );
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM posts
             WHERE id=:id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $postId,
        ]);

        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            throw new RuntimeException(
                'Notícia não encontrada.'
            );
        }

        return $post;
    }

    private static function requireReviewer(): void
    {
        if (
            !Auth::can('noticias.revisar')
            && !Auth::isAdmin()
        ) {
            throw new RuntimeException(
                'Você não possui permissão para revisar notícias.'
            );
        }
    }

    private static function requirePublisher(): void
    {
        if (
            !Auth::can('noticias.publicar')
            && !Auth::isAdmin()
        ) {
            throw new RuntimeException(
                'Você não possui permissão para publicar notícias.'
            );
        }
    }
}
