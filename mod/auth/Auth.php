<?php

declare(strict_types=1);

final class Auth
{
    public static function attempt(string $email, string $senha): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.*, p.nome AS perfil_nome, p.slug AS perfil_slug
             FROM usuarios u
             INNER JOIN perfis p ON p.id = u.perfil_id
             WHERE u.email = :email AND u.ativo = 1
             LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha'])) {
            return false;
        }

        Session::regenerate();
        unset($user['senha']);
        $user['permissoes'] = self::loadPermissions($pdo, (int)$user['perfil_id'], (string)$user['perfil_slug']);
        $_SESSION['auth_user'] = $user;

        $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        return true;
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
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (self::can($permission)) {
            return;
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
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
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
            // Compatibilidade durante a atualização: antes da v0.5.0 as tabelas ainda não existem.
            return [];
        }
    }
}
