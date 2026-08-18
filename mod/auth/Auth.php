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
        return isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . BASE_URL . '/admin/login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
