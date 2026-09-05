<?php

declare(strict_types=1);

/**
 * Controle das sessões administrativas do Portal.
 *
 * A v0.83 não armazena o ID bruto da sessão PHP no banco.
 * Cada login recebe um token aleatório próprio e somente o SHA-256 desse
 * token é persistido.
 */
final class SessionSecurityService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                device_label VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                revoked_by_user_id INT UNSIGNED NULL,
                revoke_reason VARCHAR(100) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_sessions_token (token_hash),
                KEY idx_user_sessions_user (user_id,last_seen_at),
                KEY idx_user_sessions_active (user_id,revoked_at,last_seen_at),
                KEY idx_user_sessions_last_seen (last_seen_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    public static function registerCurrent(
        PDO $pdo,
        int $userId,
        bool $forceNew = true
    ): void {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        self::ensureSchema($pdo);

        if ($forceNew) {
            $oldHash = self::currentTokenHash();

            if ($oldHash !== '') {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE user_sessions
                         SET revoked_at=COALESCE(revoked_at,NOW()),
                             revoked_by_user_id=COALESCE(revoked_by_user_id,:user_id),
                             revoke_reason=COALESCE(revoke_reason,'novo_login')
                         WHERE token_hash=:token_hash
                           AND revoked_at IS NULL"
                    );

                    $stmt->execute([
                        'user_id' => $userId,
                        'token_hash' => $oldHash,
                    ]);
                } catch (Throwable $ignored) {
                }
            }

            unset(
                $_SESSION['_security_session_token'],
                $_SESSION['_security_session_last_touch'],
                $_SESSION['_security_session_regenerated_at']
            );
        }

        $token = self::currentToken(true);

        if ($token === '') {
            return;
        }

        $hash = hash('sha256', $token);
        $ip = self::ip();
        $userAgent = self::userAgent();
        $device = self::deviceLabel($userAgent);

        $stmt = $pdo->prepare(
            "INSERT INTO user_sessions
                (
                    user_id,
                    token_hash,
                    ip,
                    user_agent,
                    device_label,
                    created_at,
                    last_seen_at,
                    revoked_at,
                    revoked_by_user_id,
                    revoke_reason
                )
             VALUES
                (
                    :user_id,
                    :token_hash,
                    :ip,
                    :user_agent,
                    :device_label,
                    NOW(),
                    NOW(),
                    NULL,
                    NULL,
                    NULL
                )
             ON DUPLICATE KEY UPDATE
                user_id=VALUES(user_id),
                ip=VALUES(ip),
                user_agent=VALUES(user_agent),
                device_label=VALUES(device_label),
                last_seen_at=NOW()"
        );

        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $hash,
            'ip' => $ip !== '' ? $ip : null,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'device_label' => $device !== '' ? $device : null,
        ]);

        $_SESSION['_security_session_last_touch'] = time();
        $_SESSION['_security_session_regenerated_at'] = time();
    }

    /**
     * Valida revogação e atualiza o último acesso.
     *
     * Sessões criadas antes da instalação da v0.83 são registradas
     * automaticamente na primeira requisição autenticada.
     */
    public static function validateAndTouch(
        PDO $pdo,
        int $userId,
        int $timeoutMinutes
    ): bool {
        if ($userId <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        self::ensureSchema($pdo);

        $timeoutMinutes =
            max(
                5,
                min(
                    1440,
                    $timeoutMinutes
                )
            );

        self::cleanupExpired(
            $pdo,
            $timeoutMinutes
        );

        $token = self::currentToken(false);

        if ($token === '') {
            self::registerCurrent(
                $pdo,
                $userId,
                false
            );

            $token = self::currentToken(false);
        }

        if ($token === '') {
            return true;
        }

        $hash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            "SELECT
                id,
                user_id,
                last_seen_at,
                revoked_at,
                revoke_reason
             FROM user_sessions
             WHERE token_hash=:token_hash
             LIMIT 1"
        );

        $stmt->execute([
            'token_hash' => $hash,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$row) {
            self::registerCurrent(
                $pdo,
                $userId,
                false
            );

            return true;
        }

        if (
            (int)($row['user_id'] ?? 0) !== $userId
            || !empty($row['revoked_at'])
        ) {
            self::clearLocalAuthentication(
                'Esta sessão foi encerrada. Entre novamente para continuar.'
            );

            return false;
        }

        $lastSeen =
            strtotime(
                (string)(
                    $row['last_seen_at']
                    ?? ''
                )
            );

        if (
            $lastSeen !== false
            && (time() - $lastSeen) > ($timeoutMinutes * 60)
        ) {
            self::revokeByTokenHash(
                $pdo,
                $hash,
                $userId,
                'inatividade'
            );

            self::clearLocalAuthentication(
                'Sua sessão expirou por inatividade. Entre novamente.'
            );

            return false;
        }

        $lastTouch =
            (int)(
                $_SESSION['_security_session_last_touch']
                ?? 0
            );

        if (
            $lastTouch <= 0
            || (time() - $lastTouch) >= 60
        ) {
            $stmt = $pdo->prepare(
                "UPDATE user_sessions
                 SET last_seen_at=NOW(),
                     ip=:ip,
                     user_agent=:user_agent,
                     device_label=:device_label
                 WHERE id=:id
                   AND revoked_at IS NULL"
            );

            $userAgent = self::userAgent();

            $stmt->execute([
                'ip' =>
                    self::ip() !== ''
                        ? self::ip()
                        : null,
                'user_agent' =>
                    $userAgent !== ''
                        ? $userAgent
                        : null,
                'device_label' =>
                    self::deviceLabel($userAgent),
                'id' =>
                    (int)$row['id'],
            ]);

            $_SESSION['_security_session_last_touch'] =
                time();
        }

        /*
         * Renovação periódica do ID da sessão PHP.
         * O token de controle v0.83 permanece o mesmo, então a sessão continua
         * identificável no painel mesmo após session_regenerate_id().
         */
        $regeneratedAt =
            (int)(
                $_SESSION['_security_session_regenerated_at']
                ?? 0
            );

        if (
            $regeneratedAt <= 0
            || (time() - $regeneratedAt) >= 900
        ) {
            try {
                Session::regenerate();

                $_SESSION['_security_session_regenerated_at'] =
                    time();
            } catch (Throwable $ignored) {
            }
        }

        return true;
    }

    public static function revokeCurrent(
        PDO $pdo,
        int $actorUserId,
        string $reason = 'logout'
    ): void {
        $hash = self::currentTokenHash();

        if ($hash === '') {
            return;
        }

        self::ensureSchema($pdo);

        self::revokeByTokenHash(
            $pdo,
            $hash,
            $actorUserId,
            $reason
        );
    }

    public static function revokeSession(
        PDO $pdo,
        int $sessionId,
        int $actorUserId,
        ?int $expectedUserId = null
    ): bool {
        if ($sessionId <= 0 || $actorUserId <= 0) {
            return false;
        }

        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT id,user_id,token_hash,revoked_at
             FROM user_sessions
             WHERE id=:id
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $sessionId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$row) {
            return false;
        }

        if (
            $expectedUserId !== null
            && (int)$row['user_id'] !== $expectedUserId
        ) {
            return false;
        }

        if (
            hash_equals(
                self::currentTokenHash(),
                (string)$row['token_hash']
            )
        ) {
            return false;
        }

        if (!empty($row['revoked_at'])) {
            return true;
        }

        $stmt = $pdo->prepare(
            "UPDATE user_sessions
             SET revoked_at=NOW(),
                 revoked_by_user_id=:actor,
                 revoke_reason='manual'
             WHERE id=:id
               AND revoked_at IS NULL"
        );

        $stmt->execute([
            'actor' => $actorUserId,
            'id' => $sessionId,
        ]);

        return
            $stmt->rowCount() > 0;
    }

    public static function revokeOtherSessions(
        PDO $pdo,
        int $userId,
        int $actorUserId,
        string $reason = 'encerrar_outras'
    ): int {
        if ($userId <= 0 || $actorUserId <= 0) {
            return 0;
        }

        self::ensureSchema($pdo);

        $currentHash =
            self::currentTokenHash();

        $sql =
            "UPDATE user_sessions
             SET revoked_at=NOW(),
                 revoked_by_user_id=:actor,
                 revoke_reason=:reason
             WHERE user_id=:user_id
               AND revoked_at IS NULL";

        $params = [
            'actor' => $actorUserId,
            'reason' => self::cut($reason, 100),
            'user_id' => $userId,
        ];

        if ($currentHash !== '') {
            $sql .=
                " AND token_hash<>:current_hash";

            $params['current_hash'] =
                $currentHash;
        }

        $stmt =
            $pdo->prepare(
                $sql
            );

        $stmt->execute($params);

        return
            $stmt->rowCount();
    }

    public static function revokeAllForUser(
        PDO $pdo,
        int $targetUserId,
        int $actorUserId,
        bool $keepCurrent = false
    ): int {
        if ($targetUserId <= 0 || $actorUserId <= 0) {
            return 0;
        }

        self::ensureSchema($pdo);

        $sql =
            "UPDATE user_sessions
             SET revoked_at=NOW(),
                 revoked_by_user_id=:actor,
                 revoke_reason='admin'
             WHERE user_id=:user_id
               AND revoked_at IS NULL";

        $params = [
            'actor' => $actorUserId,
            'user_id' => $targetUserId,
        ];

        if (
            $keepCurrent
            && $targetUserId === $actorUserId
            && self::currentTokenHash() !== ''
        ) {
            $sql .=
                " AND token_hash<>:current_hash";

            $params['current_hash'] =
                self::currentTokenHash();
        }

        $stmt =
            $pdo->prepare(
                $sql
            );

        $stmt->execute($params);

        return
            $stmt->rowCount();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function sessionsForUser(
        PDO $pdo,
        int $userId,
        int $timeoutMinutes,
        int $limit = 50
    ): array {
        self::ensureSchema($pdo);
        self::cleanupExpired($pdo, $timeoutMinutes);

        $limit =
            max(
                1,
                min(
                    200,
                    $limit
                )
            );

        $stmt = $pdo->prepare(
            "SELECT *
             FROM user_sessions
             WHERE user_id=:user_id
             ORDER BY
                CASE WHEN revoked_at IS NULL THEN 0 ELSE 1 END,
                last_seen_at DESC,
                id DESC
             LIMIT {$limit}"
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            self::decorateRows(
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function allActiveSessions(
        PDO $pdo,
        int $timeoutMinutes,
        int $limit = 250
    ): array {
        self::ensureSchema($pdo);
        self::cleanupExpired($pdo, $timeoutMinutes);

        $limit =
            max(
                1,
                min(
                    500,
                    $limit
                )
            );

        $stmt = $pdo->query(
            "SELECT
                s.*,
                u.nome AS usuario_nome,
                u.email AS usuario_email,
                p.nome AS perfil_nome
             FROM user_sessions s
             INNER JOIN usuarios u
                ON u.id=s.user_id
             LEFT JOIN perfis p
                ON p.id=u.perfil_id
             WHERE s.revoked_at IS NULL
             ORDER BY s.last_seen_at DESC,s.id DESC
             LIMIT {$limit}"
        );

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            self::decorateRows(
                $rows
            );
    }

    public static function cleanupExpired(
        PDO $pdo,
        int $timeoutMinutes
    ): int {
        self::ensureSchema($pdo);

        $timeoutMinutes =
            max(
                5,
                min(
                    1440,
                    $timeoutMinutes
                )
            );

        $stmt = $pdo->prepare(
            "UPDATE user_sessions
             SET revoked_at=NOW(),
                 revoke_reason='inatividade'
             WHERE revoked_at IS NULL
               AND last_seen_at < DATE_SUB(
                    NOW(),
                    INTERVAL {$timeoutMinutes} MINUTE
               )"
        );

        $stmt->execute();

        return
            $stmt->rowCount();
    }

    public static function currentSessionDatabaseId(
        PDO $pdo
    ): int {
        $hash = self::currentTokenHash();

        if ($hash === '') {
            return 0;
        }

        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "SELECT id
             FROM user_sessions
             WHERE token_hash=:token_hash
             LIMIT 1"
        );

        $stmt->execute([
            'token_hash' => $hash,
        ]);

        return
            (int)(
                $stmt->fetchColumn()
                ?: 0
            );
    }

    private static function revokeByTokenHash(
        PDO $pdo,
        string $hash,
        int $actorUserId,
        string $reason
    ): void {
        if ($hash === '') {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE user_sessions
             SET revoked_at=COALESCE(revoked_at,NOW()),
                 revoked_by_user_id=COALESCE(revoked_by_user_id,:actor),
                 revoke_reason=COALESCE(revoke_reason,:reason)
             WHERE token_hash=:token_hash"
        );

        $stmt->execute([
            'actor' =>
                $actorUserId > 0
                    ? $actorUserId
                    : null,
            'reason' =>
                self::cut(
                    $reason,
                    100
                ),
            'token_hash' =>
                $hash,
        ]);
    }

    private static function clearLocalAuthentication(
        string $message
    ): void {
        unset(
            $_SESSION['auth_user'],
            $_SESSION['auth_2fa_pending'],
            $_SESSION['_admin_last_activity'],
            $_SESSION['_security_session_token'],
            $_SESSION['_security_session_last_touch'],
            $_SESSION['_security_session_regenerated_at']
        );

        try {
            Session::regenerate();
        } catch (Throwable $ignored) {
        }

        Session::flash(
            'error',
            $message
        );
    }

    private static function currentToken(
        bool $create
    ): string {
        $token =
            trim(
                (string)(
                    $_SESSION['_security_session_token']
                    ?? ''
                )
            );

        if (
            $token === ''
            && $create
        ) {
            $token =
                bin2hex(
                    random_bytes(32)
                );

            $_SESSION['_security_session_token'] =
                $token;
        }

        return $token;
    }

    private static function currentTokenHash(): string
    {
        $token =
            self::currentToken(false);

        return
            $token !== ''
                ? hash(
                    'sha256',
                    $token
                )
                : '';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function decorateRows(
        array $rows
    ): array {
        $currentHash =
            self::currentTokenHash();

        foreach ($rows as &$row) {
            $row['is_current'] =
                $currentHash !== ''
                && hash_equals(
                    $currentHash,
                    (string)(
                        $row['token_hash']
                        ?? ''
                    )
                );

            if (
                empty(
                    $row['device_label']
                )
            ) {
                $row['device_label'] =
                    self::deviceLabel(
                        (string)(
                            $row['user_agent']
                            ?? ''
                        )
                    );
            }
        }

        unset($row);

        return $rows;
    }

    private static function ip(): string
    {
        return
            self::cut(
                trim(
                    (string)(
                        $_SERVER['REMOTE_ADDR']
                        ?? ''
                    )
                ),
                45
            );
    }

    private static function userAgent(): string
    {
        return
            self::cut(
                trim(
                    (string)(
                        $_SERVER['HTTP_USER_AGENT']
                        ?? ''
                    )
                ),
                500
            );
    }

    private static function deviceLabel(
        string $userAgent
    ): string {
        if ($userAgent === '') {
            return 'Dispositivo não identificado';
        }

        $browser =
            match (true) {
                str_contains($userAgent, 'Edg/') =>
                    'Microsoft Edge',
                str_contains($userAgent, 'OPR/')
                    || str_contains($userAgent, 'Opera') =>
                    'Opera',
                str_contains($userAgent, 'Firefox/') =>
                    'Firefox',
                str_contains($userAgent, 'Chrome/') =>
                    'Chrome',
                str_contains($userAgent, 'Safari/')
                    && !str_contains($userAgent, 'Chrome/') =>
                    'Safari',
                default =>
                    'Navegador',
            };

        $system =
            match (true) {
                str_contains($userAgent, 'Windows') =>
                    'Windows',
                str_contains($userAgent, 'Android') =>
                    'Android',
                str_contains($userAgent, 'iPhone')
                    || str_contains($userAgent, 'iPad') =>
                    'iOS/iPadOS',
                str_contains($userAgent, 'Mac OS X')
                    || str_contains($userAgent, 'Macintosh') =>
                    'macOS',
                str_contains($userAgent, 'Linux') =>
                    'Linux',
                default =>
                    'dispositivo',
            };

        return
            self::cut(
                $browser
                . ' · '
                . $system,
                190
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
