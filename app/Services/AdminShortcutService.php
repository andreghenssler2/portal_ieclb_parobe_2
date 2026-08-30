<?php

declare(strict_types=1);

/**
 * Favoritos e acessos recentes do painel administrativo.
 */
final class AdminShortcutService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function recordVisit(
        int $userId,
        string $route,
        string $title
    ): void {
        $userId = max(0, $userId);
        $route = self::normalizeRoute($route);
        $title = self::normalizeTitle($title);

        if (
            $userId <= 0
            || $route === ''
            || $title === ''
            || self::shouldIgnoreRoute($route)
        ) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO usuario_admin_atalhos
                (
                    usuario_id,
                    rota,
                    titulo,
                    favorito,
                    acessos,
                    ultimo_acesso_em,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :usuario_id,
                    :rota,
                    :titulo,
                    0,
                    1,
                    NOW(),
                    NOW(),
                    NOW()
                )
             ON DUPLICATE KEY UPDATE
                titulo=VALUES(titulo),
                acessos=acessos+1,
                ultimo_acesso_em=NOW(),
                updated_at=NOW()"
        );

        $stmt->execute([
            'usuario_id' => $userId,
            'rota' => $route,
            'titulo' => $title,
        ]);

        $this->prune($userId);
    }

    public function toggleFavorite(
        int $userId,
        string $route,
        string $title
    ): bool {
        $userId = max(0, $userId);
        $route = self::normalizeRoute($route);
        $title = self::normalizeTitle($title);

        if (
            $userId <= 0
            || $route === ''
            || $title === ''
            || self::shouldIgnoreRoute($route)
        ) {
            throw new InvalidArgumentException(
                'Atalho administrativo inválido.'
            );
        }

        $stmt = $this->pdo->prepare(
            "SELECT id,favorito
             FROM usuario_admin_atalhos
             WHERE usuario_id=:usuario_id
               AND rota=:rota
             LIMIT 1"
        );

        $stmt->execute([
            'usuario_id' => $userId,
            'rota' => $route,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $insert = $this->pdo->prepare(
                "INSERT INTO usuario_admin_atalhos
                    (
                        usuario_id,
                        rota,
                        titulo,
                        favorito,
                        acessos,
                        ultimo_acesso_em,
                        created_at,
                        updated_at
                    )
                 VALUES
                    (
                        :usuario_id,
                        :rota,
                        :titulo,
                        1,
                        1,
                        NOW(),
                        NOW(),
                        NOW()
                    )"
            );

            $insert->execute([
                'usuario_id' => $userId,
                'rota' => $route,
                'titulo' => $title,
            ]);

            return true;
        }

        $favorite =
            (int)($row['favorito'] ?? 0) !== 1;

        $update = $this->pdo->prepare(
            "UPDATE usuario_admin_atalhos
             SET favorito=:favorito,
                 titulo=:titulo,
                 updated_at=NOW()
             WHERE id=:id
               AND usuario_id=:usuario_id"
        );

        $update->execute([
            'favorito' => $favorite ? 1 : 0,
            'titulo' => $title,
            'id' => (int)$row['id'],
            'usuario_id' => $userId,
        ]);

        return $favorite;
    }

    /**
     * @return array{
     *   favorites:array<int,array<string,mixed>>,
     *   recent:array<int,array<string,mixed>>,
     *   current_favorite:bool
     * }
     */
    public function lists(
        int $userId,
        string $currentRoute = ''
    ): array {
        $userId = max(0, $userId);

        if ($userId <= 0) {
            return [
                'favorites' => [],
                'recent' => [],
                'current_favorite' => false,
            ];
        }

        $favorites = $this->fetchRows(
            "SELECT
                id,
                rota,
                titulo,
                favorito,
                acessos,
                ultimo_acesso_em,
                updated_at
             FROM usuario_admin_atalhos
             WHERE usuario_id=:usuario_id
               AND favorito=1
             ORDER BY
                updated_at DESC,
                id DESC
             LIMIT 12",
            [
                'usuario_id' => $userId,
            ]
        );

        $recent = $this->fetchRows(
            "SELECT
                id,
                rota,
                titulo,
                favorito,
                acessos,
                ultimo_acesso_em,
                updated_at
             FROM usuario_admin_atalhos
             WHERE usuario_id=:usuario_id
             ORDER BY
                ultimo_acesso_em DESC,
                id DESC
             LIMIT 12",
            [
                'usuario_id' => $userId,
            ]
        );

        $currentFavorite = false;
        $currentRoute = self::normalizeRoute($currentRoute);

        if ($currentRoute !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT favorito
                 FROM usuario_admin_atalhos
                 WHERE usuario_id=:usuario_id
                   AND rota=:rota
                 LIMIT 1"
            );

            $stmt->execute([
                'usuario_id' => $userId,
                'rota' => $currentRoute,
            ]);

            $currentFavorite =
                (int)$stmt->fetchColumn() === 1;
        }

        return [
            'favorites' => $favorites,
            'recent' => $recent,
            'current_favorite' => $currentFavorite,
        ];
    }

    public function remove(
        int $userId,
        string $route
    ): void {
        $userId = max(0, $userId);
        $route = self::normalizeRoute($route);

        if (
            $userId <= 0
            || $route === ''
        ) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "DELETE FROM usuario_admin_atalhos
             WHERE usuario_id=:usuario_id
               AND rota=:rota"
        );

        $stmt->execute([
            'usuario_id' => $userId,
            'rota' => $route,
        ]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(
        string $sql,
        array $params
    ): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        foreach ($rows as &$row) {
            $row['url'] =
                url(
                    (string)$row['rota']
                );

            $row['favorito'] =
                (int)($row['favorito'] ?? 0) === 1;

            $row['acessos'] =
                (int)($row['acessos'] ?? 0);
        }

        unset($row);

        return $rows;
    }

    private function prune(int $userId): void
    {
        /*
         * Favoritos nunca são podados.
         * Mantemos os 60 itens não favoritos mais recentes por usuário.
         */
        $stmt = $this->pdo->prepare(
            "DELETE FROM usuario_admin_atalhos
             WHERE usuario_id=:usuario_id
               AND favorito=0
               AND id NOT IN (
                    SELECT id
                    FROM (
                        SELECT id
                        FROM usuario_admin_atalhos
                        WHERE usuario_id=:usuario_id_inner
                          AND favorito=0
                        ORDER BY
                            ultimo_acesso_em DESC,
                            id DESC
                        LIMIT 60
                    ) AS keep_rows
               )"
        );

        $stmt->execute([
            'usuario_id' => $userId,
            'usuario_id_inner' => $userId,
        ]);
    }

    public static function normalizeRoute(
        string $route
    ): string {
        $route = trim($route);

        if ($route === '') {
            return '';
        }

        /*
         * Não aceita URL absoluta.
         */
        if (
            preg_match(
                '~^(?:https?:)?//~i',
                $route
            )
        ) {
            return '';
        }

        $route =
            preg_replace(
                '~[\x00-\x1F\x7F]+~',
                '',
                $route
            );

        if (!is_string($route)) {
            return '';
        }

        $route =
            ltrim(
                $route,
                '/'
            );

        if (!str_starts_with($route, 'admin/')) {
            $route = 'admin/' . $route;
        }

        /*
         * Evita navegação fora do Admin.
         */
        if (
            str_contains($route, '..')
            || !preg_match(
                '~^admin/[a-zA-Z0-9_./?=&%+\-]*$~',
                $route
            )
        ) {
            return '';
        }

        return mb_substr(
            $route,
            0,
            255
        );
    }

    private static function normalizeTitle(
        string $title
    ): string {
        $title =
            strip_tags(
                trim($title)
            );

        $title =
            preg_replace(
                '/\s+/u',
                ' ',
                $title
            );

        if (!is_string($title)) {
            return '';
        }

        return mb_substr(
            $title,
            0,
            180
        );
    }

    private static function shouldIgnoreRoute(
        string $route
    ): bool {
        foreach (
            [
                'admin/api/',
                'admin/login.php',
                'admin/logout.php',
            ]
            as $ignored
        ) {
            if (
                str_starts_with(
                    $route,
                    $ignored
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
