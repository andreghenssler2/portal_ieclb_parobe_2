<?php

declare(strict_types=1);

final class ContentPatternService
{
    private static array $schemaReady = [];

    public static function ensureSchema(PDO $pdo): void
    {
        $key = spl_object_id($pdo);
        if (!empty(self::$schemaReady[$key])) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS conteudo_padroes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nome VARCHAR(160) NOT NULL,
                descricao VARCHAR(500) NULL,
                escopo VARCHAR(20) NOT NULL DEFAULT 'geral',
                blocos_json LONGTEXT NOT NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_por INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_conteudo_padroes_escopo (escopo,ativo,nome)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaReady[$key] = true;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        return $pdo->query(
            "SELECT cp.*,u.nome AS autor_nome
             FROM conteudo_padroes cp
             LEFT JOIN usuarios u ON u.id=cp.criado_por
             ORDER BY cp.ativo DESC,cp.nome ASC,cp.id DESC"
        )->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $pdo, int $id): ?array
    {
        self::ensureSchema($pdo);

        if ($id <= 0) return null;

        $stmt = $pdo->prepare(
            'SELECT * FROM conteudo_padroes WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int,array{
     *   id:int,
     *   nome:string,
     *   descricao:string,
     *   escopo:string,
     *   blocks:array
     * }>
     */
    public static function activeFor(PDO $pdo, string $contentType): array
    {
        self::ensureSchema($pdo);

        if (!in_array($contentType, ['pagina','post'], true)) {
            throw new InvalidArgumentException('Tipo de conteúdo inválido.');
        }

        $stmt = $pdo->prepare(
            "SELECT id,nome,descricao,escopo,blocos_json
             FROM conteudo_padroes
             WHERE ativo=1
               AND (escopo='geral' OR escopo=:escopo)
             ORDER BY nome ASC,id ASC"
        );
        $stmt->execute(['escopo' => $contentType]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $blocks = json_decode((string)$row['blocos_json'], true);
            if (!is_array($blocks) || !$blocks) {
                continue;
            }

            $out[] = [
                'id' => (int)$row['id'],
                'nome' => (string)$row['nome'],
                'descricao' => (string)($row['descricao'] ?? ''),
                'escopo' => (string)$row['escopo'],
                'blocks' => ContentBlockService::prepareForEditor(
                    $pdo,
                    $blocks
                ),
            ];
        }

        return $out;
    }

    public static function save(
        PDO $pdo,
        ?int $id,
        string $name,
        ?string $description,
        string $scope,
        bool $active,
        int $userId,
        array $blocks
    ): int {
        self::ensureSchema($pdo);

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Informe o nome do padrão.');
        }

        $name = mb_substr($name, 0, 160);
        $description = trim((string)$description);
        $description = $description !== ''
            ? mb_substr($description, 0, 500)
            : null;

        if (!in_array($scope, ['geral','pagina','post'], true)) {
            $scope = 'geral';
        }

        if (!ContentBlockService::hasContent($blocks)) {
            throw new InvalidArgumentException(
                'Adicione pelo menos um bloco ao padrão.'
            );
        }

        // Reaplica a sanitização do serviço central antes de persistir.
        $blocks = ContentBlockService::fromJson(
            $pdo,
            json_encode(
                $blocks,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '[]'
        );

        if (!$blocks) {
            throw new InvalidArgumentException(
                'O padrão não possui blocos válidos.'
            );
        }

        $json = json_encode(
            $blocks,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            throw new RuntimeException('Não foi possível serializar os blocos.');
        }

        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE conteudo_padroes
                 SET nome=:nome,
                     descricao=:descricao,
                     escopo=:escopo,
                     blocos_json=:blocos_json,
                     ativo=:ativo,
                     updated_at=NOW()
                 WHERE id=:id'
            );
            $stmt->execute([
                'nome' => $name,
                'descricao' => $description,
                'escopo' => $scope,
                'blocos_json' => $json,
                'ativo' => $active ? 1 : 0,
                'id' => $id,
            ]);

            return $id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO conteudo_padroes
                (nome,descricao,escopo,blocos_json,ativo,criado_por)
             VALUES
                (:nome,:descricao,:escopo,:blocos_json,:ativo,:criado_por)'
        );
        $stmt->execute([
            'nome' => $name,
            'descricao' => $description,
            'escopo' => $scope,
            'blocos_json' => $json,
            'ativo' => $active ? 1 : 0,
            'criado_por' => $userId > 0 ? $userId : null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function duplicate(PDO $pdo, int $id, int $userId): int
    {
        $pattern = self::find($pdo, $id);
        if (!$pattern) {
            throw new RuntimeException('Padrão não encontrado.');
        }

        $blocks = json_decode((string)$pattern['blocos_json'], true);
        if (!is_array($blocks)) {
            $blocks = [];
        }

        return self::save(
            $pdo,
            null,
            'Cópia de ' . (string)$pattern['nome'],
            (string)($pattern['descricao'] ?? ''),
            (string)$pattern['escopo'],
            false,
            $userId,
            $blocks
        );
    }

    public static function delete(PDO $pdo, int $id): void
    {
        self::ensureSchema($pdo);

        if ($id <= 0) return;

        $stmt = $pdo->prepare(
            'DELETE FROM conteudo_padroes WHERE id=:id'
        );
        $stmt->execute(['id' => $id]);
    }
}
