<?php

declare(strict_types=1);

final class CategoryService
{
    /**
     * Garante a estrutura usada pelas categorias hierárquicas e pelas
     * múltiplas categorias de Posts. Idempotente por requisição.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableExists = static function (string $table) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND column_name=?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $indexExists = static function (string $table, string $index) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND index_name=?'
            );
            $stmt->execute([$table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$tableExists('posts') || !$tableExists('categorias')) {
            return;
        }

        if (!$columnExists('categorias', 'parent_id')) {
            $pdo->exec(
                'ALTER TABLE categorias
                 ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
            );
        }

        if (!$indexExists('categorias', 'idx_categorias_parent_id')) {
            $pdo->exec(
                'CREATE INDEX idx_categorias_parent_id
                 ON categorias (parent_id)'
            );
        }

        if (!$tableExists('post_categorias')) {
            $pdo->exec(
                "CREATE TABLE post_categorias (
                    post_id INT UNSIGNED NOT NULL,
                    categoria_id INT UNSIGNED NOT NULL,
                    principal TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (post_id,categoria_id),
                    KEY idx_post_categorias_categoria (categoria_id,post_id),
                    KEY idx_post_categorias_principal (post_id,principal),
                    CONSTRAINT fk_post_categorias_post
                        FOREIGN KEY (post_id) REFERENCES posts(id)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_post_categorias_categoria
                        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ($columnExists('posts', 'categoria_id')) {
            $pdo->exec(
                "INSERT IGNORE INTO post_categorias
                    (post_id,categoria_id,principal)
                 SELECT p.id,p.categoria_id,1
                 FROM posts p
                 INNER JOIN categorias c ON c.id=p.categoria_id
                 WHERE p.categoria_id IS NOT NULL
                   AND p.categoria_id>0"
            );
        }

        $ensured = true;
    }
    /**
     * Retorna as categorias de Posts em ordem hierárquica.
     * Cada item recebe a chave "depth" (0 = categoria raiz).
     */
    public static function tree(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT id, nome, slug, descricao, parent_id, created_at
             FROM categorias
             ORDER BY nome ASC'
        )->fetchAll();

        return self::flatten($rows);
    }

    /**
     * Ordena uma lista de categorias no formato árvore.
     * É tolerante a dados órfãos/cíclicos: itens não visitados são anexados ao final.
     */
    public static function flatten(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $row['id'] = (int)$row['id'];
            $row['parent_id'] = isset($row['parent_id']) && $row['parent_id'] !== null
                ? (int)$row['parent_id']
                : null;
            $byId[$row['id']] = $row;
        }

        $children = [];
        foreach ($byId as $row) {
            $parentId = $row['parent_id'];
            if ($parentId !== null && !isset($byId[$parentId])) {
                $parentId = null;
            }
            $children[$parentId ?? 0][] = $row['id'];
        }

        foreach ($children as &$ids) {
            usort($ids, static function (int $a, int $b) use ($byId): int {
                return strnatcasecmp((string)$byId[$a]['nome'], (string)$byId[$b]['nome']);
            });
        }
        unset($ids);

        $result = [];
        $visited = [];
        $walk = static function (int $id, int $depth) use (&$walk, &$result, &$visited, $byId, $children): void {
            if (isset($visited[$id]) || !isset($byId[$id])) {
                return;
            }
            $visited[$id] = true;
            $row = $byId[$id];
            $row['depth'] = $depth;
            $result[] = $row;

            foreach ($children[$id] ?? [] as $childId) {
                $walk((int)$childId, $depth + 1);
            }
        };

        foreach ($children[0] ?? [] as $rootId) {
            $walk((int)$rootId, 0);
        }

        // Segurança para bases antigas com algum ciclo ou referência inconsistente.
        foreach (array_keys($byId) as $id) {
            if (!isset($visited[$id])) {
                $walk((int)$id, 0);
            }
        }

        return $result;
    }

    /** Retorna IDs de todos os descendentes da categoria informada. */
    public static function descendantIds(PDO $pdo, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $rows = $pdo->query('SELECT id, parent_id FROM categorias')->fetchAll();
        $children = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
            $children[$parentId][] = (int)$row['id'];
        }

        $result = [];
        $seen = [];
        $stack = $children[$categoryId] ?? [];
        while ($stack) {
            $id = (int)array_pop($stack);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = (int)$childId;
            }
        }

        return $result;
    }

    /**
     * Valida uma categoria ascendente, impedindo auto-relacionamento e ciclos.
     */
    public static function validateParent(PDO $pdo, ?int $parentId, ?int $categoryId = null): ?int
    {
        if ($parentId === null || $parentId <= 0) {
            return null;
        }

        if ($categoryId !== null && $categoryId > 0 && $parentId === $categoryId) {
            throw new RuntimeException('Uma categoria não pode ser ascendente de si mesma.');
        }

        $stmt = $pdo->prepare('SELECT id FROM categorias WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $parentId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Categoria ascendente inválida.');
        }

        if ($categoryId !== null && $categoryId > 0) {
            $descendants = self::descendantIds($pdo, $categoryId);
            if (in_array($parentId, $descendants, true)) {
                throw new RuntimeException('Não é possível usar uma subcategoria como categoria ascendente, pois isso criaria um ciclo.');
            }
        }

        return $parentId;
    }

    /** Retorna as categorias associadas a uma notícia. */
    public static function postCategoryIds(PDO $pdo, int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT categoria_id FROM post_categorias WHERE post_id = :post_id ORDER BY principal DESC, categoria_id ASC'
            );
            $stmt->execute(['post_id' => $postId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                return array_values(array_unique($ids));
            }
        } catch (Throwable $ignored) {
            // Compatibilidade com base anterior à tabela post_categorias.
        }

        $stmt = $pdo->prepare('SELECT categoria_id FROM posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $postId]);
        $legacy = (int)($stmt->fetchColumn() ?: 0);
        return $legacy > 0 ? [$legacy] : [];
    }

    /**
     * Valida IDs de categorias existentes, preservando a ordem recebida.
     */
    public static function validIds(PDO $pdo, array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM categorias WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $existing = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        return array_values(array_filter($ids, static fn(int $id): bool => isset($existing[$id])));
    }

    /**
     * Sincroniza todas as categorias de uma notícia e mantém posts.categoria_id
     * como categoria principal para compatibilidade com código/integrações antigas.
     */
    public static function syncPostCategories(PDO $pdo, int $postId, array $categoryIds, ?int $primaryId = null): ?int
    {        self::ensureSchema($pdo);

        if ($postId <= 0) {
            throw new InvalidArgumentException('Post inválido para sincronizar categorias.');
        }

        $ids = self::validIds($pdo, $categoryIds);
        if ($primaryId === null || !in_array($primaryId, $ids, true)) {
            $primaryId = $ids[0] ?? null;
        }

        $pdo->prepare('DELETE FROM post_categorias WHERE post_id = :post_id')->execute(['post_id' => $postId]);

        if ($ids) {
            $insert = $pdo->prepare(
                'INSERT INTO post_categorias (post_id, categoria_id, principal) VALUES (:post_id, :categoria_id, :principal)'
            );
            foreach ($ids as $categoryId) {
                $insert->execute([
                    'post_id' => $postId,
                    'categoria_id' => $categoryId,
                    'principal' => $categoryId === $primaryId ? 1 : 0,
                ]);
            }
        }

        $stmt = $pdo->prepare('UPDATE posts SET categoria_id = :categoria_id WHERE id = :id');
        $stmt->bindValue(':categoria_id', $primaryId, $primaryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $postId, PDO::PARAM_INT);
        $stmt->execute();

        return $primaryId;
    }

    /** Retorna as categorias completas de uma notícia em ordem hierárquica. */
    public static function forPost(PDO $pdo, int $postId): array
    {
        $selected = array_fill_keys(self::postCategoryIds($pdo, $postId), true);
        if (!$selected) {
            return [];
        }
        return array_values(array_filter(
            self::tree($pdo),
            static fn(array $category): bool => isset($selected[(int)$category['id']])
        ));
    }

    /** Gera um rótulo visual para selects, ex.: "— — Eventos 1.1". */
    public static function optionLabel(array $category): string
    {
        $depth = max(0, (int)($category['depth'] ?? 0));
        return str_repeat('— ', $depth) . (string)($category['nome'] ?? '');
    }
}
