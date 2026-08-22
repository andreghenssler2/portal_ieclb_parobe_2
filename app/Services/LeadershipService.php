<?php

declare(strict_types=1);

final class LeadershipService
{
    public const ADMIN_PER_PAGE = 50;
    public const PUBLIC_PER_PAGE = 24;

    public static function typeLabels(): array
    {
        return [
            'pastoral' => 'Ministério Pastoral',
            'presbiterio' => 'Presbitério',
            'lideranca' => 'Liderança',
            'equipe' => 'Equipe',
            'outro' => 'Outro',
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return self::typeLabels()[(string)$type] ?? 'Liderança';
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;

        $groupJoin = self::tableExists($pdo, 'grupos')
            ? 'LEFT JOIN grupos g ON g.id=l.grupo_id'
            : '';
        $groupSelect = self::tableExists($pdo, 'grupos')
            ? ', g.nome AS grupo_nome, g.slug AS grupo_slug'
            : ', NULL AS grupo_nome, NULL AS grupo_slug';

        $stmt = $pdo->prepare(
            "SELECT l.*, m.caminho AS foto_caminho, m.titulo AS foto_titulo,
                    m.alt_text AS foto_alt, m.nome_original AS foto_nome,
                    c.nome AS comunidade_nome, c.slug AS comunidade_slug
                    {$groupSelect}
             FROM liderancas l
             LEFT JOIN midias m ON m.id=l.foto_id
             LEFT JOIN comunidades c ON c.id=l.comunidade_id
             {$groupJoin}
             WHERE l.id=:id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findActiveBySlug(PDO $pdo, string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return null;

        $groupJoin = self::tableExists($pdo, 'grupos')
            ? 'LEFT JOIN grupos g ON g.id=l.grupo_id'
            : '';
        $groupSelect = self::tableExists($pdo, 'grupos')
            ? ', g.nome AS grupo_nome, g.slug AS grupo_slug'
            : ', NULL AS grupo_nome, NULL AS grupo_slug';

        $stmt = $pdo->prepare(
            "SELECT l.*, m.caminho AS foto_caminho, m.titulo AS foto_titulo,
                    m.alt_text AS foto_alt, m.nome_original AS foto_nome,
                    c.nome AS comunidade_nome, c.slug AS comunidade_slug
                    {$groupSelect}
             FROM liderancas l
             LEFT JOIN midias m ON m.id=l.foto_id
             LEFT JOIN comunidades c ON c.id=l.comunidade_id
             {$groupJoin}
             WHERE l.slug=:slug AND l.ativo=1
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function communities(PDO $pdo): array
    {
        try {
            return $pdo->query('SELECT id,nome,slug FROM comunidades WHERE ativa=1 ORDER BY ordem ASC,nome ASC')->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function groups(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'grupos')) return [];
        try {
            $columns = self::columns($pdo, 'grupos');
            $activeWhere = in_array('ativo', $columns, true) ? ' WHERE ativo=1' : '';
            return $pdo->query("SELECT id,nome,slug FROM grupos{$activeWhere} ORDER BY ordem ASC,nome ASC")->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function imageChoices(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT id,caminho,titulo,alt_text,nome_original,largura,altura
                 FROM midias
                 WHERE mime_type LIKE 'image/%'
                 ORDER BY id DESC"
            )->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function save(PDO $pdo, array $data, int $userId): int
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $name = trim((string)($data['nome'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Informe o nome da pessoa.');

        $type = strtolower(trim((string)($data['tipo'] ?? 'lideranca')));
        if (!isset(self::typeLabels()[$type])) $type = 'lideranca';

        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }

        $photoId = (int)($data['foto_id'] ?? 0);
        if ($photoId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM midias WHERE id=:id AND mime_type LIKE 'image/%' LIMIT 1");
            $stmt->execute(['id' => $photoId]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('A foto selecionada é inválida.');
        } else {
            $photoId = 0;
        }

        $communityId = (int)($data['comunidade_id'] ?? 0);
        if ($communityId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM comunidades WHERE id=:id LIMIT 1');
            $stmt->execute(['id' => $communityId]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Comunidade inválida.');
        } else {
            $communityId = 0;
        }

        $groupId = (int)($data['grupo_id'] ?? 0);
        if ($groupId > 0) {
            if (!self::tableExists($pdo, 'grupos')) {
                $groupId = 0;
            } else {
                $stmt = $pdo->prepare('SELECT id FROM grupos WHERE id=:id LIMIT 1');
                $stmt->execute(['id' => $groupId]);
                if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Grupo / Ministério inválido.');
            }
        }

        $slugInput = trim((string)($data['slug'] ?? ''));
        $slug = uniqueSlug($pdo, 'liderancas', $slugInput !== '' ? $slugInput : $name, $id > 0 ? $id : null);

        $instagram = self::safeUrl((string)($data['instagram'] ?? ''));
        $facebook = self::safeUrl((string)($data['facebook'] ?? ''));

        $payload = [
            'autor_id' => $userId > 0 ? $userId : null,
            'foto_id' => $photoId > 0 ? $photoId : null,
            'comunidade_id' => $communityId > 0 ? $communityId : null,
            'grupo_id' => $groupId > 0 ? $groupId : null,
            'nome' => self::cut($name, 180),
            'slug' => self::cut($slug, 220),
            'tipo' => $type,
            'funcao' => self::nullableCut((string)($data['funcao'] ?? ''), 180),
            'resumo' => self::nullableCut((string)($data['resumo'] ?? ''), 500),
            'biografia' => trim((string)($data['biografia'] ?? '')) ?: null,
            'email' => $email !== '' ? self::cut($email, 190) : null,
            'telefone' => self::nullableCut((string)($data['telefone'] ?? ''), 40),
            'whatsapp' => self::nullableCut((string)($data['whatsapp'] ?? ''), 40),
            'instagram' => $instagram,
            'facebook' => $facebook,
            'exibir_email' => !empty($data['exibir_email']) ? 1 : 0,
            'exibir_telefone' => !empty($data['exibir_telefone']) ? 1 : 0,
            'exibir_whatsapp' => !empty($data['exibir_whatsapp']) ? 1 : 0,
            'ativo' => !empty($data['ativo']) ? 1 : 0,
            'ordem' => max(-99999, min(99999, (int)($data['ordem'] ?? 0))),
            'seo_titulo' => self::nullableCut((string)($data['seo_titulo'] ?? ''), 220),
            'seo_descricao' => self::nullableCut((string)($data['seo_descricao'] ?? ''), 320),
            'seo_noindex' => !empty($data['seo_noindex']) ? 1 : 0,
        ];

        if ($id > 0) {
            if (!self::find($pdo, $id)) throw new RuntimeException('Perfil de liderança não encontrado.');
            $payload['id'] = $id;
            $stmt = $pdo->prepare(
                "UPDATE liderancas SET
                    autor_id=:autor_id,foto_id=:foto_id,comunidade_id=:comunidade_id,grupo_id=:grupo_id,
                    nome=:nome,slug=:slug,tipo=:tipo,funcao=:funcao,resumo=:resumo,biografia=:biografia,
                    email=:email,telefone=:telefone,whatsapp=:whatsapp,instagram=:instagram,facebook=:facebook,
                    exibir_email=:exibir_email,exibir_telefone=:exibir_telefone,exibir_whatsapp=:exibir_whatsapp,
                    ativo=:ativo,ordem=:ordem,seo_titulo=:seo_titulo,seo_descricao=:seo_descricao,
                    seo_noindex=:seo_noindex,updated_at=NOW()
                 WHERE id=:id"
            );
            $stmt->execute($payload);
            return $id;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO liderancas
                (autor_id,foto_id,comunidade_id,grupo_id,nome,slug,tipo,funcao,resumo,biografia,
                 email,telefone,whatsapp,instagram,facebook,exibir_email,exibir_telefone,
                 exibir_whatsapp,ativo,ordem,seo_titulo,seo_descricao,seo_noindex,created_at,updated_at)
             VALUES
                (:autor_id,:foto_id,:comunidade_id,:grupo_id,:nome,:slug,:tipo,:funcao,:resumo,:biografia,
                 :email,:telefone,:whatsapp,:instagram,:facebook,:exibir_email,:exibir_telefone,
                 :exibir_whatsapp,:ativo,:ordem,:seo_titulo,:seo_descricao,:seo_noindex,NOW(),NOW())"
        );
        $stmt->execute($payload);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(PDO $pdo, int $id): bool
    {
        if ($id <= 0) return false;
        $stmt = $pdo->prepare('DELETE FROM liderancas WHERE id=:id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{items:array,total:int,page:int,pages:int,from:int,to:int}
     */
    public static function adminList(PDO $pdo, string $q, string $type, int $communityId, string $status, int $page): array
    {
        $where = [];
        $params = [];

        $q = trim($q);
        if ($q !== '') {
            $where[] = '(l.nome LIKE :q OR l.funcao LIKE :q OR l.resumo LIKE :q OR l.biografia LIKE :q OR l.email LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($type !== '' && isset(self::typeLabels()[$type])) {
            $where[] = 'l.tipo=:tipo';
            $params['tipo'] = $type;
        }
        if ($communityId > 0) {
            $where[] = 'l.comunidade_id=:comunidade_id';
            $params['comunidade_id'] = $communityId;
        }
        if ($status === 'ativos') {
            $where[] = 'l.ativo=1';
        } elseif ($status === 'inativos') {
            $where[] = 'l.ativo=0';
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $pdo->prepare('SELECT COUNT(*) FROM liderancas l' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / self::ADMIN_PER_PAGE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::ADMIN_PER_PAGE;

        $groupJoin = self::tableExists($pdo, 'grupos') ? 'LEFT JOIN grupos g ON g.id=l.grupo_id' : '';
        $groupSelect = self::tableExists($pdo, 'grupos') ? ',g.nome AS grupo_nome' : ',NULL AS grupo_nome';

        $sql = "SELECT l.*,m.caminho AS foto_caminho,c.nome AS comunidade_nome {$groupSelect}
                FROM liderancas l
                LEFT JOIN midias m ON m.id=l.foto_id
                LEFT JOIN comunidades c ON c.id=l.comunidade_id
                {$groupJoin}
                {$whereSql}
                ORDER BY l.ordem ASC,l.nome ASC,l.id ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
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

    /**
     * @return array{items:array,total:int,page:int,pages:int,from:int,to:int}
     */
    public static function publicList(PDO $pdo, string $q, string $type, int $communityId, int $page): array
    {
        $where = ['l.ativo=1'];
        $params = [];

        $q = trim($q);
        if ($q !== '') {
            $where[] = '(l.nome LIKE :q OR l.funcao LIKE :q OR l.resumo LIKE :q OR l.biografia LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($type !== '' && isset(self::typeLabels()[$type])) {
            $where[] = 'l.tipo=:tipo';
            $params['tipo'] = $type;
        }
        if ($communityId > 0) {
            $where[] = 'l.comunidade_id=:comunidade_id';
            $params['comunidade_id'] = $communityId;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(*) FROM liderancas l' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PUBLIC_PER_PAGE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PUBLIC_PER_PAGE;

        $groupJoin = self::tableExists($pdo, 'grupos') ? 'LEFT JOIN grupos g ON g.id=l.grupo_id' : '';
        $groupSelect = self::tableExists($pdo, 'grupos') ? ',g.nome AS grupo_nome,g.slug AS grupo_slug' : ',NULL AS grupo_nome,NULL AS grupo_slug';

        $sql = "SELECT l.*,m.caminho AS foto_caminho,m.alt_text AS foto_alt,m.titulo AS foto_titulo,
                       c.nome AS comunidade_nome,c.slug AS comunidade_slug {$groupSelect}
                FROM liderancas l
                LEFT JOIN midias m ON m.id=l.foto_id
                LEFT JOIN comunidades c ON c.id=l.comunidade_id
                {$groupJoin}
                {$whereSql}
                ORDER BY l.ordem ASC,l.nome ASC,l.id ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
        $stmt->bindValue(':limit', self::PUBLIC_PER_PAGE, PDO::PARAM_INT);
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

    public static function whatsappUrl(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone) ?? '';
        if ($digits === '') return '';
        if (strlen($digits) <= 11) $digits = '55' . $digits;
        return 'https://wa.me/' . $digits;
    }

    private static function safeUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (!preg_match('#^https?://#i', $value)) $value = 'https://' . ltrim($value, '/');
        return filter_var($value, FILTER_VALIDATE_URL) ? self::cut($value, 500) : null;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name');
            $stmt->execute(['table_name' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function columns(PDO $pdo, string $table): array
    {
        try {
            $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table_name');
            $stmt->execute(['table_name' => $table]);
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
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
}
