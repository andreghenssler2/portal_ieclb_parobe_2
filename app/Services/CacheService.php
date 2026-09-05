<?php

declare(strict_types=1);

/**
 * Cache simples em arquivos para reduzir consultas e acelerar páginas públicas.
 *
 * O cache nunca armazena sessões, painel administrativo ou cabeçalhos HTTP.
 * Os arquivos ficam em storage/cache, protegido contra acesso pela web.
 */
final class CacheService
{
    private static bool $configured = false;
    private static bool $enabled = true;
    private static bool $pageCacheEnabled = true;
    private static int $defaultTtl = 300;
    private static int $pageTtl = 120;
    private static ?string $cacheDir = null;

    public static function configure(PDO $pdo): void
    {
        self::initPath();

        $settings = [
            'performance_cache_enabled' => '1',
            'performance_page_cache_enabled' => '1',
            'performance_cache_ttl_seconds' => '300',
            'performance_page_cache_ttl_seconds' => '120',
        ];

        try {
            $stmt = $pdo->query(
                "SELECT chave,valor FROM configuracoes WHERE chave IN (" .
                "'performance_cache_enabled','performance_page_cache_enabled'," .
                "'performance_cache_ttl_seconds','performance_page_cache_ttl_seconds')"
            );
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $key = (string)($row['chave'] ?? '');
                if ($key !== '') {
                    $settings[$key] = (string)($row['valor'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // Durante instalação/migração mantém os valores seguros padrão.
        }

        self::$enabled = $settings['performance_cache_enabled'] !== '0';
        self::$pageCacheEnabled = $settings['performance_page_cache_enabled'] !== '0';
        self::$defaultTtl = max(30, min(86400, (int)$settings['performance_cache_ttl_seconds']));
        self::$pageTtl = max(15, min(3600, (int)$settings['performance_page_cache_ttl_seconds']));
        self::$configured = true;
    }

    public static function enabled(): bool
    {
        self::initPath();
        return self::$enabled;
    }

    public static function pageCacheEnabled(): bool
    {
        self::initPath();
        return self::$enabled && self::$pageCacheEnabled;
    }

    public static function defaultTtl(): int
    {
        return self::$defaultTtl;
    }

    public static function pageTtl(): int
    {
        return self::$pageTtl;
    }

    public static function directory(): string
    {
        self::initPath();
        return (string)self::$cacheDir;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::initPath();
        if (!self::$enabled) {
            return $default;
        }

        $file = self::fileForKey($key);
        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            @unlink($file);
            return $default;
        }

        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($file);
            return $default;
        }

        return $payload['value'];
    }

    public static function put(string $key, mixed $value, ?int $ttl = null, string $group = 'default'): bool
    {
        self::initPath();
        if (!self::$enabled) {
            return false;
        }

        $ttl = max(1, min(86400, $ttl ?? self::$defaultTtl));
        $payload = [
            'version' => 1,
            'key' => $key,
            'group' => preg_replace('/[^a-z0-9_.-]+/i', '-', $group) ?: 'default',
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'value' => $value,
        ];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return false;
        }

        $file = self::fileForKey($key);
        $tmp = $file . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($file);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
        }
        return true;
    }

    public static function remember(string $key, callable $loader, ?int $ttl = null, string $group = 'default'): mixed
    {
        $sentinel = new stdClass();
        $cached = self::get($key, $sentinel);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $value = $loader();
        self::put($key, $value, $ttl, $group);
        return $value;
    }

    public static function forget(string $key): void
    {
        self::initPath();
        @unlink(self::fileForKey($key));
    }

    public static function clearGroup(string $group): int
    {
        self::initPath();
        $removed = 0;
        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($payload) && (string)($payload['group'] ?? '') === $group) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    public static function clearAll(): int
    {
        self::initPath();
        $removed = 0;
        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        foreach (glob(self::directory() . '/*.tmp-*') ?: [] as $file) {
            @unlink($file);
        }
        return $removed;
    }

    /**
     * Invalida páginas públicas quando uma operação administrativa pode ter
     * alterado conteúdo. A lista de exclusão evita apagar cache em login/logs.
     */
    public static function invalidateForAction(string $action, ?string $entity = null): void
    {
        self::initPath();
        $action = strtolower(trim($action));
        $entity = strtolower(trim((string)$entity));

        foreach (['login', 'logout', 'visualizar', 'consulta', 'export', 'diagnost', 'teste', 'saude.', 'auditoria.'] as $readOnly) {
            if (str_contains($action, $readOnly)) {
                return;
            }
        }

        $contentHints = [
            'post', 'noticia', 'pagina', 'evento', 'categoria', 'tag', 'midia', 'galeria',
            'comunidade', 'grupo', 'menu', 'banner', 'widget', 'home', 'tema', 'configur',
            'wordpress', 'newsletter', 'formulario', 'lideranca', 'documento', 'lideranca'
        ];
        $haystack = $action . ' ' . $entity;
        foreach ($contentHints as $hint) {
            if (str_contains($haystack, $hint)) {
                self::clearGroup('page');
                self::clearGroup('public');
                self::clearGroup('content-page');
                return;
            }
        }
    }

    /**
     * Cache de página inteira apenas para a Home pública, em GET sem querystring
     * e sem usuário autenticado. O painel e páginas com formulários não entram.
     */
    public static function bootstrapPublicPageCache(PDO $pdo): void
    {
        if (!self::pageCacheEnabled() || PHP_SAPI === 'cli') {
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        if (!empty($_SERVER['QUERY_STRING'])) {
            return;
        }
        if (Auth::check()) {
            return;
        }

        $path = function_exists('currentRelativePath') ? currentRelativePath() : '/';
        if ($path !== '/') {
            return;
        }

        $key = 'page.home.' . hash('sha256', defined('BASE_URL') ? (string)BASE_URL : '/');
        $cached = self::get($key, null);
        if (is_string($cached) && $cached !== '') {
            if (!headers_sent()) {
                header('X-Portal-Cache: HIT');
            }
            echo $cached;
            exit;
        }

        if (!headers_sent()) {
            header('X-Portal-Cache: MISS');
        }

        $ttl = self::$pageTtl;
        ob_start(static function (string $buffer) use ($key, $ttl): string {
            $status = http_response_code();
            if (($status === 200 || $status === false) && trim($buffer) !== '') {
                CacheService::put($key, $buffer, $ttl, 'page');
            }
            return $buffer;
        });
    }

    /** @return array{files:int,bytes:int,expired:int,writable:bool,path:string,oldest:?int,newest:?int} */
    public static function stats(): array
    {
        self::initPath();
        $files = 0;
        $bytes = 0;
        $expired = 0;
        $oldest = null;
        $newest = null;
        $now = time();

        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            $files++;
            $size = @filesize($file);
            if (is_int($size)) {
                $bytes += $size;
            }
            $mtime = @filemtime($file);
            if (is_int($mtime)) {
                $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
                $newest = $newest === null ? $mtime : max($newest, $mtime);
            }
            $raw = @file_get_contents($file);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($payload) && (int)($payload['expires_at'] ?? 0) > 0 && (int)$payload['expires_at'] < $now) {
                $expired++;
            }
        }

        return [
            'files' => $files,
            'bytes' => $bytes,
            'expired' => $expired,
            'writable' => is_dir(self::directory()) && is_writable(self::directory()),
            'path' => self::directory(),
            'oldest' => $oldest,
            'newest' => $newest,
        ];
    }

    public static function cleanupExpired(): int
    {
        self::initPath();
        $removed = 0;
        $now = time();
        foreach (glob(self::directory() . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($payload) || ((int)($payload['expires_at'] ?? 0) > 0 && (int)$payload['expires_at'] < $now)) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private static function initPath(): void
    {
        if (self::$cacheDir !== null) {
            return;
        }
        self::$cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0775, true);
        }
    }

    private static function fileForKey(string $key): string
    {
        return self::directory() . '/' . hash('sha256', $key) . '.cache';
    }
}
