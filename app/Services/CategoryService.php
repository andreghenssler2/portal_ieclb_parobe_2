<?php

declare(strict_types=1);

final class CategoryService
{
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

    /** Gera um rótulo visual para selects, ex.: "— — Eventos 1.1". */
    public static function optionLabel(array $category): string
    {
        $depth = max(0, (int)($category['depth'] ?? 0));
        return str_repeat('— ', $depth) . (string)($category['nome'] ?? '');
    }
}
