<?php

declare(strict_types=1);

/**
 * Central de notificações administrativas.
 *
 * A v0.85 transforma pendências relevantes em notificações pessoais e
 * persistentes, sem substituir a Central de Pendências.
 */
final class AdminNotificationService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                notification_key VARCHAR(190) NOT NULL,
                title VARCHAR(220) NOT NULL,
                message TEXT NULL,
                target_url VARCHAR(500) NULL,
                icon VARCHAR(80) NOT NULL DEFAULT 'bi-bell',
                level VARCHAR(30) NOT NULL DEFAULT 'primary',
                source_count INT UNSIGNED NOT NULL DEFAULT 0,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_notification_user_key
                    (user_id,notification_key),
                KEY idx_admin_notification_unread
                    (user_id,is_read,resolved_at,updated_at),
                KEY idx_admin_notification_recent
                    (user_id,updated_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    /**
     * Sincroniza as pendências que o usuário atual pode enxergar.
     *
     * @return array{unread:int,total_active:int}
     */
    public static function syncCurrentUser(
        PDO $pdo,
        bool $force = false
    ): array {
        $userId =
            (int)Auth::id();

        if ($userId <= 0) {
            return [
                'unread' => 0,
                'total_active' => 0,
            ];
        }

        self::ensureSchema($pdo);

        $lastSync =
            (int)(
                $_SESSION['_admin_notifications_sync_at']
                ?? 0
            );

        if (
            !$force
            && $lastSync > 0
            && (time() - $lastSync) < 60
        ) {
            return [
                'unread' =>
                    self::unreadCount(
                        $pdo,
                        $userId
                    ),
                'total_active' =>
                    self::activeCount(
                        $pdo,
                        $userId
                    ),
            ];
        }

        self::ensureWelcome(
            $pdo,
            $userId
        );

        $currentKeys = [];

        if (
            class_exists(
                'AdminPendingService'
            )
        ) {
            try {
                $overview =
                    AdminPendingService::overview(
                        $pdo
                    );

                foreach (
                    (array)(
                        $overview['items']
                        ?? []
                    )
                    as $item
                ) {
                    $count =
                        max(
                            0,
                            (int)(
                                $item['count']
                                ?? 0
                            )
                        );

                    if ($count <= 0) {
                        continue;
                    }

                    $sourceKey =
                        trim(
                            (string)(
                                $item['key']
                                ?? ''
                            )
                        );

                    if ($sourceKey === '') {
                        continue;
                    }

                    $key =
                        'pending:'
                        . $sourceKey;

                    $currentKeys[] =
                        $key;

                    self::upsertPending(
                        $pdo,
                        $userId,
                        $key,
                        (string)(
                            $item['label']
                            ?? 'Pendência administrativa'
                        ),
                        (string)(
                            $item['description']
                            ?? ''
                        ),
                        (string)(
                            $item['url']
                            ?? 'admin/pendencias.php'
                        ),
                        (string)(
                            $item['icon']
                            ?? 'bi-bell'
                        ),
                        self::normalizeLevel(
                            (string)(
                                $item['class']
                                ?? 'primary'
                            )
                        ),
                        $count
                    );
                }
            } catch (Throwable $ignored) {
            }
        }

        self::resolveMissingPending(
            $pdo,
            $userId,
            $currentKeys
        );

        $_SESSION['_admin_notifications_sync_at'] =
            time();

        return [
            'unread' =>
                self::unreadCount(
                    $pdo,
                    $userId
                ),
            'total_active' =>
                self::activeCount(
                    $pdo,
                    $userId
                ),
        ];
    }

    /**
     * Cria ou atualiza uma notificação personalizada.
     */
    public static function notify(
        PDO $pdo,
        int $userId,
        string $key,
        string $title,
        string $message = '',
        string $targetUrl = '',
        string $level = 'primary',
        string $icon = 'bi-bell',
        bool $markUnread = true
    ): int {
        self::ensureSchema($pdo);

        if ($userId <= 0) {
            throw new InvalidArgumentException(
                'Usuário inválido para notificação.'
            );
        }

        $key =
            self::cut(
                trim($key),
                190
            );

        $title =
            self::cut(
                trim($title),
                220
            );

        if (
            $key === ''
            || $title === ''
        ) {
            throw new InvalidArgumentException(
                'A notificação precisa de chave e título.'
            );
        }

        $existing =
            self::findByKey(
                $pdo,
                $userId,
                $key
            );

        if (!$existing) {
            $stmt = $pdo->prepare(
                "INSERT INTO admin_notifications
                    (
                        user_id,
                        notification_key,
                        title,
                        message,
                        target_url,
                        icon,
                        level,
                        source_count,
                        is_read,
                        read_at,
                        resolved_at,
                        created_at,
                        updated_at
                    )
                 VALUES
                    (
                        :user_id,
                        :notification_key,
                        :title,
                        :message,
                        :target_url,
                        :icon,
                        :level,
                        0,
                        :is_read,
                        :read_at,
                        NULL,
                        NOW(),
                        NOW()
                    )"
            );

            $stmt->execute([
                'user_id' => $userId,
                'notification_key' => $key,
                'title' => $title,
                'message' => $message,
                'target_url' =>
                    $targetUrl !== ''
                        ? self::cut(
                            $targetUrl,
                            500
                        )
                        : null,
                'icon' =>
                    self::cut(
                        $icon !== ''
                            ? $icon
                            : 'bi-bell',
                        80
                    ),
                'level' =>
                    self::normalizeLevel(
                        $level
                    ),
                'is_read' =>
                    $markUnread
                        ? 0
                        : 1,
                'read_at' =>
                    $markUnread
                        ? null
                        : date('Y-m-d H:i:s'),
            ]);

            return
                (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            "UPDATE admin_notifications
             SET title=:title,
                 message=:message,
                 target_url=:target_url,
                 icon=:icon,
                 level=:level,
                 resolved_at=NULL,
                 is_read=:is_read,
                 read_at=:read_at,
                 updated_at=NOW()
             WHERE id=:id"
        );

        $stmt->execute([
            'title' => $title,
            'message' => $message,
            'target_url' =>
                $targetUrl !== ''
                    ? self::cut(
                        $targetUrl,
                        500
                    )
                    : null,
            'icon' =>
                self::cut(
                    $icon !== ''
                        ? $icon
                        : 'bi-bell',
                    80
                ),
            'level' =>
                self::normalizeLevel(
                    $level
                ),
            'is_read' =>
                $markUnread
                    ? 0
                    : (int)(
                        $existing['is_read']
                        ?? 0
                    ),
            'read_at' =>
                $markUnread
                    ? null
                    : (
                        $existing['read_at']
                        ?? null
                    ),
            'id' =>
                (int)$existing['id'],
        ]);

        return
            (int)$existing['id'];
    }

    public static function unreadCount(
        PDO $pdo,
        int $userId
    ): int {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM admin_notifications
             WHERE user_id=:user_id
               AND is_read=0
               AND resolved_at IS NULL"
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return
            max(
                0,
                (int)$stmt->fetchColumn()
            );
    }

    public static function activeCount(
        PDO $pdo,
        int $userId
    ): int {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM admin_notifications
             WHERE user_id=:user_id
               AND resolved_at IS NULL"
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return
            max(
                0,
                (int)$stmt->fetchColumn()
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listForUser(
        PDO $pdo,
        int $userId,
        string $filter = 'all',
        int $limit = 100
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    200,
                    $limit
                )
            );

        $where =
            "user_id=:user_id";

        if ($filter === 'unread') {
            $where .=
                " AND is_read=0
                  AND resolved_at IS NULL";
        } elseif ($filter === 'active') {
            $where .=
                " AND resolved_at IS NULL";
        }

        $stmt = $pdo->prepare(
            "SELECT *
             FROM admin_notifications
             WHERE {$where}
             ORDER BY
                CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END,
                CASE WHEN is_read=0 THEN 0 ELSE 1 END,
                updated_at DESC,
                id DESC
             LIMIT {$limit}"
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentUnread(
        PDO $pdo,
        int $userId,
        int $limit = 5
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    10,
                    $limit
                )
            );

        $stmt = $pdo->prepare(
            "SELECT *
             FROM admin_notifications
             WHERE user_id=:user_id
               AND is_read=0
               AND resolved_at IS NULL
             ORDER BY updated_at DESC,id DESC
             LIMIT {$limit}"
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];
    }

    public static function markRead(
        PDO $pdo,
        int $userId,
        int $notificationId
    ): bool {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "UPDATE admin_notifications
             SET is_read=1,
                 read_at=COALESCE(read_at,NOW())
             WHERE id=:id
               AND user_id=:user_id"
        );

        $stmt->execute([
            'id' =>
                $notificationId,
            'user_id' =>
                $userId,
        ]);

        return
            $stmt->rowCount() > 0;
    }

    public static function markUnread(
        PDO $pdo,
        int $userId,
        int $notificationId
    ): bool {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "UPDATE admin_notifications
             SET is_read=0,
                 read_at=NULL
             WHERE id=:id
               AND user_id=:user_id
               AND resolved_at IS NULL"
        );

        $stmt->execute([
            'id' =>
                $notificationId,
            'user_id' =>
                $userId,
        ]);

        return
            $stmt->rowCount() > 0;
    }

    public static function markAllRead(
        PDO $pdo,
        int $userId
    ): int {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "UPDATE admin_notifications
             SET is_read=1,
                 read_at=COALESCE(read_at,NOW())
             WHERE user_id=:user_id
               AND is_read=0"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
        ]);

        return
            $stmt->rowCount();
    }

    public static function deleteReadResolved(
        PDO $pdo,
        int $userId
    ): int {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "DELETE FROM admin_notifications
             WHERE user_id=:user_id
               AND is_read=1
               AND resolved_at IS NOT NULL"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
        ]);

        return
            $stmt->rowCount();
    }

    public static function findForUser(
        PDO $pdo,
        int $userId,
        int $notificationId
    ): ?array {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT *
             FROM admin_notifications
             WHERE id=:id
               AND user_id=:user_id
             LIMIT 1"
        );

        $stmt->execute([
            'id' =>
                $notificationId,
            'user_id' =>
                $userId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row ?: null;
    }

    private static function ensureWelcome(
        PDO $pdo,
        int $userId
    ): void {
        $key =
            'system:v0.85.0';

        if (
            self::findByKey(
                $pdo,
                $userId,
                $key
            )
        ) {
            return;
        }

        self::notify(
            $pdo,
            $userId,
            $key,
            'Central de notificações ativada',
            'Agora o painel reúne alertas e pendências importantes em uma caixa de notificações pessoal.',
            'admin/notificacoes.php',
            'primary',
            'bi-bell-fill',
            true
        );
    }

    private static function upsertPending(
        PDO $pdo,
        int $userId,
        string $key,
        string $title,
        string $message,
        string $targetUrl,
        string $icon,
        string $level,
        int $count
    ): void {
        $existing =
            self::findByKey(
                $pdo,
                $userId,
                $key
            );

        if (!$existing) {
            $stmt = $pdo->prepare(
                "INSERT INTO admin_notifications
                    (
                        user_id,
                        notification_key,
                        title,
                        message,
                        target_url,
                        icon,
                        level,
                        source_count,
                        is_read,
                        read_at,
                        resolved_at,
                        created_at,
                        updated_at
                    )
                 VALUES
                    (
                        :user_id,
                        :notification_key,
                        :title,
                        :message,
                        :target_url,
                        :icon,
                        :level,
                        :source_count,
                        0,
                        NULL,
                        NULL,
                        NOW(),
                        NOW()
                    )"
            );

            $stmt->execute([
                'user_id' => $userId,
                'notification_key' => $key,
                'title' =>
                    self::withCount(
                        $title,
                        $count
                    ),
                'message' => $message,
                'target_url' =>
                    self::cut(
                        $targetUrl,
                        500
                    ),
                'icon' =>
                    self::cut(
                        $icon,
                        80
                    ),
                'level' =>
                    self::normalizeLevel(
                        $level
                    ),
                'source_count' =>
                    $count,
            ]);

            return;
        }

        $oldCount =
            max(
                0,
                (int)(
                    $existing['source_count']
                    ?? 0
                )
            );

        $wasResolved =
            !empty(
                $existing['resolved_at']
            );

        $becameMoreImportant =
            $count > $oldCount
            || $wasResolved;

        $stmt = $pdo->prepare(
            "UPDATE admin_notifications
             SET title=:title,
                 message=:message,
                 target_url=:target_url,
                 icon=:icon,
                 level=:level,
                 source_count=:source_count,
                 resolved_at=NULL,
                 is_read=:is_read,
                 read_at=:read_at,
                 updated_at=
                    CASE
                        WHEN :touch_updated=1 THEN NOW()
                        ELSE updated_at
                    END
             WHERE id=:id"
        );

        $stmt->execute([
            'title' =>
                self::withCount(
                    $title,
                    $count
                ),
            'message' =>
                $message,
            'target_url' =>
                self::cut(
                    $targetUrl,
                    500
                ),
            'icon' =>
                self::cut(
                    $icon,
                    80
                ),
            'level' =>
                self::normalizeLevel(
                    $level
                ),
            'source_count' =>
                $count,
            'is_read' =>
                $becameMoreImportant
                    ? 0
                    : (int)(
                        $existing['is_read']
                        ?? 0
                    ),
            'read_at' =>
                $becameMoreImportant
                    ? null
                    : (
                        $existing['read_at']
                        ?? null
                    ),
            'touch_updated' =>
                (
                    $count !== $oldCount
                    || $wasResolved
                )
                    ? 1
                    : 0,
            'id' =>
                (int)$existing['id'],
        ]);
    }

    /**
     * @param array<int,string> $currentKeys
     */
    private static function resolveMissingPending(
        PDO $pdo,
        int $userId,
        array $currentKeys
    ): void {
        $stmt = $pdo->prepare(
            "SELECT id,notification_key
             FROM admin_notifications
             WHERE user_id=:user_id
               AND notification_key LIKE 'pending:%'
               AND resolved_at IS NULL"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
        ]);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        $currentMap =
            array_fill_keys(
                $currentKeys,
                true
            );

        $resolve =
            $pdo->prepare(
                "UPDATE admin_notifications
                 SET resolved_at=NOW(),
                     is_read=1,
                     read_at=COALESCE(read_at,NOW()),
                     updated_at=NOW()
                 WHERE id=:id
                   AND user_id=:user_id"
            );

        foreach ($rows as $row) {
            $key =
                (string)(
                    $row['notification_key']
                    ?? ''
                );

            if (
                $key !== ''
                && !isset(
                    $currentMap[$key]
                )
            ) {
                $resolve->execute([
                    'id' =>
                        (int)$row['id'],
                    'user_id' =>
                        $userId,
                ]);
            }
        }
    }

    private static function findByKey(
        PDO $pdo,
        int $userId,
        string $key
    ): ?array {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM admin_notifications
             WHERE user_id=:user_id
               AND notification_key=:notification_key
             LIMIT 1"
        );

        $stmt->execute([
            'user_id' =>
                $userId,
            'notification_key' =>
                $key,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row ?: null;
    }

    private static function normalizeLevel(
        string $level
    ): string {
        $level =
            strtolower(
                trim(
                    $level
                )
            );

        return
            in_array(
                $level,
                [
                    'primary',
                    'secondary',
                    'success',
                    'danger',
                    'warning',
                    'info',
                ],
                true
            )
                ? $level
                : 'primary';
    }

    private static function withCount(
        string $title,
        int $count
    ): string {
        return
            self::cut(
                trim($title)
                . (
                    $count > 0
                        ? ' (' . $count . ')'
                        : ''
                ),
                220
            );
    }

    private static function cut(
        string $value,
        int $length
    ): string {
        return
            function_exists('mb_substr')
                ? mb_substr(
                    $value,
                    0,
                    $length
                )
                : substr(
                    $value,
                    0,
                    $length
                );
    }
}
