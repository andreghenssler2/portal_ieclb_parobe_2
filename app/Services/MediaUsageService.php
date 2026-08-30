<?php

declare(strict_types=1);

/**
 * Descobre onde uma mídia está sendo usada no Portal.
 *
 * A exclusão de uma mídia usada deve ser bloqueada antes do DELETE para
 * evitar registros quebrados e arquivos ausentes no conteúdo público.
 */
final class MediaUsageService
{
    /**
     * @return array{
     *   total:int,
     *   groups:array<int,array{
     *     key:string,
     *     label:string,
     *     count:int,
     *     items:array<int,array<string,mixed>>
     *   }>
     * }
     */
    public static function usage(
        PDO $pdo,
        int $mediaId,
        int $limitPerGroup = 20
    ): array {
        $mediaId = max(0, $mediaId);
        $limitPerGroup =
            max(
                1,
                min(
                    50,
                    $limitPerGroup
                )
            );

        if ($mediaId <= 0) {
            return [
                'total' => 0,
                'groups' => [],
            ];
        }

        $groups = [];
        $seen = [];

        /*
         * Relações conhecidas do Portal.
         *
         * Mesmo quando a instalação antiga não criou FOREIGN KEY,
         * conseguimos localizar os vínculos pelos campos usados pelo CMS.
         */
        $known = [
            [
                'table' => 'posts',
                'column' => 'imagem_capa_id',
                'key' => 'posts',
                'label' => 'Notícias',
                'title_column' => 'titulo',
                'status_column' => 'status',
                'url_prefix' => 'admin/noticias/form.php?id=',
            ],
            [
                'table' => 'paginas',
                'column' => 'imagem_capa_id',
                'key' => 'paginas',
                'label' => 'Páginas',
                'title_column' => 'titulo',
                'status_column' => 'status',
                'url_prefix' => 'admin/paginas/form.php?id=',
            ],
            [
                'table' => 'eventos',
                'column' => 'imagem_capa_id',
                'key' => 'eventos',
                'label' => 'Eventos / Cultos',
                'title_column' => 'titulo',
                'status_column' => 'status',
                'url_prefix' => 'admin/eventos/form.php?id=',
            ],
            [
                'table' => 'documentos',
                'column' => 'midia_id',
                'key' => 'documentos',
                'label' => 'Documentos',
                'title_column' => 'titulo',
                'status_column' => 'status',
                'url_prefix' => 'admin/documentos/form.php?id=',
            ],
        ];

        foreach ($known as $definition) {
            $table =
                (string)$definition['table'];

            $column =
                (string)$definition['column'];

            if (
                !self::columnExists(
                    $pdo,
                    $table,
                    $column
                )
            ) {
                continue;
            }

            $group =
                self::knownGroup(
                    $pdo,
                    $mediaId,
                    $definition,
                    $limitPerGroup
                );

            $seen[
                strtolower(
                    $table
                    . '.'
                    . $column
                )
            ] = true;

            if (
                (int)$group['count'] > 0
            ) {
                $groups[] = $group;
            }
        }

        /*
         * FOREIGN KEYs adicionais apontando para midias.id.
         *
         * Se versões futuras criarem novas relações, a proteção continua
         * funcionando mesmo antes de a tela ganhar um layout específico.
         */
        foreach (
            self::foreignKeysToMedia($pdo)
            as $foreign
        ) {
            $table =
                (string)$foreign['table_name'];

            $column =
                (string)$foreign['column_name'];

            $identity =
                strtolower(
                    $table
                    . '.'
                    . $column
                );

            if (isset($seen[$identity])) {
                continue;
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9_]+$/',
                    $table
                )
                || !preg_match(
                    '/^[a-zA-Z0-9_]+$/',
                    $column
                )
            ) {
                continue;
            }

            try {
                $stmt =
                    $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM `{$table}`
                         WHERE `{$column}`=:media_id"
                    );

                $stmt->execute([
                    'media_id' => $mediaId,
                ]);

                $count =
                    (int)$stmt->fetchColumn();

                if ($count <= 0) {
                    continue;
                }

                $groups[] = [
                    'key' =>
                        $table
                        . '.'
                        . $column,
                    'label' =>
                        'Referência em '
                        . $table,
                    'count' => $count,
                    'items' => [],
                ];
            } catch (Throwable $ignored) {
            }
        }

        $total =
            array_sum(
                array_map(
                    static fn(array $group): int =>
                        (int)$group['count'],
                    $groups
                )
            );

        return [
            'total' => $total,
            'groups' => $groups,
        ];
    }

    public static function isInUse(
        PDO $pdo,
        int $mediaId
    ): bool {
        return self::usage(
            $pdo,
            $mediaId,
            1
        )['total'] > 0;
    }

    public static function assertCanDelete(
        PDO $pdo,
        int $mediaId
    ): void {
        $usage =
            self::usage(
                $pdo,
                $mediaId,
                5
            );

        $total =
            (int)$usage['total'];

        if ($total <= 0) {
            return;
        }

        $labels = [];

        foreach (
            $usage['groups']
            as $group
        ) {
            $labels[] =
                (string)$group['label']
                . ' ('
                . (int)$group['count']
                . ')';
        }

        throw new RuntimeException(
            'Esta mídia está em uso em '
            . $total
            . ' conteúdo(s): '
            . implode(
                ', ',
                $labels
            )
            . '. Remova ou substitua as referências antes de excluí-la.'
        );
    }

    /**
     * @param array<string,mixed> $definition
     * @return array{
     *   key:string,
     *   label:string,
     *   count:int,
     *   items:array<int,array<string,mixed>>
     * }
     */
    private static function knownGroup(
        PDO $pdo,
        int $mediaId,
        array $definition,
        int $limit
    ): array {
        $table =
            (string)$definition['table'];

        $column =
            (string)$definition['column'];

        $titleColumn =
            (string)$definition['title_column'];

        $statusColumn =
            (string)$definition['status_column'];

        $hasTitle =
            self::columnExists(
                $pdo,
                $table,
                $titleColumn
            );

        $hasStatus =
            self::columnExists(
                $pdo,
                $table,
                $statusColumn
            );

        $titleSelect =
            $hasTitle
                ? "`{$titleColumn}` AS item_title"
                : "CONCAT('#',id) AS item_title";

        $statusSelect =
            $hasStatus
                ? "`{$statusColumn}` AS item_status"
                : "NULL AS item_status";

        try {
            $countStmt =
                $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM `{$table}`
                     WHERE `{$column}`=:media_id"
                );

            $countStmt->execute([
                'media_id' => $mediaId,
            ]);

            $count =
                (int)$countStmt->fetchColumn();

            if ($count <= 0) {
                return [
                    'key' =>
                        (string)$definition['key'],
                    'label' =>
                        (string)$definition['label'],
                    'count' => 0,
                    'items' => [],
                ];
            }

            $stmt =
                $pdo->prepare(
                    "SELECT
                        id,
                        {$titleSelect},
                        {$statusSelect}
                     FROM `{$table}`
                     WHERE `{$column}`=:media_id
                     ORDER BY id DESC
                     LIMIT {$limit}"
                );

            $stmt->execute([
                'media_id' => $mediaId,
            ]);

            $rows =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [];

            $items = [];

            foreach ($rows as $row) {
                $id =
                    (int)($row['id'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                $title =
                    trim(
                        (string)(
                            $row['item_title']
                            ?? ''
                        )
                    );

                if ($title === '') {
                    $title =
                        '#'
                        . $id;
                }

                $items[] = [
                    'id' => $id,
                    'title' => $title,
                    'status' =>
                        trim(
                            (string)(
                                $row['item_status']
                                ?? ''
                            )
                        ),
                    'url' =>
                        url(
                            (string)$definition['url_prefix']
                            . $id
                        ),
                ];
            }

            return [
                'key' =>
                    (string)$definition['key'],
                'label' =>
                    (string)$definition['label'],
                'count' => $count,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return [
                'key' =>
                    (string)$definition['key'],
                'label' =>
                    (string)$definition['label'],
                'count' => 0,
                'items' => [],
            ];
        }
    }

    /**
     * @return array<int,array{
     *   table_name:string,
     *   column_name:string
     * }>
     */
    private static function foreignKeysToMedia(
        PDO $pdo
    ): array {
        try {
            $stmt =
                $pdo->query(
                    "SELECT
                        TABLE_NAME AS table_name,
                        COLUMN_NAME AS column_name
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA=DATABASE()
                       AND REFERENCED_TABLE_NAME='midias'
                       AND REFERENCED_COLUMN_NAME='id'
                     ORDER BY
                        TABLE_NAME,
                        COLUMN_NAME"
                );

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function columnExists(
        PDO $pdo,
        string $table,
        string $column
    ): bool {
        if (
            !preg_match(
                '/^[a-zA-Z0-9_]+$/',
                $table
            )
            || !preg_match(
                '/^[a-zA-Z0-9_]+$/',
                $column
            )
        ) {
            return false;
        }

        try {
            $stmt =
                $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema=DATABASE()
                       AND table_name=:table_name
                       AND column_name=:column_name"
                );

            $stmt->execute([
                'table_name' => $table,
                'column_name' => $column,
            ]);

            return
                (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
