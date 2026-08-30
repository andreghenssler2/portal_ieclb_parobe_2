<?php

declare(strict_types=1);

/**
 * Portal IECLB Parobé v0.54.0
 *
 * Hierarquia ilimitada para menus administráveis.
 */
final class MenuHierarchyService
{
    /**
     * Monta uma árvore com profundidade livre.
     *
     * Itens órfãos são promovidos para a raiz para que não desapareçam.
     * Caso existam dados antigos com ciclo, o ciclo é quebrado durante
     * a montagem sem entrar em recursão infinita.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function buildTree(array $rows): array
    {
        $nodes = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $row['id'] = $id;
            $row['children'] = [];
            $nodes[$id] = $row;
        }

        if (!$nodes) {
            return [];
        }

        $ids = array_keys($nodes);

        usort(
            $ids,
            static function (int $a, int $b) use ($nodes): int {
                $orderA = (int)($nodes[$a]['ordem'] ?? 0);
                $orderB = (int)($nodes[$b]['ordem'] ?? 0);

                return $orderA <=> $orderB
                    ?: $a <=> $b;
            }
        );

        $childrenByParent = [];
        $roots = [];

        foreach ($ids as $id) {
            $parentId = (int)(
                $nodes[$id]['parent_id']
                ?? 0
            );

            if (
                $parentId <= 0
                || $parentId === $id
                || !isset($nodes[$parentId])
            ) {
                $roots[] = $id;
                continue;
            }

            $childrenByParent[$parentId][] = $id;
        }

        $processed = [];

        $walk = static function (
            int $id,
            array $branch
        ) use (
            &$walk,
            &$nodes,
            &$childrenByParent,
            &$processed
        ): ?array {
            if (
                isset($branch[$id])
                || isset($processed[$id])
                || !isset($nodes[$id])
            ) {
                return null;
            }

            $branch[$id] = true;
            $processed[$id] = true;

            $node = $nodes[$id];
            $node['children'] = [];

            foreach (
                $childrenByParent[$id]
                ?? []
                as $childId
            ) {
                $child = $walk(
                    (int)$childId,
                    $branch
                );

                if ($child !== null) {
                    $node['children'][] = $child;
                }
            }

            return $node;
        };

        $tree = [];

        foreach ($roots as $rootId) {
            $node = $walk(
                (int)$rootId,
                []
            );

            if ($node !== null) {
                $tree[] = $node;
            }
        }

        /*
         * Um ciclo puro não possui raiz. Os itens ainda não processados
         * são promovidos à raiz e a aresta que fecha o ciclo é ignorada.
         */
        foreach ($ids as $id) {
            if (isset($processed[$id])) {
                continue;
            }

            $node = $walk(
                (int)$id,
                []
            );

            if ($node !== null) {
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /**
     * Achata a árvore mantendo a profundidade em _depth.
     *
     * @param array<int,array<string,mixed>> $tree
     * @return array<int,array<string,mixed>>
     */
    public static function flatten(
        array $tree,
        int $depth = 0
    ): array {
        $out = [];

        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }

            $children = is_array(
                $node['children']
                ?? null
            )
                ? $node['children']
                : [];

            $row = $node;
            $row['_depth'] = max(
                0,
                $depth
            );
            unset($row['children']);

            $out[] = $row;

            if ($children) {
                array_push(
                    $out,
                    ...self::flatten(
                        $children,
                        $depth + 1
                    )
                );
            }
        }

        return $out;
    }

    /**
     * Lista todos os itens possíveis como pai, exceto o próprio item
     * em edição e todos os seus descendentes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parentOptions(
        PDO $pdo,
        int $menuId,
        ?int $editingId = null
    ): array {
        if ($menuId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT id,menu_id,parent_id,titulo,ordem,ativo
             FROM menu_itens
             WHERE menu_id=:menu_id
             ORDER BY ordem ASC,id ASC'
        );
        $stmt->execute([
            'menu_id' => $menuId,
        ]);

        $rows = $stmt->fetchAll() ?: [];

        $excluded = [];

        if (
            $editingId !== null
            && $editingId > 0
        ) {
            $excluded[$editingId] = true;

            foreach (
                self::descendantIds(
                    $rows,
                    $editingId
                )
                as $descendantId
            ) {
                $excluded[$descendantId] = true;
            }
        }

        $flat = self::flatten(
            self::buildTree($rows)
        );

        return array_values(
            array_filter(
                $flat,
                static fn(array $row): bool =>
                    !isset(
                        $excluded[
                            (int)($row['id'] ?? 0)
                        ]
                    )
            )
        );
    }

    /**
     * Valida a seleção de pai consultando todos os itens do mesmo menu.
     */
    public static function validateParent(
        PDO $pdo,
        int $menuId,
        ?int $itemId,
        ?int $parentId
    ): ?int {
        if (
            $parentId === null
            || $parentId <= 0
        ) {
            return null;
        }

        if ($menuId <= 0) {
            throw new RuntimeException(
                'Menu inválido.'
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id,menu_id,parent_id,titulo,ordem
             FROM menu_itens
             WHERE menu_id=:menu_id
             ORDER BY ordem ASC,id ASC'
        );
        $stmt->execute([
            'menu_id' => $menuId,
        ]);

        $rows = $stmt->fetchAll() ?: [];

        return self::validateParentRows(
            $rows,
            $itemId,
            $parentId
        );
    }

    /**
     * Parte pura da validação, separada para permitir teste seguro.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function validateParentRows(
        array $rows,
        ?int $itemId,
        ?int $parentId
    ): ?int {
        if (
            $parentId === null
            || $parentId <= 0
        ) {
            return null;
        }

        $parents = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $parents[$id] = (int)(
                $row['parent_id']
                ?? 0
            );
        }

        if (!isset($parents[$parentId])) {
            throw new RuntimeException(
                'O item pai selecionado é inválido.'
            );
        }

        if (
            $itemId !== null
            && $itemId > 0
            && $parentId === $itemId
        ) {
            throw new RuntimeException(
                'Um item não pode ser subitem dele mesmo.'
            );
        }

        $seen = [];
        $current = $parentId;

        while ($current > 0) {
            if (
                $itemId !== null
                && $itemId > 0
                && $current === $itemId
            ) {
                throw new RuntimeException(
                    'Não é possível mover o item para dentro de um de seus próprios subitens.'
                );
            }

            if (isset($seen[$current])) {
                throw new RuntimeException(
                    'A hierarquia selecionada contém um ciclo inválido.'
                );
            }

            $seen[$current] = true;

            $next = (int)(
                $parents[$current]
                ?? 0
            );

            if (
                $next > 0
                && !isset($parents[$next])
            ) {
                throw new RuntimeException(
                    'A hierarquia do item pai está inconsistente com o menu selecionado.'
                );
            }

            $current = $next;
        }

        return $parentId;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,int>
     */
    public static function descendantIds(
        array $rows,
        int $itemId
    ): array {
        if ($itemId <= 0) {
            return [];
        }

        $children = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $parentId = (int)(
                $row['parent_id']
                ?? 0
            );

            if (
                $id > 0
                && $parentId > 0
                && $id !== $parentId
            ) {
                $children[$parentId][] = $id;
            }
        }

        $out = [];
        $seen = [
            $itemId => true,
        ];
        $queue = $children[$itemId] ?? [];

        while ($queue) {
            $id = (int)array_shift($queue);

            if (
                $id <= 0
                || isset($seen[$id])
            ) {
                continue;
            }

            $seen[$id] = true;
            $out[] = $id;

            foreach (
                $children[$id]
                ?? []
                as $childId
            ) {
                $queue[] = (int)$childId;
            }
        }

        return $out;
    }
}
