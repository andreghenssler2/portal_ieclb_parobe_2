<?php

declare(strict_types=1);

/**
 * Diagnóstico de desempenho v0.96.0.
 *
 * Somente leitura, exceto pelo benchmark de cache que grava uma chave
 * temporária e a remove imediatamente em seguida.
 */
final class PerformanceHealthService
{
    /**
     * @return array<string,mixed>
     */
    public static function report(PDO $pdo): array
    {
        $cacheStats = class_exists('CacheService')
            ? CacheService::stats()
            : [
                'files' => 0,
                'bytes' => 0,
                'expired' => 0,
                'writable' => false,
                'path' => '',
                'oldest' => null,
                'newest' => null,
            ];

        $cacheEnabled = class_exists('CacheService')
            ? CacheService::enabled()
            : false;

        $pageCacheEnabled = class_exists('CacheService')
            ? CacheService::pageCacheEnabled()
            : false;

        $defaultTtl = class_exists('CacheService')
            ? CacheService::defaultTtl()
            : 0;

        $pageTtl = class_exists('CacheService')
            ? CacheService::pageTtl()
            : 0;

        $opcacheEnabled =
            function_exists('opcache_get_status')
            && filter_var(
                ini_get('opcache.enable'),
                FILTER_VALIDATE_BOOLEAN
            );

        $opcacheStatus = null;

        if ($opcacheEnabled) {
            try {
                $status = @opcache_get_status(false);

                if (is_array($status)) {
                    $opcacheStatus = [
                        'enabled' => !empty($status['opcache_enabled']),
                        'cache_full' => !empty($status['cache_full']),
                        'restart_pending' => !empty($status['restart_pending']),
                        'restart_in_progress' => !empty($status['restart_in_progress']),
                        'memory_usage' => (array)($status['memory_usage'] ?? []),
                        'statistics' => (array)($status['opcache_statistics'] ?? []),
                    ];
                }
            } catch (Throwable $ignored) {
            }
        }

        $storagePath =
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'storage';

        $warnings = [];
        $errors = [];

        if (!$cacheEnabled) {
            $warnings[] =
                'O cache de dados está desativado.';
        }

        if (!$pageCacheEnabled) {
            $warnings[] =
                'O cache de página pública está desativado.';
        }

        if (empty($cacheStats['writable'])) {
            $errors[] =
                'A pasta de cache não possui permissão de escrita.';
        }

        if ((int)$cacheStats['expired'] > 0) {
            $warnings[] =
                (int)$cacheStats['expired']
                . ' arquivo(s) de cache expirado(s) aguardam limpeza.';
        }

        if (!$opcacheEnabled) {
            $warnings[] =
                'OPcache está desativado neste ambiente PHP.';
        } elseif (
            is_array($opcacheStatus)
            && !empty($opcacheStatus['cache_full'])
        ) {
            $warnings[] =
                'OPcache informa que o cache está cheio.';
        }

        $memoryLimitBytes =
            self::iniBytes(
                (string)ini_get('memory_limit')
            );

        if (
            $memoryLimitBytes > 0
            && $memoryLimitBytes < 128 * 1024 * 1024
        ) {
            $warnings[] =
                'memory_limit está abaixo de 128 MB.';
        }

        $db = self::databaseInfo($pdo);

        return [
            'cache' => [
                'enabled' => $cacheEnabled,
                'page_enabled' => $pageCacheEnabled,
                'default_ttl' => $defaultTtl,
                'page_ttl' => $pageTtl,
                'stats' => $cacheStats,
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => (string)ini_get('memory_limit'),
                'memory_limit_bytes' => $memoryLimitBytes,
                'max_execution_time' => (int)ini_get('max_execution_time'),
                'realpath_cache_size' => (string)ini_get('realpath_cache_size'),
                'realpath_cache_ttl' => (int)ini_get('realpath_cache_ttl'),
                'opcache_enabled' => $opcacheEnabled,
                'opcache' => $opcacheStatus,
            ],
            'database' => $db,
            'filesystem' => [
                'storage_path' => $storagePath,
                'storage_writable' =>
                    is_dir($storagePath)
                    && is_writable($storagePath),
            ],
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * Benchmark rápido e não destrutivo.
     *
     * @return array<string,mixed>
     */
    public static function benchmark(
        PDO $pdo,
        int $iterations = 10
    ): array {
        $iterations =
            max(
                3,
                min(
                    50,
                    $iterations
                )
            );

        $dbTimes = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start =
                hrtime(true);

            $value =
                $pdo->query(
                    'SELECT 1'
                )->fetchColumn();

            $elapsed =
                (
                    hrtime(true)
                    - $start
                )
                / 1_000_000;

            if ((int)$value !== 1) {
                throw new RuntimeException(
                    'O teste SELECT 1 retornou valor inesperado.'
                );
            }

            $dbTimes[] =
                round(
                    $elapsed,
                    3
                );
        }

        $cacheResult = [
            'supported' => class_exists('CacheService'),
            'write_ms' => null,
            'read_ms' => null,
            'verified' => false,
        ];

        if (
            class_exists('CacheService')
            && CacheService::enabled()
        ) {
            $key =
                'performance.health.'
                . bin2hex(
                    random_bytes(8)
                );

            $payload = [
                'token' =>
                    bin2hex(
                        random_bytes(8)
                    ),
                'created_at' =>
                    microtime(true),
            ];

            $start =
                hrtime(true);

            $written =
                CacheService::put(
                    $key,
                    $payload,
                    60,
                    'diagnostic'
                );

            $cacheResult['write_ms'] =
                round(
                    (
                        hrtime(true)
                        - $start
                    )
                    / 1_000_000,
                    3
                );

            $start =
                hrtime(true);

            $read =
                CacheService::get(
                    $key,
                    null
                );

            $cacheResult['read_ms'] =
                round(
                    (
                        hrtime(true)
                        - $start
                    )
                    / 1_000_000,
                    3
                );

            CacheService::forget(
                $key
            );

            $cacheResult['verified'] =
                $written
                && is_array($read)
                && hash_equals(
                    (string)$payload['token'],
                    (string)($read['token'] ?? '')
                );
        }

        sort(
            $dbTimes,
            SORT_NUMERIC
        );

        $count =
            count(
                $dbTimes
            );

        $average =
            $count > 0
                ? array_sum($dbTimes) / $count
                : 0.0;

        $p95Index =
            max(
                0,
                min(
                    $count - 1,
                    (int)ceil(
                        $count * 0.95
                    ) - 1
                )
            );

        return [
            'iterations' => $iterations,
            'database_ms' => [
                'min' =>
                    $count
                        ? round(
                            (float)$dbTimes[0],
                            3
                        )
                        : 0,
                'average' =>
                    round(
                        $average,
                        3
                    ),
                'p95' =>
                    $count
                        ? round(
                            (float)$dbTimes[$p95Index],
                            3
                        )
                        : 0,
                'max' =>
                    $count
                        ? round(
                            (float)$dbTimes[$count - 1],
                            3
                        )
                        : 0,
            ],
            'cache' => $cacheResult,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function databaseInfo(
        PDO $pdo
    ): array {
        $info = [
            'driver' =>
                (string)$pdo->getAttribute(
                    PDO::ATTR_DRIVER_NAME
                ),
            'server_version' => '',
            'connection_status' => '',
            'database' =>
                defined('DB_NAME')
                    ? (string)DB_NAME
                    : '',
        ];

        try {
            $info['server_version'] =
                (string)$pdo->getAttribute(
                    PDO::ATTR_SERVER_VERSION
                );
        } catch (Throwable $ignored) {
        }

        try {
            $info['connection_status'] =
                (string)$pdo->getAttribute(
                    PDO::ATTR_CONNECTION_STATUS
                );
        } catch (Throwable $ignored) {
        }

        return $info;
    }

    private static function iniBytes(
        string $value
    ): int {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
            || $value === '-1'
        ) {
            return -1;
        }

        $last =
            strtolower(
                substr(
                    $value,
                    -1
                )
            );

        $number =
            (float)$value;

        return match ($last) {
            'g' =>
                (int)round(
                    $number
                    * 1024
                    * 1024
                    * 1024
                ),
            'm' =>
                (int)round(
                    $number
                    * 1024
                    * 1024
                ),
            'k' =>
                (int)round(
                    $number
                    * 1024
                ),
            default =>
                (int)$number,
        };
    }
}
