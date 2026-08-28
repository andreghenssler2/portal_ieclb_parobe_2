<?php

declare(strict_types=1);

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }

        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $message;
    }

    public static function touchAdminActivity(): void
    {
        if (isset($_SESSION['auth_user']['id'])) {
            $_SESSION['_admin_last_activity'] = time();
        }
    }

    /**
     * Encerra apenas a autenticação administrativa quando o tempo de inatividade expira.
     * Retorna true quando houve expiração.
     */
    public static function enforceIdleTimeout(int $minutes): bool
    {
        if (!isset($_SESSION['auth_user']['id'])) {
            return false;
        }

        $minutes = max(5, min(1440, $minutes));
        $now = time();
        $last = (int)($_SESSION['_admin_last_activity'] ?? $now);

        if (($now - $last) > ($minutes * 60)) {
            unset($_SESSION['auth_user'], $_SESSION['_admin_last_activity']);
            self::regenerate();
            self::flash('error', 'Sua sessão expirou por inatividade. Entre novamente.');
            return true;
        }

        $_SESSION['_admin_last_activity'] = $now;
        return false;
    }
}
