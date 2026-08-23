<?php

declare(strict_types=1);

final class NewsEngagementService
{
    /**
     * Notícias relacionadas priorizando categorias em comum e, depois,
     * a mesma comunidade.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function related(PDO $pdo, array $post, int $limit = 4): array
    {
        $currentId = max(0, (int)($post['id'] ?? 0));
        if ($currentId <= 0) {
            return [];
        }

        $limit = max(1, min(8, $limit));
        $communityId = max(0, (int)($post['comunidade_id'] ?? 0));
        $categoryIds = self::postCategoryIds($pdo, $post);
        $hasPivot = self::tableExists($pdo, 'post_categorias');

        $score = [];
        $relevance = [];

        if ($communityId > 0) {
            $score[] = '(CASE WHEN p.comunidade_id=' . $communityId . ' THEN 30 ELSE 0 END)';
            $relevance[] = 'p.comunidade_id=' . $communityId;
        }

        if ($categoryIds) {
            $in = implode(',', $categoryIds);
            $score[] = '(CASE WHEN p.categoria_id IN (' . $in . ') THEN 80 ELSE 0 END)';
            $relevance[] = 'p.categoria_id IN (' . $in . ')';

            if ($hasPivot) {
                $score[] = 'COALESCE((SELECT COUNT(*)*100 FROM post_categorias rpc WHERE rpc.post_id=p.id AND rpc.categoria_id IN (' . $in . ')),0)';
                $relevance[] = 'EXISTS (SELECT 1 FROM post_categorias rpc2 WHERE rpc2.post_id=p.id AND rpc2.categoria_id IN (' . $in . '))';
            }
        }

        $scoreSql = $score ? implode(' + ', $score) : '0';
        $whereRelevance = $relevance
            ? ' AND (' . implode(' OR ', $relevance) . ')'
            : '';

        $sql = "SELECT p.id,p.titulo,p.slug,p.resumo,p.conteudo,p.publicado_em,p.created_at,
                       p.comunidade_id,p.categoria_id,
                       m.caminho AS imagem_capa_midia,m.alt_text AS imagem_capa_alt,
                       c.nome AS comunidade_nome,
                       (" . $scoreSql . ") AS relevancia
                FROM posts p
                LEFT JOIN midias m ON m.id=p.imagem_capa_id
                LEFT JOIN comunidades c ON c.id=p.comunidade_id
                WHERE p.id<>" . $currentId . "
                  AND p.status='publicado'
                  AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())"
                . $whereRelevance . "
                ORDER BY relevancia DESC,
                         COALESCE(p.publicado_em,p.created_at) DESC,
                         p.id DESC
                LIMIT " . $limit;

        $rows = $pdo->query($sql)->fetchAll() ?: [];

        // Se a notícia não tem classificação suficiente, ainda oferecemos
        // conteúdo recente para não deixar o bloco vazio.
        if (!$rows) {
            $fallback = "SELECT p.id,p.titulo,p.slug,p.resumo,p.conteudo,p.publicado_em,p.created_at,
                                p.comunidade_id,p.categoria_id,
                                m.caminho AS imagem_capa_midia,m.alt_text AS imagem_capa_alt,
                                c.nome AS comunidade_nome,
                                0 AS relevancia
                         FROM posts p
                         LEFT JOIN midias m ON m.id=p.imagem_capa_id
                         LEFT JOIN comunidades c ON c.id=p.comunidade_id
                         WHERE p.id<>" . $currentId . "
                           AND p.status='publicado'
                           AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                         ORDER BY COALESCE(p.publicado_em,p.created_at) DESC,p.id DESC
                         LIMIT " . $limit;

            $rows = $pdo->query($fallback)->fetchAll() ?: [];
        }

        return $rows;
    }

    /**
     * Ranking para blocos editoriais da Home.
     * Quando o histórico do período ainda está vazio, usa o total acumulado.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function popular(PDO $pdo, int $limit = 8, string $period = '30'): array
    {
        $limit = max(1, min(20, $limit));

        if (!class_exists('NewsAnalyticsService')) {
            return [];
        }

        $rows = NewsAnalyticsService::ranking($pdo, $period, $limit);

        if (!$rows && $period !== 'total') {
            $rows = NewsAnalyticsService::ranking($pdo, 'total', $limit);
        }

        return $rows;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<int,int> */
    private static function postCategoryIds(PDO $pdo, array $post): array
    {
        $ids = [];

        $direct = max(0, (int)($post['categoria_id'] ?? 0));
        if ($direct > 0) {
            $ids[$direct] = $direct;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT categoria_id
                 FROM post_categorias
                 WHERE post_id=?'
            );
            $stmt->execute([(int)$post['id']]);

            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        } catch (Throwable $e) {
            // Compatibilidade com instalações antigas sem tabela pivô.
        }

        return array_values($ids);
    }
}
