<?php

declare(strict_types=1);

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
                    $tag = (string)($match[0] ?? '');

                    if ($tag === '') {
                        return $tag;
                    }

                    $src =
                        self::attribute(
                            $tag,
                            'src'
                        );

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

                    /*
                     * v1.1.3 / consolidação R6:
                     * usa a forma moderna allow="fullscreen" e remove o
                     * atributo legado allowfullscreen para não gerar aviso.
                     */
                    $tag =
                        self::removeBooleanAttribute(
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

        $parts = parse_url($src);

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
            '/'
            . ltrim(
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

        $pos = strrpos($tag, '>');

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

    private static function removeBooleanAttribute(
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
                $tag,
                1
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
                $normalized[$token] = $token;
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
                $normalized[$token] = $token;
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
