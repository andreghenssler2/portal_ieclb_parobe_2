<?php

declare(strict_types=1);

final class DocumentService
{
    public const ADMIN_PER_PAGE = 50;
    public const PUBLIC_PER_PAGE = 30;

    public static function categories(PDO $pdo, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM documento_categorias';
        if ($onlyActive) {
            $sql .= ' WHERE ativo=1';
        }
        $sql .= ' ORDER BY ordem ASC, nome ASC, id ASC';
        return $pdo->query($sql)->fetchAll() ?: [];
    }

    public static function mediaChoices(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT id,nome_original,titulo,caminho,mime_type,extensao,tamanho
             FROM midias
             WHERE mime_type NOT LIKE 'image/%'
             ORDER BY id DESC"
        );
        return $stmt->fetchAll() ?: [];
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT d.*, dc.nome AS categoria_nome, dc.slug AS categoria_slug,
                    m.nome_original, m.titulo AS midia_titulo, m.caminho, m.mime_type,
                    m.extensao, m.tamanho, u.nome AS autor_nome
             FROM documentos d
             LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id
             LEFT JOIN midias m ON m.id=d.midia_id
             LEFT JOIN usuarios u ON u.id=d.autor_id
             WHERE d.id=:id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findPublishedBySlug(PDO $pdo, string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT d.*, dc.nome AS categoria_nome, dc.slug AS categoria_slug,
                    m.nome_original, m.titulo AS midia_titulo, m.caminho, m.mime_type,
                    m.extensao, m.tamanho, u.nome AS autor_nome
             FROM documentos d
             LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id
             LEFT JOIN midias m ON m.id=d.midia_id
             LEFT JOIN usuarios u ON u.id=d.autor_id
             WHERE d.slug=:slug
               AND d.status='publicado'
               AND (d.publicado_em IS NULL OR d.publicado_em<=NOW())
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function save(PDO $pdo, array $data, int $userId): int
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $title = trim((string)($data['titulo'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Informe o título do documento.');
        }

        $mediaId = (int)($data['midia_id'] ?? 0);
        if ($mediaId <= 0) {
            throw new InvalidArgumentException('Selecione um arquivo da Biblioteca de Mídia.');
        }
        $mediaStmt = $pdo->prepare("SELECT id,mime_type FROM midias WHERE id=:id LIMIT 1");
        $mediaStmt->execute(['id' => $mediaId]);
        $media = $mediaStmt->fetch();
        if (!$media) {
            throw new RuntimeException('O arquivo selecionado não existe mais na Biblioteca de Mídia.');
        }
        if (str_starts_with((string)($media['mime_type'] ?? ''), 'image/')) {
            throw new InvalidArgumentException('Selecione um documento ou arquivo, não uma imagem.');
        }

        $categoryId = (int)($data['categoria_id'] ?? 0);
        if ($categoryId > 0) {
            $categoryStmt = $pdo->prepare('SELECT id FROM documento_categorias WHERE id=:id LIMIT 1');
            $categoryStmt->execute(['id' => $categoryId]);
            if (!$categoryStmt->fetchColumn()) {
                throw new InvalidArgumentException('Categoria de documento inválida.');
            }
        } else {
            $categoryId = 0;
        }

        $status = strtolower(trim((string)($data['status'] ?? 'rascunho')));
        if (!in_array($status, ['rascunho', 'publicado', 'arquivado'], true)) {
            $status = 'rascunho';
        }

        $slugInput = trim((string)($data['slug'] ?? ''));
        $slug = uniqueSlug($pdo, 'documentos', $slugInput !== '' ? $slugInput : $title, $id > 0 ? $id : null);

        $publishedAt = null;
        if ($status === 'publicado') {
            $raw = trim((string)($data['publicado_em'] ?? ''));
            if ($raw !== '') {
                try {
                    $publishedAt = (new DateTimeImmutable(str_replace('T', ' ', $raw)))->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('A data de publicação é inválida.');
                }
            } else {
                $publishedAt = date('Y-m-d H:i:s');
            }
        }

        $payload = [
            'autor_id' => $userId > 0 ? $userId : null,
            'categoria_id' => $categoryId > 0 ? $categoryId : null,
            'midia_id' => $mediaId,
            'titulo' => self::cut($title, 220),
            'slug' => self::cut($slug, 240),
            'descricao' => trim((string)($data['descricao'] ?? '')) ?: null,
            'status' => $status,
            'ordem' => max(-99999, min(99999, (int)($data['ordem'] ?? 0))),
            'publicado_em' => $publishedAt,
            'seo_titulo' => self::nullableCut((string)($data['seo_titulo'] ?? ''), 220),
            'seo_descricao' => self::nullableCut((string)($data['seo_descricao'] ?? ''), 320),
            'seo_noindex' => !empty($data['seo_noindex']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!self::find($pdo, $id)) {
                throw new RuntimeException('Documento não encontrado.');
            }
            $payload['id'] = $id;
            $stmt = $pdo->prepare(
                "UPDATE documentos SET
                    autor_id=:autor_id,categoria_id=:categoria_id,midia_id=:midia_id,
                    titulo=:titulo,slug=:slug,descricao=:descricao,status=:status,
                    ordem=:ordem,publicado_em=:publicado_em,seo_titulo=:seo_titulo,
                    seo_descricao=:seo_descricao,seo_noindex=:seo_noindex,updated_at=NOW()
                 WHERE id=:id"
            );
            $stmt->execute($payload);
            self::invalidateCache('documento.editar');
            return $id;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO documentos
                (autor_id,categoria_id,midia_id,titulo,slug,descricao,status,ordem,
                 publicado_em,seo_titulo,seo_descricao,seo_noindex,downloads,created_at,updated_at)
             VALUES
                (:autor_id,:categoria_id,:midia_id,:titulo,:slug,:descricao,:status,:ordem,
                 :publicado_em,:seo_titulo,:seo_descricao,:seo_noindex,0,NOW(),NOW())"
        );
        $stmt->execute($payload);
        $id = (int)$pdo->lastInsertId();
        self::invalidateCache('documento.criar');
        return $id;
    }

    public static function delete(PDO $pdo, int $id): bool
    {
        $row = self::find($pdo, $id);
        if (!$row) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM documentos WHERE id=:id');
        $stmt->execute(['id' => $id]);
        self::invalidateCache('documento.excluir');
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{items:array,total:int,page:int,pages:int,from:int,to:int}
     */
    public static function adminList(
        PDO $pdo,
        string $query = '',
        string $status = '',
        int $categoryId = 0,
        int $page = 1,
        int $perPage = self::ADMIN_PER_PAGE
    ): array {
        $perPage = max(1, min(200, $perPage));
        $where = [];
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(d.titulo LIKE :q OR d.slug LIKE :q OR d.descricao LIKE :q OR m.nome_original LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        if (in_array($status, ['rascunho', 'publicado', 'arquivado'], true)) {
            $where[] = 'd.status=:status';
            $params['status'] = $status;
        }
        if ($categoryId > 0) {
            $where[] = 'd.categoria_id=:categoria_id';
            $params['categoria_id'] = $categoryId;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM documentos d LEFT JOIN midias m ON m.id=d.midia_id' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT d.*, dc.nome AS categoria_nome, m.nome_original, m.extensao, m.tamanho, u.nome AS autor_nome
                FROM documentos d
                LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id
                LEFT JOIN midias m ON m.id=d.midia_id
                LEFT JOIN usuarios u ON u.id=d.autor_id
                {$whereSql}
                ORDER BY d.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => $total > 0 ? min($offset + count($items), $total) : 0,
        ];
    }

    /**
     * @return array{items:array,total:int,page:int,pages:int,from:int,to:int}
     */
    public static function publicList(
        PDO $pdo,
        string $query = '',
        string $categorySlug = '',
        int $page = 1,
        int $perPage = self::PUBLIC_PER_PAGE
    ): array {
        $perPage = max(1, min(100, $perPage));
        $where = ["d.status='publicado'", '(d.publicado_em IS NULL OR d.publicado_em<=NOW())'];
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(d.titulo LIKE :q OR d.descricao LIKE :q OR m.nome_original LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $categorySlug = strtolower(trim($categorySlug));
        if ($categorySlug !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $categorySlug)) {
            $where[] = 'dc.slug=:categoria_slug';
            $params['categoria_slug'] = $categorySlug;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM documentos d
             LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id
             LEFT JOIN midias m ON m.id=d.midia_id
             {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT d.*, dc.nome AS categoria_nome, dc.slug AS categoria_slug,
                       m.nome_original,m.caminho,m.mime_type,m.extensao,m.tamanho
                FROM documentos d
                LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id
                LEFT JOIN midias m ON m.id=d.midia_id
                {$whereSql}
                ORDER BY d.ordem ASC, COALESCE(d.publicado_em,d.created_at) DESC, d.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => $total > 0 ? min($offset + count($items), $total) : 0,
        ];
    }

    public static function incrementDownload(PDO $pdo, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $stmt = $pdo->prepare('UPDATE documentos SET downloads=downloads+1 WHERE id=:id');
            $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            // A contagem não deve impedir o download.
        }
    }

    public static function downloadUrl(string $slug): string
    {
        return rtrim(contentUrl('documento', $slug), '/') . '/baixar';
    }

    public static function fileLabel(array $row): string
    {
        $ext = strtoupper(trim((string)($row['extensao'] ?? '')));
        return $ext !== '' ? $ext : 'ARQUIVO';
    }

    public static function formatPublished(?string $value): string
    {
        return $value ? formatDateOnlyBr($value) : '';
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function nullableCut(string $value, int $length): ?string
    {
        $value = trim($value);
        return $value === '' ? null : self::cut($value, $length);
    }

    private static function invalidateCache(string $action): void
    {
        try {
            if (class_exists('CacheService')) {
                CacheService::invalidateForAction($action, 'documentos');
            }
        } catch (Throwable $e) {
        }
    }
}
