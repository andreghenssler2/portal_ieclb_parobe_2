<?php

declare(strict_types=1);

/**
 * Normaliza embeds externos confiáveis.
 *
 * - Google Maps: mantém as permissões controladas já adotadas pelo Portal.
 * - PDF local aberto por Google Viewer: troca o iframe do Google pelo PDF
 *   hospedado no próprio domínio.
 */
final class TrustedEmbedService
{
    private const GOOGLE_MAPS_SANDBOX = [
        'allow-scripts',
        'allow-same-origin',
        'allow-forms',
        'allow-popups',
        'allow-popups-to-escape-sandbox',
    ];

    public static function normalize(string $html): string
    {
        if (
            trim($html) === ''
            || stripos($html, '<iframe') === false
        ) {
            return $html;
        }

        $normalized =
            preg_replace_callback(
                '~<iframe\b[^>]*>~iu',
                static function (array $match): string {
                    $tag =
                        (string)($match[0] ?? '');

                    if ($tag === '') {
                        return $tag;
                    }

                    $src =
                        self::attribute(
                            $tag,
                            'src'
                        );

                    /*
                     * PORTAL_LOCAL_PDF_EMBED_V114_R2
                     *
                     * Posts antigos do WordPress podem usar o visualizador do
                     * Google para abrir um PDF que, na realidade, já está
                     * hospedado no próprio Portal. O Google pode negar o frame
                     * por X-Frame-Options/CSP. Nesse caso apontamos o iframe
                     * diretamente para o PDF local.
                     */
                    $localPdf =
                        self::localPdfFromGoogleViewer(
                            $src
                        );

                    if ($localPdf !== null) {
                        $tag =
                            self::setAttribute(
                                $tag,
                                'src',
                                $localPdf
                            );

                        /*
                         * O PDF é do próprio Portal. Sandbox não é necessário e
                         * pode impedir o visualizador PDF nativo do navegador.
                         */
                        $tag =
                            self::removeAttribute(
                                $tag,
                                'sandbox'
                            );

                        $tag =
                            self::removeAttribute(
                                $tag,
                                'allow'
                            );

                        $tag =
                            self::removeAttribute(
                                $tag,
                                'allowfullscreen'
                            );

                        if (
                            self::attribute(
                                $tag,
                                'loading'
                            ) === ''
                        ) {
                            $tag =
                                self::setAttribute(
                                    $tag,
                                    'loading',
                                    'lazy'
                                );
                        }

                        if (
                            self::attribute(
                                $tag,
                                'title'
                            ) === ''
                        ) {
                            $tag =
                                self::setAttribute(
                                    $tag,
                                    'title',
                                    'Documento PDF'
                                );
                        }

                        return $tag;
                    }

                    if (
                        !self::isTrustedGoogleMapsEmbed(
                            $src
                        )
                    ) {
                        return $tag;
                    }

                    $sandbox =
                        self::mergeSpaceTokens(
                            self::attribute(
                                $tag,
                                'sandbox'
                            ),
                            self::GOOGLE_MAPS_SANDBOX
                        );

                    $tag =
                        self::setAttribute(
                            $tag,
                            'sandbox',
                            $sandbox
                        );

                    $tag =
                        self::removeAttribute(
                            $tag,
                            'allowfullscreen'
                        );

                    $allow =
                        self::mergeSemicolonTokens(
                            self::attribute(
                                $tag,
                                'allow'
                            ),
                            [
                                'fullscreen',
                            ]
                        );

                    $tag =
                        self::setAttribute(
                            $tag,
                            'allow',
                            $allow
                        );

                    if (
                        self::attribute(
                            $tag,
                            'referrerpolicy'
                        ) === ''
                    ) {
                        $tag =
                            self::setAttribute(
                                $tag,
                                'referrerpolicy',
                                'no-referrer-when-downgrade'
                            );
                    }

                    if (
                        self::attribute(
                            $tag,
                            'loading'
                        ) === ''
                    ) {
                        $tag =
                            self::setAttribute(
                                $tag,
                                'loading',
                                'lazy'
                            );
                    }

                    return $tag;
                },
                $html
            );

        return
            is_string($normalized)
                ? $normalized
                : $html;
    }

    public static function localPdfFromGoogleViewer(
        string $src
    ): ?string {
        $src =
            trim(
                html_entity_decode(
                    $src,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        if ($src === '') {
            return null;
        }

        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }

        $parts =
            parse_url(
                $src
            );

        if (!is_array($parts)) {
            return null;
        }

        $scheme =
            strtolower(
                (string)($parts['scheme'] ?? '')
            );

        $host =
            strtolower(
                (string)($parts['host'] ?? '')
            );

        $path =
            strtolower(
                '/' . ltrim(
                    (string)($parts['path'] ?? ''),
                    '/'
                )
            );

        if (
            !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            return null;
        }

        if (
            !in_array(
                $host,
                [
                    'docs.google.com',
                    'drive.google.com',
                ],
                true
            )
        ) {
            return null;
        }

        if (
            !str_contains(
                $path,
                'viewer'
            )
            && !str_contains(
                $path,
                'gview'
            )
        ) {
            return null;
        }

        $query = [];

        parse_str(
            (string)($parts['query'] ?? ''),
            $query
        );

        $candidate =
            trim(
                (string)(
                    $query['url']
                    ?? $query['file']
                    ?? ''
                )
            );

        if ($candidate === '') {
            return null;
        }

        /*
         * Alguns imports antigos deixam o valor codificado mais de uma vez.
         */
        for ($i = 0; $i < 2; $i++) {
            $decoded =
                rawurldecode(
                    $candidate
                );

            if ($decoded === $candidate) {
                break;
            }

            $candidate = $decoded;
        }

        $candidate =
            trim(
                html_entity_decode(
                    $candidate,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        if (
            !self::isLocalPdfUrl(
                $candidate
            )
        ) {
            return null;
        }

        return $candidate;
    }

    private static function isLocalPdfUrl(
        string $url
    ): bool {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if (
            str_starts_with(
                $url,
                '/'
            )
            && !str_starts_with(
                $url,
                '//'
            )
        ) {
            $path =
                parse_url(
                    $url,
                    PHP_URL_PATH
                );

            return
                is_string($path)
                && preg_match(
                    '~\.pdf$~i',
                    $path
                ) === 1;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parts =
            parse_url(
                $url
            );

        if (!is_array($parts)) {
            return false;
        }

        $scheme =
            strtolower(
                (string)($parts['scheme'] ?? '')
            );

        $host =
            self::normalizeHost(
                (string)($parts['host'] ?? '')
            );

        $path =
            (string)($parts['path'] ?? '');

        if (
            !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            return false;
        }

        if (
            preg_match(
                '~\.pdf$~i',
                $path
            ) !== 1
        ) {
            return false;
        }

        $allowedHosts =
            self::localHosts();

        return
            $host !== ''
            && in_array(
                $host,
                $allowedHosts,
                true
            );
    }

    /**
     * @return array<int,string>
     */
    private static function localHosts(): array
    {
        $hosts = [];

        if (
            defined('BASE_URL')
            && (string)BASE_URL !== ''
        ) {
            $baseHost =
                parse_url(
                    (string)BASE_URL,
                    PHP_URL_HOST
                );

            if (
                is_string($baseHost)
                && $baseHost !== ''
            ) {
                $hosts[] =
                    self::normalizeHost(
                        $baseHost
                    );
            }
        }

        $requestHost =
            trim(
                (string)(
                    $_SERVER['HTTP_HOST']
                    ?? ''
                )
            );

        if ($requestHost !== '') {
            $requestHost =
                preg_replace(
                    '/:\d+$/',
                    '',
                    $requestHost
                )
                ?? $requestHost;

            $hosts[] =
                self::normalizeHost(
                    $requestHost
                );
        }

        /*
         * Compatibilidade com os conteúdos importados do WordPress anterior.
         */
        $hosts[] = 'ieclbparobe.com.br';

        return
            array_values(
                array_unique(
                    array_filter(
                        $hosts
                    )
                )
            );
    }

    private static function normalizeHost(
        string $host
    ): string {
        $host =
            strtolower(
                trim(
                    $host
                )
            );

        if (str_starts_with($host, 'www.')) {
            $host =
                substr(
                    $host,
                    4
                );
        }

        return $host;
    }

    public static function isTrustedGoogleMapsEmbed(
        string $src
    ): bool {
        $src =
            trim(
                html_entity_decode(
                    $src,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

        if ($src === '') {
            return false;
        }

        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }

        $parts =
            parse_url(
                $src
            );

        if (!is_array($parts)) {
            return false;
        }

        $scheme =
            strtolower(
                (string)($parts['scheme'] ?? '')
            );

        $host =
            strtolower(
                (string)($parts['host'] ?? '')
            );

        $path =
            '/' . ltrim(
                (string)($parts['path'] ?? ''),
                '/'
            );

        if (
            !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            return false;
        }

        if (
            !in_array(
                $host,
                [
                    'www.google.com',
                    'google.com',
                    'maps.google.com',
                ],
                true
            )
        ) {
            return false;
        }

        return
            str_starts_with(
                strtolower($path),
                '/maps/embed'
            );
    }

    private static function attribute(
        string $tag,
        string $name
    ): string {
        $namePattern =
            preg_quote(
                $name,
                '~'
            );

        if (
            preg_match(
                '~\s'
                . $namePattern
                . '\s*=\s*(["\'])(.*?)\1~isu',
                $tag,
                $match
            ) === 1
        ) {
            return
                trim(
                    (string)($match[2] ?? '')
                );
        }

        if (
            preg_match(
                '~\s'
                . $namePattern
                . '\s*=\s*([^\s>]+)~iu',
                $tag,
                $match
            ) === 1
        ) {
            return
                trim(
                    (string)($match[1] ?? ''),
                    "\"'"
                );
        }

        return '';
    }

    private static function setAttribute(
        string $tag,
        string $name,
        string $value
    ): string {
        $escaped =
            htmlspecialchars(
                $value,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

        $namePattern =
            preg_quote(
                $name,
                '~'
            );

        $pattern =
            '~\s'
            . $namePattern
            . '\s*=\s*(?:(["\']).*?\1|[^\s>]+)~isu';

        if (
            preg_match(
                $pattern,
                $tag
            ) === 1
        ) {
            $updated =
                preg_replace(
                    $pattern,
                    ' '
                    . $name
                    . '="'
                    . $escaped
                    . '"',
                    $tag,
                    1
                );

            return
                is_string($updated)
                    ? $updated
                    : $tag;
        }

        $pos =
            strrpos(
                $tag,
                '>'
            );

        if ($pos === false) {
            return $tag;
        }

        return
            substr(
                $tag,
                0,
                $pos
            )
            . ' '
            . $name
            . '="'
            . $escaped
            . '"'
            . substr(
                $tag,
                $pos
            );
    }

    private static function removeAttribute(
        string $tag,
        string $name
    ): string {
        $namePattern =
            preg_quote(
                $name,
                '~'
            );

        $pattern =
            '~\s'
            . $namePattern
            . '(?:\s*=\s*(?:(["\']).*?\1|[^\s>]+))?~isu';

        $updated =
            preg_replace(
                $pattern,
                '',
                $tag
            );

        return
            is_string($updated)
                ? $updated
                : $tag;
    }

    /**
     * @param array<int,string> $required
     */
    private static function mergeSpaceTokens(
        string $current,
        array $required
    ): string {
        $tokens =
            preg_split(
                '/\s+/u',
                trim($current)
            )
            ?: [];

        $normalized = [];

        foreach (
            array_merge(
                $tokens,
                $required
            )
            as $token
        ) {
            $token =
                strtolower(
                    trim(
                        (string)$token
                    )
                );

            if ($token !== '') {
                $normalized[$token] =
                    $token;
            }
        }

        return
            implode(
                ' ',
                array_values(
                    $normalized
                )
            );
    }

    /**
     * @param array<int,string> $required
     */
    private static function mergeSemicolonTokens(
        string $current,
        array $required
    ): string {
        $tokens =
            preg_split(
                '/\s*;\s*/u',
                trim($current)
            )
            ?: [];

        $normalized = [];

        foreach (
            array_merge(
                $tokens,
                $required
            )
            as $token
        ) {
            $token =
                strtolower(
                    trim(
                        (string)$token
                    )
                );

            if ($token !== '') {
                $normalized[$token] =
                    $token;
            }
        }

        return
            implode(
                '; ',
                array_values(
                    $normalized
                )
            );
    }
}
