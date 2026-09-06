<?php

declare(strict_types=1);

final class AccessibilityAuditService
{
    /**
     * @return array<string,mixed>
     */
    public static function report(string $rootPath): array
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        $checks = [];
        $warnings = [];
        $errors = [];

        self::checkFileMarkers(
            $rootPath,
            'theme/ieclb/header.php',
            [
                'public/css/accessibility-v98.css',
                'class="portal-skip-link"',
                'href="#conteudo-principal"',
                'id="conteudo-principal"',
                'tabindex="-1"',
                'aria-label="Navegação principal"',
            ],
            $checks,
            $errors
        );

        self::checkFileMarkers(
            $rootPath,
            'admin/_header.php',
            [
                'public/css/accessibility-v98.css',
                'class="portal-skip-link"',
                'href="#admin-conteudo"',
                'id="admin-conteudo"',
                'tabindex="-1"',
                'aria-label="Barra superior administrativa"',
            ],
            $checks,
            $errors
        );

        self::checkFileMarkers(
            $rootPath,
            'public/css/accessibility-v98.css',
            [
                '.portal-skip-link',
                ':focus-visible',
                'forced-colors: active',
                'prefers-reduced-motion: reduce',
            ],
            $checks,
            $errors
        );

        $scanFiles = [
            'theme/ieclb/header.php',
            'theme/ieclb/footer.php',
            'index.php',
            'agenda.php',
            'busca.php',
            'noticia.php',
            'pagina.php',
            'evento.php',
            'comunidades.php',
        ];

        $scanned = 0;
        $imagesWithoutAlt = [];
        $iframesWithoutTitle = [];

        foreach ($scanFiles as $relative) {
            $file = self::path($rootPath, $relative);

            if (!is_file($file)) {
                continue;
            }

            $content = file_get_contents($file);

            if (!is_string($content)) {
                continue;
            }

            $scanned++;

            /*
             * PORTAL_ACCESSIBILITY_TEMPLATE_SANITIZE_R2
             *
             * Remove somente os blocos PHP da cópia usada pelo scanner.
             * Isso impede que o "?>" de atributos dinâmicos encerre a regex
             * da tag HTML antes de chegar em alt/title.
             */
            $htmlTemplate =
                preg_replace(
                    '/<\?(?:php|=)?[\s\S]*?\?>/i',
                    '',
                    $content
                )
                ?? $content;

            if (
                preg_match_all(
                    '/<img\b[^>]*>/i',
                    $htmlTemplate,
                    $imgMatches
                )
            ) {
                foreach ($imgMatches[0] as $tag) {
                    if (
                        !preg_match(
                            '/\balt\s*=/i',
                            $tag
                        )
                    ) {
                        $imagesWithoutAlt[] = $relative;
                        break;
                    }
                }
            }

            if (
                preg_match_all(
                    '/<iframe\b[^>]*>/i',
                    $htmlTemplate,
                    $iframeMatches
                )
            ) {
                foreach ($iframeMatches[0] as $tag) {
                    if (
                        !preg_match(
                            '/\btitle\s*=/i',
                            $tag
                        )
                    ) {
                        $iframesWithoutTitle[] = $relative;
                        break;
                    }
                }
            }
        }

        $imagesWithoutAlt = array_values(array_unique($imagesWithoutAlt));
        $iframesWithoutTitle = array_values(array_unique($iframesWithoutTitle));

        if ($imagesWithoutAlt) {
            $warnings[] =
                'Imagem(ns) sem atributo alt detectadas em: '
                . implode(', ', $imagesWithoutAlt)
                . '.';
        }

        if ($iframesWithoutTitle) {
            $warnings[] =
                'iframe(s) sem atributo title detectados em: '
                . implode(', ', $iframesWithoutTitle)
                . '.';
        }

        return [
            'checks' => $checks,
            'warnings' => $warnings,
            'errors' => $errors,
            'summary' => [
                'checks' => count($checks),
                'passed' =>
                    count(
                        array_filter(
                            $checks,
                            static fn(array $item): bool =>
                                !empty($item['ok'])
                        )
                    ),
                'scanned_files' => $scanned,
                'images_without_alt_files' => count($imagesWithoutAlt),
                'iframes_without_title_files' => count($iframesWithoutTitle),
            ],
        ];
    }

    /**
     * @param array<int,string> $markers
     * @param array<int,array<string,mixed>> $checks
     * @param array<int,string> $errors
     */
    private static function checkFileMarkers(
        string $rootPath,
        string $relative,
        array $markers,
        array &$checks,
        array &$errors
    ): void {
        $file = self::path($rootPath, $relative);

        if (!is_file($file)) {
            $checks[] = [
                'label' => $relative,
                'ok' => false,
                'detail' => 'Arquivo ausente.',
            ];

            $errors[] =
                "Arquivo obrigatório ausente: {$relative}";

            return;
        }

        $content =
            file_get_contents(
                $file
            );

        if (!is_string($content)) {
            $checks[] = [
                'label' => $relative,
                'ok' => false,
                'detail' => 'Não foi possível ler.',
            ];

            $errors[] =
                "Não foi possível ler: {$relative}";

            return;
        }

        $missing = [];

        foreach ($markers as $marker) {
            if (!str_contains($content, $marker)) {
                $missing[] = $marker;
            }
        }

        $ok = !$missing;

        $checks[] = [
            'label' => $relative,
            'ok' => $ok,
            'detail' =>
                $ok
                    ? 'Integração presente.'
                    : 'Marcadores ausentes: '
                        . implode(', ', $missing),
        ];

        if (!$ok) {
            $errors[] =
                "{$relative}: integração de acessibilidade incompleta.";
        }
    }

    private static function path(
        string $rootPath,
        string $relative
    ): string {
        return
            $rootPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            );
    }
}
