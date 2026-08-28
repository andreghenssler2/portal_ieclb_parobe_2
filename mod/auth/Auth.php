<?php

declare(strict_types=1);

final class Auth
{
    private static string $lastError = '';

    public static function attempt(string $email, string $senha): bool
    {
        self::$lastError = '';
        $pdo = Database::connection();
        $email = strtolower(trim($email));
        $ip = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;

        $maxAttempts = max(3, min(20, (int)siteConfig($pdo, 'security_max_login_attempts', '5')));
        $lockoutMinutes = max(1, min(180, (int)siteConfig($pdo, 'security_lockout_minutes', '15')));

        try {
            if (self::isLoginBlocked($pdo, $email, $ip, $maxAttempts, $lockoutMinutes)) {
                self::$lastError = 'Muitas tentativas de login. Aguarde ' . $lockoutMinutes . ' minuto(s) e tente novamente.';
                self::recordLoginAttempt($pdo, $email, $ip, false);
                logAction($pdo, 'login.bloqueado', 'autenticacao', null, 'E-mail: ' . self::maskEmail($email), 'warning');
                return false;
            }
        } catch (Throwable $e) {
            // Compatibilidade enquanto a migração da v0.20.0 ainda não foi executada.
        }

        $stmt = $pdo->prepare(
            'SELECT u.*, p.nome AS perfil_nome, p.slug AS perfil_slug
             FROM usuarios u
             INNER JOIN perfis p ON p.id = u.perfil_id
             WHERE u.email = :email AND u.ativo = 1
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha'])) {
            self::$lastError = 'E-mail ou senha inválidos.';
            try {
                self::recordLoginAttempt($pdo, $email, $ip, false);
                if (siteConfig($pdo, 'security_log_failed_logins', '1') === '1') {
                    logAction($pdo, 'login.falhou', 'autenticacao', null, 'E-mail: ' . self::maskEmail($email), 'warning');
                }
            } catch (Throwable $e) {
                // Falha de auditoria não altera o retorno do login.
            }
            return false;
        }

        Session::regenerate();
        unset($user['senha']);
        $user['permissoes'] = self::loadPermissions($pdo, (int)$user['perfil_id'], (string)$user['perfil_slug']);
        $_SESSION['auth_user'] = $user;
        Session::touchAdminActivity();

        $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        try {
            self::recordLoginAttempt($pdo, $email, $ip, true);
            $pdo->prepare('DELETE FROM login_tentativas WHERE email = :email AND sucesso = 0')
                ->execute(['email' => $email]);
            logAction($pdo, 'login.sucesso', 'usuario', (int)$user['id'], 'Acesso administrativo realizado.');
        } catch (Throwable $e) {
            // Compatibilidade durante atualização.
        }

        return true;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function check(): bool
    {
        return isset($_SESSION['auth_user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['auth_user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['auth_user']['id']) ? (int)$_SESSION['auth_user']['id'] : null;
    }

    public static function isAdmin(): bool
    {
        return (string)($_SESSION['auth_user']['perfil_slug'] ?? '') === 'administrador';
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        if (!isset($_SESSION['auth_user']['permissoes']) || !is_array($_SESSION['auth_user']['permissoes'])) {
            try {
                $pdo = Database::connection();
                $_SESSION['auth_user']['permissoes'] = self::loadPermissions(
                    $pdo,
                    (int)($_SESSION['auth_user']['perfil_id'] ?? 0),
                    (string)($_SESSION['auth_user']['perfil_slug'] ?? '')
                );
            } catch (Throwable $e) {
                $_SESSION['auth_user']['permissoes'] = [];
            }
        }

        return in_array($permission, $_SESSION['auth_user']['permissoes'], true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/admin/login.php');
            exit;
        }
        Session::touchAdminActivity();
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (self::can($permission)) {
            return;
        }

        try {
            logAction(
                Database::connection(),
                'acesso.negado',
                'permissao',
                null,
                'Permissão exigida: ' . $permission,
                'warning'
            );
        } catch (Throwable $e) {
            // Não interrompe o redirecionamento.
        }

        Session::flash('error', 'Você não possui permissão para acessar este módulo.');
        header('Location: ' . rtrim(BASE_URL, '/') . '/admin/index.php');
        exit;
    }

    public static function refresh(): void
    {
        $id = self::id();
        if (!$id) {
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.*, p.nome AS perfil_nome, p.slug AS perfil_slug
             FROM usuarios u
             INNER JOIN perfis p ON p.id = u.perfil_id
             WHERE u.id = :id AND u.ativo = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            self::logout();
            return;
        }

        unset($user['senha']);
        $user['permissoes'] = self::loadPermissions($pdo, (int)$user['perfil_id'], (string)$user['perfil_slug']);
        $_SESSION['auth_user'] = $user;
        Session::touchAdminActivity();
    }

    public static function logout(): void
    {
        if (self::check()) {
            try {
                logAction(Database::connection(), 'logout', 'usuario', self::id(), 'Sessão administrativa encerrada.');
            } catch (Throwable $e) {
                // Continua o logout.
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    private static function isLoginBlocked(PDO $pdo, string $email, ?string $ip, int $maxAttempts, int $minutes): bool
    {
        $stmt = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN email = :email THEN 1 ELSE 0 END) AS email_falhas,
                SUM(CASE WHEN ip = :ip THEN 1 ELSE 0 END) AS ip_falhas
             FROM login_tentativas
             WHERE sucesso = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$minutes . ' MINUTE)
               AND (email = :email2 OR ip = :ip2)'
        );
        $stmt->execute([
            'email' => $email,
            'ip' => $ip ?? '',
            'email2' => $email,
            'ip2' => $ip ?? '',
        ]);
        $row = $stmt->fetch() ?: [];
        $emailFailures = (int)($row['email_falhas'] ?? 0);
        $ipFailures = (int)($row['ip_falhas'] ?? 0);

        return $emailFailures >= $maxAttempts || ($ip !== null && $ipFailures >= ($maxAttempts * 3));
    }

    private static function recordLoginAttempt(PDO $pdo, string $email, ?string $ip, bool $success): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO login_tentativas (email, ip, sucesso, user_agent)
             VALUES (:email, :ip, :sucesso, :user_agent)'
        );
        $stmt->execute([
            'email' => mb_substr($email, 0, 190),
            'ip' => $ip,
            'sucesso' => $success ? 1 : 0,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    }

    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return mb_substr($email, 0, 2) . '***';
        }
        $local = $parts[0];
        return mb_substr($local, 0, min(2, mb_strlen($local))) . '***@' . $parts[1];
    }

    private static function loadPermissions(PDO $pdo, int $perfilId, string $perfilSlug): array
    {
        if ($perfilSlug === 'administrador') {
            return ['*'];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT p.slug
                 FROM permissoes p
                 INNER JOIN perfil_permissoes pp ON pp.permissao_id = p.id
                 WHERE pp.perfil_id = :perfil_id
                 ORDER BY p.ordem, p.id'
            );
            $stmt->execute(['perfil_id' => $perfilId]);
            return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            return [];
        }
    }
}
