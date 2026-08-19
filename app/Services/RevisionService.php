<?php

declare(strict_types=1);

final class RevisionService
{
    private const TYPES = ['post', 'pagina'];

    public static function create(PDO $pdo, string $type, int $contentId, ?int $userId = null): int
    {
        self::assertType($type);
        $snapshot = self::snapshot($pdo, $type, $contentId);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Não foi possível gerar a revisão do conteúdo.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO revisoes (tipo, conteudo_id, autor_id, dados) VALUES (:tipo, :conteudo_id, :autor_id, :dados)'
        );
        $stmt->execute([
            'tipo' => $type,
            'conteudo_id' => $contentId,
            'autor_id' => $userId,
            'dados' => $json,
        ]);
        $revisionId = (int)$pdo->lastInsertId();
        self::prune($pdo, $type, $contentId);
        return $revisionId;
    }

    public static function count(PDO $pdo, string $type, int $contentId): int
    {
        self::assertType($type);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM revisoes WHERE tipo = :tipo AND conteudo_id = :conteudo_id');
        $stmt->execute(['tipo' => $type, 'conteudo_id' => $contentId]);
        return (int)$stmt->fetchColumn();
    }

    public static function list(PDO $pdo, string $type, int $contentId): array
    {
        self::assertType($type);
        $stmt = $pdo->prepare(
            'SELECT r.*, u.nome AS autor_nome
             FROM revisoes r
             LEFT JOIN usuarios u ON u.id = r.autor_id
             WHERE r.tipo = :tipo AND r.conteudo_id = :conteudo_id
             ORDER BY r.id DESC'
        );
        $stmt->execute(['tipo' => $type, 'conteudo_id' => $contentId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string)$row['dados'], true);
            $row['snapshot'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    public static function restore(PDO $pdo, int $revisionId, string $type, int $contentId, ?int $userId = null): void
    {
        self::assertType($type);
        $stmt = $pdo->prepare('SELECT * FROM revisoes WHERE id = :id AND tipo = :tipo AND conteudo_id = :conteudo_id LIMIT 1');
        $stmt->execute(['id' => $revisionId, 'tipo' => $type, 'conteudo_id' => $contentId]);
        $revision = $stmt->fetch();
        if (!$revision) {
            throw new RuntimeException('Revisão não encontrada.');
        }

        $snapshot = json_decode((string)$revision['dados'], true);
        if (!is_array($snapshot) || !isset($snapshot['record']) || !is_array($snapshot['record'])) {
            throw new RuntimeException('Os dados desta revisão estão inválidos.');
        }

        $pdo->beginTransaction();
        try {
            // Guarda o estado atual para permitir desfazer a restauração.
            self::create($pdo, $type, $contentId, $userId);
            if ($type === 'post') {
                self::restorePost($pdo, $contentId, $snapshot);
            } else {
                self::restorePage($pdo, $contentId, $snapshot);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function deleteForContent(PDO $pdo, string $type, int $contentId): void
    {
        self::assertType($type);
        $stmt = $pdo->prepare('DELETE FROM revisoes WHERE tipo = :tipo AND conteudo_id = :conteudo_id');
        $stmt->execute(['tipo' => $type, 'conteudo_id' => $contentId]);
    }

    private static function snapshot(PDO $pdo, string $type, int $contentId): array
    {
        if ($type === 'post') {
            $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $contentId]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new RuntimeException('Notícia não encontrada para revisão.');
            }
            $tagsStmt = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id = :id ORDER BY tag_id');
            $tagsStmt->execute(['id' => $contentId]);
            return [
                'record' => $record,
                'tags' => array_map('intval', $tagsStmt->fetchAll(PDO::FETCH_COLUMN)),
            ];
        }

        $stmt = $pdo->prepare('SELECT * FROM paginas WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $contentId]);
        $record = $stmt->fetch();
        if (!$record) {
            throw new RuntimeException('Página não encontrada para revisão.');
        }
        return ['record' => $record];
    }

    private static function restorePost(PDO $pdo, int $contentId, array $snapshot): void
    {
        $record = $snapshot['record'];
        $status = in_array(($record['status'] ?? ''), ['rascunho', 'agendado', 'publicado', 'arquivado'], true)
            ? (string)$record['status'] : 'rascunho';
        $stmt = $pdo->prepare(
            'UPDATE posts SET
                autor_id = :autor_id,
                comunidade_id = :comunidade_id,
                categoria_id = :categoria_id,
                titulo = :titulo,
                slug = :slug,
                resumo = :resumo,
                conteudo = :conteudo,
                imagem_capa_id = :imagem_capa_id,
                seo_titulo = :seo_titulo,
                seo_descricao = :seo_descricao,
                seo_noindex = :seo_noindex,
                status = :status,
                status_anterior = NULL,
                lixeira_em = NULL,
                destaque = :destaque,
                comentarios_ativos = :comentarios_ativos,
                publicado_em = :publicado_em
             WHERE id = :id'
        );
        $stmt->execute([
            'autor_id' => $record['autor_id'] ?? null,
            'comunidade_id' => $record['comunidade_id'] ?? null,
            'categoria_id' => $record['categoria_id'] ?? null,
            'titulo' => (string)($record['titulo'] ?? ''),
            'slug' => (string)($record['slug'] ?? ''),
            'resumo' => $record['resumo'] ?? null,
            'conteudo' => (string)($record['conteudo'] ?? ''),
            'imagem_capa_id' => $record['imagem_capa_id'] ?? null,
            'seo_titulo' => $record['seo_titulo'] ?? null,
            'seo_descricao' => $record['seo_descricao'] ?? null,
            'seo_noindex' => (int)($record['seo_noindex'] ?? 0),
            'status' => $status,
            'destaque' => (int)($record['destaque'] ?? 0),
            'comentarios_ativos' => (int)($record['comentarios_ativos'] ?? 1),
            'publicado_em' => $record['publicado_em'] ?? null,
            'id' => $contentId,
        ]);

        $pdo->prepare('DELETE FROM post_tags WHERE post_id = :id')->execute(['id' => $contentId]);
        $tags = array_values(array_unique(array_filter(array_map('intval', (array)($snapshot['tags'] ?? [])), static fn(int $v): bool => $v > 0)));
        if ($tags) {
            $valid = $pdo->prepare('SELECT id FROM tags WHERE id IN (' . implode(',', array_fill(0, count($tags), '?')) . ')');
            $valid->execute($tags);
            $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
            $link = $pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (:post_id, :tag_id)');
            foreach ($validIds as $tagId) {
                $link->execute(['post_id' => $contentId, 'tag_id' => $tagId]);
            }
        }
    }

    private static function restorePage(PDO $pdo, int $contentId, array $snapshot): void
    {
        $record = $snapshot['record'];
        $status = in_array(($record['status'] ?? ''), ['rascunho', 'agendado', 'publicado', 'arquivado'], true)
            ? (string)$record['status'] : 'rascunho';
        $stmt = $pdo->prepare(
            'UPDATE paginas SET
                autor_id = :autor_id,
                titulo = :titulo,
                slug = :slug,
                resumo = :resumo,
                conteudo = :conteudo,
                imagem_capa_id = :imagem_capa_id,
                seo_titulo = :seo_titulo,
                seo_descricao = :seo_descricao,
                seo_noindex = :seo_noindex,
                status = :status,
                status_anterior = NULL,
                lixeira_em = NULL,
                exibir_menu = :exibir_menu,
                ordem = :ordem,
                publicado_em = :publicado_em
             WHERE id = :id'
        );
        $stmt->execute([
            'autor_id' => $record['autor_id'] ?? null,
            'titulo' => (string)($record['titulo'] ?? ''),
            'slug' => (string)($record['slug'] ?? ''),
            'resumo' => $record['resumo'] ?? null,
            'conteudo' => (string)($record['conteudo'] ?? ''),
            'imagem_capa_id' => $record['imagem_capa_id'] ?? null,
            'seo_titulo' => $record['seo_titulo'] ?? null,
            'seo_descricao' => $record['seo_descricao'] ?? null,
            'seo_noindex' => (int)($record['seo_noindex'] ?? 0),
            'status' => $status,
            'exibir_menu' => (int)($record['exibir_menu'] ?? 0),
            'ordem' => (int)($record['ordem'] ?? 0),
            'publicado_em' => $record['publicado_em'] ?? null,
            'id' => $contentId,
        ]);
    }

    private static function prune(PDO $pdo, string $type, int $contentId): void
    {
        $limit = 30;
        try {
            $limit = (int)siteConfig($pdo, 'writing_revision_limit', '30');
        } catch (Throwable $e) {
            $limit = 30;
        }
        $limit = max(5, min(100, $limit));
        $stmt = $pdo->prepare('SELECT id FROM revisoes WHERE tipo = :tipo AND conteudo_id = :conteudo_id ORDER BY id DESC');
        $stmt->execute(['tipo' => $type, 'conteudo_id' => $contentId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $remove = array_slice($ids, $limit);
        if (!$remove) {
            return;
        }
        $delete = $pdo->prepare('DELETE FROM revisoes WHERE id IN (' . implode(',', array_fill(0, count($remove), '?')) . ')');
        $delete->execute($remove);
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de revisão inválido.');
        }
    }
}
