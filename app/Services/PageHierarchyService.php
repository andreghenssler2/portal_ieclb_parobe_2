<?php

declare(strict_types=1);

final class PageHierarchyService
{
    private static array $schemaReady = [];

    public static function ensureSchema(PDO $pdo): void
    {
        $key = spl_object_id($pdo);
        if (!empty(self::$schemaReady[$key])) {
            return;
        }

        if (!self::tableExists($pdo, 'paginas')) {
            return;
        }

        if (!self::columnExists($pdo, 'paginas', 'parent_id')) {
            $pdo->exec(
                'ALTER TABLE paginas
                 ADD COLUMN parent_id INT UNSIGNED NULL AFTER autor_id'
            );
        }

        if (!self::indexExists($pdo, 'paginas', 'idx_paginas_parent')) {
            $pdo->exec(
                'CREATE INDEX idx_paginas_parent
                 ON paginas (parent_id, ordem, id)'
            );
        }

        if (!self::constraintExists($pdo, 'paginas', 'fk_paginas_parent')) {
            try {
                $pdo->exec(
                    'ALTER TABLE paginas
                     ADD CONSTRAINT fk_paginas_parent
                     FOREIGN KEY (parent_id) REFERENCES paginas(id)
                     ON DELETE SET NULL
                     ON UPDATE CASCADE'
                );
            } catch (Throwable $ignored) {
                // A hierarquia funciona sem FK. O serviço continua prevenindo ciclos.
            }
        }

        self::$schemaReady[$key] = true;
    }

    public static function validateParent(PDO $pdo, ?int $pageId, ?int $parentId): ?int
    {
        self::ensureSchema($pdo);

        $parentId = (int)($parentId ?? 0);
        if ($parentId <= 0) {
            return null;
        }

        if ($pageId !== null && $pageId > 0 && $parentId === $pageId) {
            throw new InvalidArgumentException('Uma página não pode ser página superior dela mesma.');
        }

        $stmt = $pdo->prepare(
            "SELECT id,parent_id,status
             FROM paginas
             WHERE id=:id
             LIMIT 1"
        );
        $stmt->execute(['id' => $parentId]);
        $parent = $stmt->fetch();

        if (!$parent || (string)($parent['status'] ?? '') === 'lixeira') {
            throw new InvalidArgumentException('A página superior selecionada não existe ou está na Lixeira.');
        }

        if ($pageId !== null && $pageId > 0) {
            $visited = [];
            $cursor = $parent;

            while ($cursor) {
                $cursorId = (int)$cursor['id'];
                if ($cursorId === $pageId) {
                    throw new InvalidArgumentException(
                        'A hierarquia criaria um ciclo. Escolha outra página superior.'
                    );
                }

                if (isset($visited[$cursorId])) {
                    throw new RuntimeException('Foi detectado um ciclo na hierarquia de páginas.');
                }

                $visited[$cursorId] = true;
                $next = (int)($cursor['parent_id'] ?? 0);
                if ($next <= 0) {
                    break;
                }

                $stmt->execute(['id' => $next]);
                $cursor = $stmt->fetch() ?: null;
            }
        }

        return $parentId;
    }

    /** @return array<int,array<string,mixed>> */
    public static function options(PDO $pdo, ?int $excludeId = null): array
    {
        self::ensureSchema($pdo);

        $rows = $pdo->query(
            "SELECT id,parent_id,titulo,slug,status,ordem
             FROM paginas
             WHERE status<>'lixeira'
             ORDER BY ordem ASC,titulo ASC,id ASC"
        )->fetchAll() ?: [];

        $excluded = [];
        if ($excludeId !== null && $excludeId > 0) {
            $excluded[$excludeId] = true;
            foreach (self::descendantIdsFromRows($rows, $excludeId) as $id) {
                $excluded[$id] = true;
            }
        }

        $byParent = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (isset($excluded[$id])) {
                continue;
            }

            $parentId = (int)($row['parent_id'] ?? 0);
            if ($parentId > 0 && isset($excluded[$parentId])) {
                $parentId = 0;
            }

            $byParent[$parentId][] = $row;
        }

        $result = [];
        $walk = static function (int $parentId, int $depth) use (&$walk, &$result, $byParent): void {
            if ($depth > 20) return;

            foreach ($byParent[$parentId] ?? [] as $row) {
                $row['depth'] = $depth;
                $result[] = $row;
                $walk((int)$row['id'], $depth + 1);
            }
        };

        $walk(0, 0);

        // Páginas órfãs continuam disponíveis no seletor.
        $seen = array_fill_keys(
            array_map(static fn(array $r): int => (int)$r['id'], $result),
            true
        );
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($excluded[$id]) && !isset($seen[$id])) {
                $row['depth'] = 0;
                $result[] = $row;
            }
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public static function ancestors(PDO $pdo, int $pageId): array
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT id,parent_id,titulo,slug,status
             FROM paginas
             WHERE id=:id
             LIMIT 1'
        );
        $stmt->execute(['id' => $pageId]);
        $page = $stmt->fetch();

        if (!$page) {
            return [];
        }

        $out = [];
        $visited = [];
        $parentId = (int)($page['parent_id'] ?? 0);

        while ($parentId > 0 && count($out) < 20) {
            if (isset($visited[$parentId])) break;
            $visited[$parentId] = true;

            $stmt->execute(['id' => $parentId]);
            $parent = $stmt->fetch();
            if (!$parent) break;

            array_unshift($out, $parent);
            $parentId = (int)($parent['parent_id'] ?? 0);
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function publishedChildren(PDO $pdo, int $pageId): array
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT id,parent_id,titulo,slug,resumo,ordem
             FROM paginas
             WHERE parent_id=:parent_id
               AND status='publicado'
               AND (publicado_em IS NULL OR publicado_em<=NOW())
             ORDER BY ordem ASC,titulo ASC,id ASC"
        );
        $stmt->execute(['parent_id' => $pageId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function urlBySlug(PDO $pdo, string $slug): string
    {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM paginas
             WHERE slug=:slug
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $id = (int)($stmt->fetchColumn() ?: 0);

        if ($id <= 0) {
            return self::fallbackUrl($pdo, [$slug]);
        }

        return self::urlById($pdo, $id);
    }

    public static function urlById(PDO $pdo, int $pageId): string
    {
        self::ensureSchema($pdo);

        $segments = self::pathSegmentsById($pdo, $pageId);
        if (!$segments) {
            return url();
        }

        return self::fallbackUrl($pdo, $segments);
    }

    /** @return array<int,string> */
    public static function requestSegments(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $path = trim(currentRelativePath(), '/');
        if ($path === '') {
            return [];
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn($value): bool => trim((string)$value) !== ''
        ));

        $prefix = permalinkPrefix('pagina', $pdo);

        if ($segments) {
            $first = strtolower(rawurldecode((string)$segments[0]));
            if (($prefix !== '' && $first === strtolower($prefix)) || $first === 'pagina') {
                array_shift($segments);
            } elseif ($prefix !== '') {
                return [];
            }
        }

        $out = [];
        foreach ($segments as $segment) {
            $slug = strtolower(rawurldecode((string)$segment));
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                return [];
            }
            $out[] = $slug;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function findPublishedByRequest(PDO $pdo): ?array
    {
        self::ensureSchema($pdo);

        $segments = self::requestSegments($pdo);
        if (!$segments) {
            return null;
        }

        $select = "SELECT p.*,u.nome AS autor_nome,
                          m.caminho AS imagem_capa_midia,
                          m.alt_text AS imagem_capa_alt,
                          m.largura AS imagem_capa_largura,
                          m.altura AS imagem_capa_altura,
                          m.mime_type AS imagem_capa_mime
                   FROM paginas p
                   LEFT JOIN usuarios u ON u.id=p.autor_id
                   LEFT JOIN midias m ON m.id=p.imagem_capa_id";

        $parentId = null;
        $page = null;

        foreach ($segments as $slug) {
            if ($parentId === null) {
                $sql = $select . "
                    WHERE p.slug=:slug
                      AND p.parent_id IS NULL
                      AND p.status='publicado'
                      AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                    LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['slug' => $slug]);
            } else {
                $sql = $select . "
                    WHERE p.slug=:slug
                      AND p.parent_id=:parent_id
                      AND p.status='publicado'
                      AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                    LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'slug' => $slug,
                    'parent_id' => $parentId,
                ]);
            }

            $page = $stmt->fetch() ?: null;
            if (!$page) {
                break;
            }

            $parentId = (int)$page['id'];
        }

        if ($page && (int)$page['id'] === $parentId) {
            return $page;
        }

        // Compatibilidade: /pagina/slug antigo continua encontrando uma
        // subpágina e será redirecionado para o caminho hierárquico canônico.
        if (count($segments) === 1) {
            $stmt = $pdo->prepare(
                $select . "
                 WHERE p.slug=:slug
                   AND p.status='publicado'
                   AND (p.publicado_em IS NULL OR p.publicado_em<=NOW())
                 LIMIT 1"
            );
            $stmt->execute(['slug' => $segments[0]]);
            $legacy = $stmt->fetch();
            return $legacy ?: null;
        }

        return null;
    }

    /** @return array<int,string> */
    private static function pathSegmentsById(PDO $pdo, int $pageId): array
    {
        if ($pageId <= 0) return [];

        $stmt = $pdo->prepare(
            'SELECT id,parent_id,slug
             FROM paginas
             WHERE id=:id
             LIMIT 1'
        );

        $segments = [];
        $visited = [];
        $cursor = $pageId;

        while ($cursor > 0 && count($segments) < 20) {
            if (isset($visited[$cursor])) {
                throw new RuntimeException('Ciclo detectado na hierarquia de páginas.');
            }
            $visited[$cursor] = true;

            $stmt->execute(['id' => $cursor]);
            $page = $stmt->fetch();
            if (!$page) break;

            $slug = trim((string)$page['slug']);
            if ($slug === '') break;

            array_unshift($segments, $slug);
            $cursor = (int)($page['parent_id'] ?? 0);
        }

        return $segments;
    }

    private static function fallbackUrl(PDO $pdo, array $segments): string
    {
        $prefix = permalinkPrefix('pagina', $pdo);
        $encoded = array_map('rawurlencode', $segments);

        if ($prefix !== '') {
            array_unshift($encoded, rawurlencode($prefix));
        }

        return url(implode('/', $encoded));
    }

    /** @return array<int,int> */
    private static function descendantIdsFromRows(array $rows, int $rootId): array
    {
        $children = [];
        foreach ($rows as $row) {
            $parent = (int)($row['parent_id'] ?? 0);
            $children[$parent][] = (int)$row['id'];
        }

        $result = [];
        $queue = $children[$rootId] ?? [];
        $seen = [];

        while ($queue) {
            $id = array_shift($queue);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $result[] = $id;

            foreach ($children[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $result;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name=?
               AND column_name=?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema=DATABASE()
               AND table_name=?
               AND index_name=?'
        );
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema=DATABASE()
               AND table_name=?
               AND constraint_name=?'
        );
        $stmt->execute([$table, $constraint]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
