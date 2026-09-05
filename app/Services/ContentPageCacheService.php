<?php

declare(strict_types=1);

/**
 * Cache de página por conteúdo público.
 *
 * A Home continua usando CacheService::bootstrapPublicPageCache().
 * Esta camada cobre Notícias e Páginas individuais, mas somente quando:
 *
 * - requisição GET;
 * - sem query string;
 * - visitante não autenticado;
 * - cache persistente e cache de páginas estão ativos;
 * - o conteúdo não contém formulário incorporado/HTML de formulário.
 *
 * Notícias iniciam esta camada depois do registro de visualização, portanto
 * o cache não impede a contagem de acessos pelo NewsAnalyticsService.
 */
final class ContentPageCacheService
{
    private const GROUP =
        'content-page';

    private static bool $configured =
        false;

    private static bool $enabled =
        true;

    private static int $ttl =
        300;

    public static function configure(
        PDO $pdo
    ): void {
        try {
            self::$enabled =
                siteConfig(
                    $pdo,
                    'performance_content_page_cache_enabled',
                    '1'
                ) !== '0';

            self::$ttl =
                max(
                    30,
                    min(
                        3600,
                        (int)siteConfig(
                            $pdo,
                            'performance_content_page_cache_ttl_seconds',
                            '300'
                        )
                    )
                );
        } catch (Throwable $ignored) {
            self::$enabled = true;
            self::$ttl = 300;
        }

        self::$configured = true;
    }

    public static function enabled(
        PDO $pdo
    ): bool {
        self::ensureConfigured(
            $pdo
        );

        return
            self::$enabled
            && CacheService::pageCacheEnabled();
    }

    public static function ttl(
        PDO $pdo
    ): int {
        self::ensureConfigured(
            $pdo
        );

        return
            self::$ttl;
    }

    /**
     * Inicia/serve o cache da página atual.
     *
     * Em HIT esta função encerra a requisição.
     * Em MISS inicia um output buffer que grava o HTML completo ao final.
     *
     * @return bool true quando o cache ficou ativo para esta requisição.
     */
    public static function begin(
        PDO $pdo,
        string $contentType,
        int $contentId,
        string $contentVersion,
        string $rawContent = ''
    ): bool {
        self::ensureConfigured(
            $pdo
        );

        if (
            !self::eligibleRequest(
                $pdo
            )
        ) {
            self::bypassHeader();

            return false;
        }

        $contentType =
            strtolower(
                trim(
                    $contentType
                )
            );

        if (
            !in_array(
                $contentType,
                [
                    'post',
                    'pagina',
                ],
                true
            )
            || $contentId <= 0
        ) {
            self::bypassHeader();

            return false;
        }

        if (
            !self::safeContent(
                $pdo,
                $contentType,
                $contentId,
                $rawContent
            )
        ) {
            if (!headers_sent()) {
                header(
                    'X-Portal-Content-Cache: BYPASS-FORM'
                );
            }

            return false;
        }

        $key =
            self::key(
                $contentType,
                $contentId,
                $contentVersion
            );

        $etag =
            '"pc-'
            . substr(
                hash(
                    'sha256',
                    $key
                ),
                0,
                24
            )
            . '"';

        $cached =
            CacheService::get(
                $key,
                null
            );

        if (
            is_string(
                $cached
            )
            && $cached !== ''
        ) {
            if (!headers_sent()) {
                header(
                    'X-Portal-Content-Cache: HIT'
                );

                header(
                    'ETag: '
                    . $etag
                );

                header(
                    'Cache-Control: public, max-age=0, must-revalidate'
                );

                $timestamp =
                    strtotime(
                        $contentVersion
                    );

                if ($timestamp !== false) {
                    header(
                        'Last-Modified: '
                        . gmdate(
                            'D, d M Y H:i:s',
                            $timestamp
                        )
                        . ' GMT'
                    );
                }
            }

            $ifNoneMatch =
                trim(
                    (string)(
                        $_SERVER['HTTP_IF_NONE_MATCH']
                        ?? ''
                    )
                );

            if (
                $ifNoneMatch !== ''
                && hash_equals(
                    $etag,
                    $ifNoneMatch
                )
            ) {
                http_response_code(
                    304
                );

                exit;
            }

            echo $cached;

            exit;
        }

        if (!headers_sent()) {
            header(
                'X-Portal-Content-Cache: MISS'
            );

            header(
                'ETag: '
                . $etag
            );

            header(
                'Cache-Control: public, max-age=0, must-revalidate'
            );

            $timestamp =
                strtotime(
                    $contentVersion
                );

            if ($timestamp !== false) {
                header(
                    'Last-Modified: '
                    . gmdate(
                        'D, d M Y H:i:s',
                        $timestamp
                    )
                    . ' GMT'
                );
            }
        }

        $ttl =
            self::$ttl;

        ob_start(
            static function (
                string $buffer
            ) use (
                $key,
                $ttl
            ): string {
                $status =
                    http_response_code();

                if (
                    (
                        $status === 200
                        || $status === false
                    )
                    && trim(
                        $buffer
                    ) !== ''
                ) {
                    CacheService::put(
                        $key,
                        $buffer,
                        $ttl,
                        self::GROUP
                    );
                }

                return $buffer;
            }
        );

        return true;
    }

    /**
     * Remove todo o cache de Notícias/Páginas individuais.
     */
    public static function clear(): int
    {
        return
            CacheService::clearGroup(
                self::GROUP
            );
    }

    /**
     * @return array{
     *   files:int,
     *   bytes:int,
     *   expired:int,
     *   oldest:?int,
     *   newest:?int
     * }
     */
    public static function stats(): array
    {
        $files = 0;
        $bytes = 0;
        $expired = 0;
        $oldest = null;
        $newest = null;
        $now = time();

        foreach (
            glob(
                CacheService::directory()
                . '/*.cache'
            )
            ?: []
            as $file
        ) {
            $raw =
                @file_get_contents(
                    $file
                );

            $payload =
                is_string(
                    $raw
                )
                    ? json_decode(
                        $raw,
                        true
                    )
                    : null;

            if (
                !is_array(
                    $payload
                )
                || (string)(
                    $payload['group']
                    ?? ''
                ) !== self::GROUP
            ) {
                continue;
            }

            $files++;

            $size =
                @filesize(
                    $file
                );

            if (is_int($size)) {
                $bytes +=
                    $size;
            }

            $mtime =
                @filemtime(
                    $file
                );

            if (is_int($mtime)) {
                $oldest =
                    $oldest === null
                        ? $mtime
                        : min(
                            $oldest,
                            $mtime
                        );

                $newest =
                    $newest === null
                        ? $mtime
                        : max(
                            $newest,
                            $mtime
                        );
            }

            $expiresAt =
                (int)(
                    $payload['expires_at']
                    ?? 0
                );

            if (
                $expiresAt > 0
                && $expiresAt < $now
            ) {
                $expired++;
            }
        }

        return [
            'files' =>
                $files,
            'bytes' =>
                $bytes,
            'expired' =>
                $expired,
            'oldest' =>
                $oldest,
            'newest' =>
                $newest,
        ];
    }

    private static function ensureConfigured(
        PDO $pdo
    ): void {
        if (!self::$configured) {
            self::configure(
                $pdo
            );
        }
    }

    private static function eligibleRequest(
        PDO $pdo
    ): bool {
        if (
            PHP_SAPI === 'cli'
            || !self::$enabled
            || !CacheService::pageCacheEnabled()
        ) {
            return false;
        }

        if (
            strtoupper(
                (string)(
                    $_SERVER['REQUEST_METHOD']
                    ?? 'GET'
                )
            ) !== 'GET'
        ) {
            return false;
        }

        if (
            !empty(
                $_SERVER['QUERY_STRING']
            )
        ) {
            return false;
        }

        if (
            Auth::check()
        ) {
            return false;
        }

        return true;
    }

    private static function safeContent(
        PDO $pdo,
        string $contentType,
        int $contentId,
        string $rawContent
    ): bool {
        $rawLower =
            strtolower(
                $rawContent
            );

        foreach (
            [
                '<form',
                'formulario-enviar.php',
                'csrf::',
                'csrf_token',
                '_token',
            ]
            as $unsafe
        ) {
            if (
                $rawLower !== ''
                && str_contains(
                    $rawLower,
                    $unsafe
                )
            ) {
                return false;
            }
        }

        /*
         * O bloco de formulário incorporado contém CSRF e honeypot.
         * Não pode ser armazenado como HTML compartilhado.
         */
        try {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM conteudo_blocos
                 WHERE tipo_conteudo=:tipo
                   AND conteudo_id=:id
                   AND tipo_bloco='portal_form_embed'
                 LIMIT 1"
            );

            $stmt->execute([
                'tipo' =>
                    $contentType,
                'id' =>
                    $contentId,
            ]);

            if (
                $stmt->fetchColumn()
            ) {
                return false;
            }
        } catch (Throwable $ignored) {
            /*
             * Em banco legado sem a tabela, o conteúdo continua elegível.
             * O HTML bruto acima ainda protege formulários inseridos no corpo.
             */
        }

        return true;
    }

    private static function key(
        string $contentType,
        int $contentId,
        string $contentVersion
    ): string {
        $base =
            defined('BASE_URL')
                ? (string)BASE_URL
                : '/';

        return
            'content-page.'
            . $contentType
            . '.'
            . $contentId
            . '.'
            . hash(
                'sha256',
                $base
                . '|'
                . $contentVersion
            );
    }

    private static function bypassHeader(): void
    {
        if (!headers_sent()) {
            header(
                'X-Portal-Content-Cache: BYPASS'
            );
        }
    }
}
