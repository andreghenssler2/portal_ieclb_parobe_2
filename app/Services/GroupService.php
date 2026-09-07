<?php

declare(strict_types=1);

final class GroupService
{
    public const ADMIN_PER_PAGE = 50;

    public static function typeLabels(): array
    {
        return [
            'grupo' => 'Grupo',
            'ministerio' => 'Ministério',
            'comissao' => 'Comissão',
            'projeto' => 'Projeto',
            'outro' => 'Outro',
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return self::typeLabels()[(string)$type] ?? 'Grupo';
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT g.*,
                    c.nome AS comunidade_nome,
                    m.caminho AS imagem_caminho,
                    m.titulo AS imagem_titulo,
                    m.alt_text AS imagem_alt,
                    m.nome_original AS imagem_nome
             FROM grupos g
             LEFT JOIN comunidades c ON c.id = g.comunidade_id
             LEFT JOIN midias m ON m.id = g.imagem_id
             WHERE g.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function communities(PDO $pdo): array
    {
        try {
            return $pdo->query(
                'SELECT id, nome, slug
                 FROM comunidades
                 WHERE ativa = 1
                 ORDER BY ordem ASC, nome ASC'
            )->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{items:array,total:int,page:int,pages:int,from:int,to:int}
     */
    public static function adminList(
        PDO $pdo,
        string $q,
        string $type,
        int $communityId,
        string $status,
        int $page
    ): array {
        $where = [];
        $params = [];

        $q = trim($q);
        if ($q !== '') {
            $where[] = '(g.nome LIKE :q
                OR g.resumo LIKE :q
                OR g.descricao LIKE :q
                OR g.responsavel LIKE :q
                OR g.local LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        if ($type !== '' && isset(self::typeLabels()[$type])) {
            $where[] = 'g.tipo = :tipo';
            $params['tipo'] = $type;
        }

        if ($communityId > 0) {
            $where[] = 'g.comunidade_id = :comunidade_id';
            $params['comunidade_id'] = $communityId;
        }

        if ($status === 'ativos') {
            $where[] = 'g.ativo = 1';
        } elseif ($status === 'inativos') {
            $where[] = 'g.ativo = 0';
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM grupos g' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = max(1, (int)ceil($total / self::ADMIN_PER_PAGE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::ADMIN_PER_PAGE;

        $sql = "SELECT g.*,
                       c.nome AS comunidade_nome,
                       m.caminho AS imagem_caminho
                FROM grupos g
                LEFT JOIN comunidades c ON c.id = g.comunidade_id
                LEFT JOIN midias m ON m.id = g.imagem_id
                {$whereSql}
                ORDER BY g.ordem ASC, g.nome ASC, g.id ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', self::ADMIN_PER_PAGE, PDO::PARAM_INT);
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

    public static function save(PDO $pdo, array $data, int $userId): int
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $name = trim((string)($data['nome'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Informe o nome do grupo ou ministério.');
        }

        $type = strtolower(trim((string)($data['tipo'] ?? 'grupo')));
        if (!isset(self::typeLabels()[$type])) {
            $type = 'grupo';
        }

        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }

        $communityId = max(0, (int)($data['comunidade_id'] ?? 0));
        if ($communityId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM comunidades WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $communityId]);

            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Comunidade inválida.');
            }
        }

        $imageId = max(0, (int)($data['imagem_id'] ?? 0));
        if ($imageId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM midias
                 WHERE id = :id
                   AND mime_type LIKE 'image/%'
                 LIMIT 1"
            );
            $stmt->execute(['id' => $imageId]);

            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('A imagem selecionada é inválida.');
            }
        }

        $slugInput = trim((string)($data['slug'] ?? ''));
        $slug = uniqueSlug(
            $pdo,
            'grupos',
            $slugInput !== '' ? $slugInput : $name,
            $id > 0 ? $id : null
        );

        $payload = [
            'autor_id' => $userId > 0 ? $userId : null,
            'comunidade_id' => $communityId > 0 ? $communityId : null,
            'imagem_id' => $imageId > 0 ? $imageId : null,
            'nome' => self::cut($name, 180),
            'slug' => self::cut($slug, 220),
            'tipo' => $type,
            'resumo' => self::nullableCut((string)($data['resumo'] ?? ''), 500),
            'descricao' => trim((string)($data['descricao'] ?? '')) ?: null,
            'responsavel' => self::nullableCut((string)($data['responsavel'] ?? ''), 180),
            'email' => $email !== '' ? self::cut($email, 190) : null,
            'telefone' => self::nullableCut((string)($data['telefone'] ?? ''), 40),
            'whatsapp' => self::nullableCut((string)($data['whatsapp'] ?? ''), 40),
            'local' => self::nullableCut((string)($data['local'] ?? ''), 255),
            'dia_semana' => self::nullableCut((string)($data['dia_semana'] ?? ''), 40),
            'horario' => self::nullableCut((string)($data['horario'] ?? ''), 80),
            'instagram' => self::safeUrl((string)($data['instagram'] ?? '')),
            'facebook' => self::safeUrl((string)($data['facebook'] ?? '')),
            'ativo' => !empty($data['ativo']) ? 1 : 0,
            'ordem' => max(-99999, min(99999, (int)($data['ordem'] ?? 0))),
            'seo_titulo' => self::nullableCut((string)($data['seo_titulo'] ?? ''), 220),
            'seo_descricao' => self::nullableCut((string)($data['seo_descricao'] ?? ''), 320),
            'seo_noindex' => !empty($data['seo_noindex']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!self::find($pdo, $id)) {
                throw new RuntimeException('Grupo / Ministério não encontrado.');
            }

            $payload['id'] = $id;

            $stmt = $pdo->prepare(
                "UPDATE grupos SET
                    autor_id = :autor_id,
                    comunidade_id = :comunidade_id,
                    imagem_id = :imagem_id,
                    nome = :nome,
                    slug = :slug,
                    tipo = :tipo,
                    resumo = :resumo,
                    descricao = :descricao,
                    responsavel = :responsavel,
                    email = :email,
                    telefone = :telefone,
                    whatsapp = :whatsapp,
                    local = :local,
                    dia_semana = :dia_semana,
                    horario = :horario,
                    instagram = :instagram,
                    facebook = :facebook,
                    ativo = :ativo,
                    ordem = :ordem,
                    seo_titulo = :seo_titulo,
                    seo_descricao = :seo_descricao,
                    seo_noindex = :seo_noindex,
                    updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute($payload);

            return $id;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO grupos
                (autor_id, comunidade_id, imagem_id, nome, slug, tipo,
                 resumo, descricao, responsavel, email, telefone, whatsapp,
                 local, dia_semana, horario, instagram, facebook,
                 ativo, ordem, seo_titulo, seo_descricao, seo_noindex,
                 created_at, updated_at)
             VALUES
                (:autor_id, :comunidade_id, :imagem_id, :nome, :slug, :tipo,
                 :resumo, :descricao, :responsavel, :email, :telefone, :whatsapp,
                 :local, :dia_semana, :horario, :instagram, :facebook,
                 :ativo, :ordem, :seo_titulo, :seo_descricao, :seo_noindex,
                 NOW(), NOW())"
        );
        $stmt->execute($payload);

        return (int)$pdo->lastInsertId();
    }

    public static function delete(PDO $pdo, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $pdo->beginTransaction();

        try {
            if (
                self::tableExists($pdo, 'liderancas')
                && self::columnExists($pdo, 'liderancas', 'grupo_id')
            ) {
                $stmt = $pdo->prepare(
                    'UPDATE liderancas
                     SET grupo_id = NULL
                     WHERE grupo_id = :id'
                );
                $stmt->execute(['id' => $id]);
            }

            $stmt = $pdo->prepare(
                'DELETE FROM grupos WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);
            $deleted = $stmt->rowCount() > 0;

            $pdo->commit();

            return $deleted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function columnExists(
        PDO $pdo,
        string $table,
        string $column
    ): bool {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND column_name = :column'
            );
            $stmt->execute([
                'table' => $table,
                'column' => $column,
            ]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function safeUrl(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Informe uma URL válida para as redes sociais.');
        }

        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Use somente URLs http ou https.');
        }

        return self::cut($value, 500);
    }

    private static function cut(string $value, int $length): string
    {
        $value = trim($value);

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }

    private static function nullableCut(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : self::cut($value, $length);
    }
}
