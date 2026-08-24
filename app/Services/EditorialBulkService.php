<?php

declare(strict_types=1);

/**
 * Ações editoriais em massa para Posts/Notícias e Páginas.
 *
 * Portal IECLB Parobé v0.46.0
 */
final class EditorialBulkService
{
    private const TYPES = [
        'post' => [
            'table' => 'posts',
            'revision' => 'post',
            'label' => 'Notícia',
        ],
        'pagina' => [
            'table' => 'paginas',
            'revision' => 'pagina',
            'label' => 'Página',
        ],
    ];

    private const ACTIONS = [
        'publish',
        'draft',
        'archive',
        'trash',
        'restore',
        'delete',
    ];

    /**
     * @return array{processed:int,skipped:int}
     */
    public static function apply(
        PDO $pdo,
        string $type,
        array $ids,
        string $action,
        int $userId
    ): array {
        self::assertType($type);

        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Ação em massa inválida.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));

        $ids = array_slice($ids, 0, 100);

        if (!$ids) {
            throw new InvalidArgumentException(
                'Selecione pelo menos um conteúdo.'
            );
        }

        $meta = self::TYPES[$type];
        $table = $meta['table'];

        $select = $pdo->prepare(
            "SELECT id,titulo,status,status_anterior
             FROM {$table}
             WHERE id=:id
             LIMIT 1"
        );

        $processed = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($ids as $id) {
                $select->execute(['id' => $id]);
                $item = $select->fetch();

                if (!$item) {
                    $skipped++;
                    continue;
                }

                $changed = self::applyOne(
                    $pdo,
                    $type,
                    $item,
                    $action,
                    $userId
                );

                if ($changed) {
                    $processed++;
                } else {
                    $skipped++;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function applyOne(
        PDO $pdo,
        string $type,
        array $item,
        string $action,
        int $userId
    ): bool {
        $id = (int)$item['id'];
        $status = (string)($item['status'] ?? '');
        $title = (string)($item['titulo'] ?? '');

        return match ($action) {
            'publish' => self::changeStatus(
                $pdo,
                $type,
                $id,
                $status,
                'publicado',
                $userId,
                $title
            ),
            'draft' => self::changeStatus(
                $pdo,
                $type,
                $id,
                $status,
                'rascunho',
                $userId,
                $title
            ),
            'archive' => self::changeStatus(
                $pdo,
                $type,
                $id,
                $status,
                'arquivado',
                $userId,
                $title
            ),
            'trash' => self::trash(
                $pdo,
                $type,
                $id,
                $status,
                $userId,
                $title
            ),
            'restore' => self::restore(
                $pdo,
                $type,
                $id,
                $status,
                (string)($item['status_anterior'] ?? ''),
                $userId,
                $title
            ),
            'delete' => self::delete(
                $pdo,
                $type,
                $id,
                $status,
                $title
            ),
            default => false,
        };
    }

    private static function changeStatus(
        PDO $pdo,
        string $type,
        int $id,
        string $currentStatus,
        string $targetStatus,
        int $userId,
        string $title
    ): bool {
        if ($currentStatus === 'lixeira') {
            return false;
        }

        if ($currentStatus === $targetStatus) {
            return false;
        }

        RevisionService::create(
            $pdo,
            self::TYPES[$type]['revision'],
            $id,
            $userId > 0 ? $userId : null
        );

        $table = self::TYPES[$type]['table'];

        if ($targetStatus === 'publicado') {
            $stmt = $pdo->prepare(
                "UPDATE {$table}
                 SET status='publicado',
                     publicado_em=COALESCE(publicado_em,NOW()),
                     status_anterior=NULL,
                     lixeira_em=NULL
                 WHERE id=:id"
            );
        } elseif ($targetStatus === 'rascunho') {
            $stmt = $pdo->prepare(
                "UPDATE {$table}
                 SET status='rascunho',
                     publicado_em=NULL,
                     status_anterior=NULL,
                     lixeira_em=NULL
                 WHERE id=:id"
            );
        } else {
            $stmt = $pdo->prepare(
                "UPDATE {$table}
                 SET status=:status,
                     status_anterior=NULL,
                     lixeira_em=NULL
                 WHERE id=:id"
            );
        }

        $params = ['id' => $id];
        if ($targetStatus === 'arquivado') {
            $params['status'] = $targetStatus;
        }

        $stmt->execute($params);

        self::audit(
            $pdo,
            $type . '.massa.' . $targetStatus,
            $table,
            $id,
            $title
        );

        return true;
    }

    private static function trash(
        PDO $pdo,
        string $type,
        int $id,
        string $currentStatus,
        int $userId,
        string $title
    ): bool {
        if ($currentStatus === 'lixeira') {
            return false;
        }

        RevisionService::create(
            $pdo,
            self::TYPES[$type]['revision'],
            $id,
            $userId > 0 ? $userId : null
        );

        $table = self::TYPES[$type]['table'];

        $pdo->prepare(
            "UPDATE {$table}
             SET status_anterior=status,
                 status='lixeira',
                 lixeira_em=NOW()
             WHERE id=:id"
        )->execute(['id' => $id]);

        self::audit(
            $pdo,
            $type . '.massa.lixeira',
            $table,
            $id,
            $title
        );

        return true;
    }

    private static function restore(
        PDO $pdo,
        string $type,
        int $id,
        string $currentStatus,
        string $previousStatus,
        int $userId,
        string $title
    ): bool {
        if ($currentStatus !== 'lixeira') {
            return false;
        }

        $allowed = [
            'rascunho',
            'agendado',
            'publicado',
            'arquivado',
        ];

        $restoreStatus = in_array(
            $previousStatus,
            $allowed,
            true
        )
            ? $previousStatus
            : 'rascunho';

        $table = self::TYPES[$type]['table'];

        $pdo->prepare(
            "UPDATE {$table}
             SET status=:status,
                 status_anterior=NULL,
                 lixeira_em=NULL
             WHERE id=:id"
        )->execute([
            'status' => $restoreStatus,
            'id' => $id,
        ]);

        self::audit(
            $pdo,
            $type . '.massa.restaurar',
            $table,
            $id,
            $title
        );

        return true;
    }

    private static function delete(
        PDO $pdo,
        string $type,
        int $id,
        string $currentStatus,
        string $title
    ): bool {
        if ($currentStatus !== 'lixeira') {
            return false;
        }

        if ($type === 'post') {
            self::deletePost($pdo, $id);
        } else {
            self::deletePage($pdo, $id);
        }

        self::audit(
            $pdo,
            $type . '.massa.excluir_permanente',
            self::TYPES[$type]['table'],
            $id,
            $title
        );

        return true;
    }

    private static function deletePost(PDO $pdo, int $id): void
    {
        RevisionService::deleteForContent(
            $pdo,
            'post',
            $id
        );

        ContentBlockService::deleteForContent(
            $pdo,
            'post',
            $id
        );

        foreach ([
            'post_categorias',
            'post_tags',
            'comentarios',
        ] as $relatedTable) {
            try {
                $pdo->prepare(
                    'DELETE FROM ' . $relatedTable
                    . ' WHERE post_id=:id'
                )->execute(['id' => $id]);
            } catch (Throwable $ignored) {
            }
        }

        $pdo->prepare(
            'DELETE FROM posts WHERE id=:id'
        )->execute(['id' => $id]);
    }

    private static function deletePage(PDO $pdo, int $id): void
    {
        RevisionService::deleteForContent(
            $pdo,
            'pagina',
            $id
        );

        ContentBlockService::deleteForContent(
            $pdo,
            'pagina',
            $id
        );

        try {
            $pdo->prepare(
                'DELETE FROM menu_itens WHERE pagina_id=:id'
            )->execute(['id' => $id]);
        } catch (Throwable $ignored) {
        }

        $pdo->prepare(
            'UPDATE paginas
             SET parent_id=NULL
             WHERE parent_id=:id'
        )->execute(['id' => $id]);

        $pdo->prepare(
            'DELETE FROM paginas WHERE id=:id'
        )->execute(['id' => $id]);
    }

    private static function audit(
        PDO $pdo,
        string $action,
        string $table,
        int $id,
        string $title
    ): void {
        if (!function_exists('logAction')) {
            return;
        }

        try {
            logAction(
                $pdo,
                $action,
                $table,
                $id,
                $title
            );
        } catch (Throwable $ignored) {
        }
    }

    private static function assertType(string $type): void
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException(
                'Tipo de conteúdo inválido.'
            );
        }
    }
}
