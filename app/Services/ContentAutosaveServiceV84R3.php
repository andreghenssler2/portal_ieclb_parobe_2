<?php

declare(strict_types=1);

/**
 * Autosave de Posts/Notícias e Páginas.
 *
 * O rascunho automático é separado do conteúdo publicado. Ele só passa a
 * integrar o conteúdo real quando o usuário recupera e salva o formulário.
 */
final class ContentAutosaveService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS content_autosaves (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                content_type VARCHAR(20) NOT NULL,
                content_id INT UNSIGNED NULL,
                draft_key VARCHAR(80) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_content_autosave_user_key (user_id,draft_key),
                KEY idx_content_autosave_updated (updated_at),
                KEY idx_content_autosave_content
                    (content_type,content_id,user_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    public static function save(
        PDO $pdo,
        int $userId,
        string $contentType,
        int $contentId,
        array $payload
    ): array {
        self::ensureSchema($pdo);

        $contentType =
            self::normalizeType(
                $contentType
            );

        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'Usuário inválido para autosave.'
            );
        }

        $contentId =
            max(
                0,
                $contentId
            );

        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            );

        if (!is_string($json)) {
            throw new RuntimeException(
                'Não foi possível serializar o rascunho.'
            );
        }

        /*
         * Evita gravar requisições excessivamente grandes.
         * Arquivos binários não fazem parte do autosave.
         */
        if (strlen($json) > 4 * 1024 * 1024) {
            throw new RuntimeException(
                'O rascunho automático ultrapassou 4 MB.'
            );
        }

        $hash =
            hash(
                'sha256',
                $json
            );

        $key =
            self::draftKey(
                $contentType,
                $contentId
            );

        $stmt = $pdo->prepare(
            "INSERT INTO content_autosaves
                (
                    user_id,
                    content_type,
                    content_id,
                    draft_key,
                    payload_json,
                    payload_hash,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :user_id,
                    :content_type,
                    :content_id,
                    :draft_key,
                    :payload_json,
                    :payload_hash,
                    NOW(),
                    NOW()
                )
             ON DUPLICATE KEY UPDATE
                content_type=VALUES(content_type),
                content_id=VALUES(content_id),
                payload_json=VALUES(payload_json),
                payload_hash=VALUES(payload_hash),
                updated_at=NOW()"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
            'content_type' =>
                $contentType,
            'content_id' =>
                $contentId > 0
                    ? $contentId
                    : null,
            'draft_key' =>
                $key,
            'payload_json' =>
                $json,
            'payload_hash' =>
                $hash,
        ]);

        $row =
            self::find(
                $pdo,
                $userId,
                $contentType,
                $contentId
            );

        if (!$row) {
            throw new RuntimeException(
                'O autosave foi gravado, mas não pôde ser relido.'
            );
        }

        return $row;
    }

    public static function find(
        PDO $pdo,
        int $userId,
        string $contentType,
        int $contentId
    ): ?array {
        self::ensureSchema($pdo);

        $contentType =
            self::normalizeType(
                $contentType
            );

        $stmt = $pdo->prepare(
            "SELECT
                id,
                user_id,
                content_type,
                content_id,
                draft_key,
                payload_json,
                payload_hash,
                created_at,
                updated_at
             FROM content_autosaves
             WHERE user_id=:user_id
               AND draft_key=:draft_key
             LIMIT 1"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
            'draft_key' =>
                self::draftKey(
                    $contentType,
                    max(
                        0,
                        $contentId
                    )
                ),
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$row) {
            return null;
        }

        $payload =
            json_decode(
                (string)$row['payload_json'],
                true
            );

        if (!is_array($payload)) {
            return null;
        }

        unset(
            $row['payload_json']
        );

        $row['payload'] =
            $payload;

        return $row;
    }

    public static function delete(
        PDO $pdo,
        int $userId,
        string $contentType,
        int $contentId
    ): bool {
        self::ensureSchema($pdo);

        $contentType =
            self::normalizeType(
                $contentType
            );

        $stmt = $pdo->prepare(
            "DELETE FROM content_autosaves
             WHERE user_id=:user_id
               AND draft_key=:draft_key"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
            'draft_key' =>
                self::draftKey(
                    $contentType,
                    max(
                        0,
                        $contentId
                    )
                ),
        ]);

        return
            $stmt->rowCount() > 0;
    }

    public static function cleanup(
        PDO $pdo,
        int $days = 30
    ): int {
        self::ensureSchema($pdo);

        $days =
            max(
                7,
                min(
                    365,
                    $days
                )
            );

        $stmt =
            $pdo->prepare(
                "DELETE FROM content_autosaves
                 WHERE updated_at < DATE_SUB(
                    NOW(),
                    INTERVAL {$days} DAY
                 )"
            );

        $stmt->execute();

        return
            $stmt->rowCount();
    }

    private static function normalizeType(
        string $contentType
    ): string {
        $contentType =
            strtolower(
                trim(
                    $contentType
                )
            );

        if (
            !in_array(
                $contentType,
                [
                    'post',
                    'pagina',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Tipo de conteúdo inválido.'
            );
        }

        return $contentType;
    }

    private static function draftKey(
        string $contentType,
        int $contentId
    ): string {
        return
            $contentType
            . ':'
            . (
                $contentId > 0
                    ? (string)$contentId
                    : 'novo'
            );
    }
}
