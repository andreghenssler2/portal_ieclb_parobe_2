<?php

declare(strict_types=1);

/**
 * Cabeçalhos HTTP de segurança do Portal.
 *
 * v0.76:
 * - CSP continua em Report-Only por padrão;
 * - pode enviar report-uri para o coletor local de violações;
 * - relatórios não armazenam IP nem query strings.
 */
final class SecurityHeadersService
{
    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'security_headers_enabled' => '1',
            'security_csp_mode' => 'report-only',
            'security_csp_report_enabled' => '1',
            'security_csp_report_retention_days' => '30',
            'security_hsts_enabled' => '0',
            'security_hsts_max_age' => '15552000',
            'security_hsts_include_subdomains' => '0',
            'security_permissions_policy_enabled' => '1',
            'security_frame_policy' => 'SAMEORIGIN',
            'security_referrer_policy' => 'strict-origin-when-cross-origin',
            'security_coop_policy' => 'same-origin-allow-popups',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function settings(PDO $pdo): array
    {
        $settings =
            array_merge(
                self::defaults(),
                siteConfigAll($pdo)
            );

        foreach (
            [
                'security_headers_enabled',
                'security_csp_report_enabled',
                'security_hsts_enabled',
                'security_hsts_include_subdomains',
                'security_permissions_policy_enabled',
            ]
            as $booleanKey
        ) {
            $settings[$booleanKey] =
                ($settings[$booleanKey] ?? '0') === '1'
                    ? '1'
                    : '0';
        }

        $mode =
            strtolower(
                trim(
                    (string)(
                        $settings['security_csp_mode']
                        ?? 'report-only'
                    )
                )
            );

        if (
            !in_array(
                $mode,
                [
                    'off',
                    'report-only',
                    'enforce',
                ],
                true
            )
        ) {
            $mode = 'report-only';
        }

        $settings['security_csp_mode'] =
            $mode;

        $settings['security_csp_report_retention_days'] =
            (string)max(
                1,
                min(
                    365,
                    (int)(
                        $settings['security_csp_report_retention_days']
                        ?? 30
                    )
                )
            );

        $settings['security_hsts_max_age'] =
            (string)max(
                300,
                min(
                    63072000,
                    (int)(
                        $settings['security_hsts_max_age']
                        ?? 15552000
                    )
                )
            );

        $frame =
            strtoupper(
                trim(
                    (string)(
                        $settings['security_frame_policy']
                        ?? 'SAMEORIGIN'
                    )
                )
            );

        if (
            !in_array(
                $frame,
                [
                    'SAMEORIGIN',
                    'DENY',
                ],
                true
            )
        ) {
            $frame = 'SAMEORIGIN';
        }

        $settings['security_frame_policy'] =
            $frame;

        $referrer =
            strtolower(
                trim(
                    (string)(
                        $settings['security_referrer_policy']
                        ?? 'strict-origin-when-cross-origin'
                    )
                )
            );

        if (
            !in_array(
                $referrer,
                [
                    'no-referrer',
                    'same-origin',
                    'strict-origin',
                    'strict-origin-when-cross-origin',
                ],
                true
            )
        ) {
            $referrer =
                'strict-origin-when-cross-origin';
        }

        $settings['security_referrer_policy'] =
            $referrer;

        $coop =
            strtolower(
                trim(
                    (string)(
                        $settings['security_coop_policy']
                        ?? 'same-origin-allow-popups'
                    )
                )
            );

        if (
            !in_array(
                $coop,
                [
                    'off',
                    'same-origin',
                    'same-origin-allow-popups',
                ],
                true
            )
        ) {
            $coop =
                'same-origin-allow-popups';
        }

        $settings['security_coop_policy'] =
            $coop;

        return $settings;
    }

    public static function apply(PDO $pdo): void
    {
        if (
            PHP_SAPI === 'cli'
            || headers_sent()
        ) {
            return;
        }

        $settings =
            self::settings($pdo);

        if (
            $settings['security_headers_enabled']
            !== '1'
        ) {
            return;
        }

        header(
            'X-Content-Type-Options: nosniff',
            true
        );

        header(
            'X-Frame-Options: '
            . $settings['security_frame_policy'],
            true
        );

        header(
            'Referrer-Policy: '
            . $settings['security_referrer_policy'],
            true
        );

        if (
            $settings['security_permissions_policy_enabled']
            === '1'
        ) {
            header(
                'Permissions-Policy: '
                . self::permissionsPolicy(),
                true
            );
        }

        if (
            $settings['security_coop_policy']
            !== 'off'
        ) {
            header(
                'Cross-Origin-Opener-Policy: '
                . $settings['security_coop_policy'],
                true
            );
        }

        $cspMode =
            $settings['security_csp_mode'];

        if ($cspMode !== 'off') {
            $headerName =
                $cspMode === 'enforce'
                    ? 'Content-Security-Policy'
                    : 'Content-Security-Policy-Report-Only';

            header(
                $headerName
                . ': '
                . self::contentSecurityPolicy(
                    $settings
                ),
                true
            );
        }

        if (
            $settings['security_hsts_enabled'] === '1'
            && self::isHttps()
        ) {
            $hsts =
                'max-age='
                . (int)$settings['security_hsts_max_age'];

            if (
                $settings['security_hsts_include_subdomains']
                === '1'
            ) {
                $hsts .=
                    '; includeSubDomains';
            }

            header(
                'Strict-Transport-Security: '
                . $hsts,
                true
            );
        }
    }

    /**
     * @return array<int,array{
     *   header:string,
     *   value:string,
     *   active:bool,
     *   note:string
     * }>
     */
    public static function preview(PDO $pdo): array
    {
        $settings =
            self::settings($pdo);

        $enabled =
            $settings['security_headers_enabled']
            === '1';

        $items = [
            [
                'header' => 'X-Content-Type-Options',
                'value' => 'nosniff',
                'active' => $enabled,
                'note' =>
                    'Impede interpretação de MIME diferente do declarado.',
            ],
            [
                'header' => 'X-Frame-Options',
                'value' =>
                    $settings['security_frame_policy'],
                'active' => $enabled,
                'note' =>
                    'Reduz risco de clickjacking.',
            ],
            [
                'header' => 'Referrer-Policy',
                'value' =>
                    $settings['security_referrer_policy'],
                'active' => $enabled,
                'note' =>
                    'Limita dados enviados no cabeçalho Referer.',
            ],
            [
                'header' => 'Permissions-Policy',
                'value' =>
                    self::permissionsPolicy(),
                'active' =>
                    $enabled
                    && $settings['security_permissions_policy_enabled']
                        === '1',
                'note' =>
                    'Desativa recursos do navegador que o Portal não utiliza.',
            ],
            [
                'header' =>
                    'Cross-Origin-Opener-Policy',
                'value' =>
                    $settings['security_coop_policy'],
                'active' =>
                    $enabled
                    && $settings['security_coop_policy']
                        !== 'off',
                'note' =>
                    'Isola o contexto de navegação, preservando pop-ups quando configurado.',
            ],
        ];

        $cspMode =
            $settings['security_csp_mode'];

        $items[] = [
            'header' =>
                $cspMode === 'enforce'
                    ? 'Content-Security-Policy'
                    : 'Content-Security-Policy-Report-Only',
            'value' =>
                self::contentSecurityPolicy(
                    $settings
                ),
            'active' =>
                $enabled
                && $cspMode !== 'off',
            'note' =>
                $cspMode === 'report-only'
                    ? 'Monitora violações sem bloquear recursos.'
                    : (
                        $cspMode === 'enforce'
                            ? 'Política aplicada e bloqueante.'
                            : 'CSP desativada.'
                    ),
        ];

        $hstsActive =
            $enabled
            && $settings['security_hsts_enabled']
                === '1'
            && self::isHttps();

        $hstsValue =
            'max-age='
            . (int)$settings['security_hsts_max_age'];

        if (
            $settings['security_hsts_include_subdomains']
            === '1'
        ) {
            $hstsValue .=
                '; includeSubDomains';
        }

        $items[] = [
            'header' => 'Strict-Transport-Security',
            'value' => $hstsValue,
            'active' => $hstsActive,
            'note' =>
                !self::isHttps()
                    ? 'HSTS só é enviado em HTTPS.'
                    : (
                        $settings['security_hsts_enabled'] === '1'
                            ? 'HTTPS obrigatório por período definido.'
                            : 'HSTS desativado nas configurações.'
                    ),
        ];

        return $items;
    }

    /**
     * @param array<string,string> $settings
     */
    public static function contentSecurityPolicy(
        array $settings = []
    ): string {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google-analytics.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
            "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://*.google-analytics.com",
            "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://www.google.com https://maps.google.com",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];

        if (
            ($settings['security_csp_report_enabled'] ?? '1')
            === '1'
        ) {
            try {
                $reportUri =
                    url(
                        'csp-report.php'
                    );

                if ($reportUri !== '') {
                    $directives[] =
                        'report-uri '
                        . $reportUri;
                }
            } catch (Throwable $ignored) {
            }
        }

        return
            implode(
                '; ',
                $directives
            )
            . ';';
    }

    public static function permissionsPolicy(): string
    {
        return
            'accelerometer=(), '
            . 'camera=(), '
            . 'geolocation=(), '
            . 'gyroscope=(), '
            . 'magnetometer=(), '
            . 'microphone=(), '
            . 'payment=(), '
            . 'usb=(), '
            . 'browsing-topics=()';
    }

    public static function isHttps(): bool
    {
        $https =
            strtolower(
                trim(
                    (string)(
                        $_SERVER['HTTPS']
                        ?? ''
                    )
                )
            );

        if (
            $https !== ''
            && $https !== 'off'
            && $https !== '0'
        ) {
            return true;
        }

        if (
            (int)(
                $_SERVER['SERVER_PORT']
                ?? 0
            ) === 443
        ) {
            return true;
        }

        $forwarded =
            strtolower(
                trim(
                    explode(
                        ',',
                        (string)(
                            $_SERVER['HTTP_X_FORWARDED_PROTO']
                            ?? ''
                        )
                    )[0]
                )
            );

        return
            $forwarded === 'https';
    }
}
