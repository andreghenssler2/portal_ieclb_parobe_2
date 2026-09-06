<?php

declare(strict_types=1);

/**
 * Histórico leve da saúde operacional do Portal.
 *
 * Não cria tabela no banco. Os snapshots são armazenados como JSON em
 * storage/health/portal/.
 */
final class PortalHealthSnapshotService
{
    private const MAX_HISTORY = 120;

    /**
     * @return array<string,mixed>
     */
    public static function current(
        PDO $pdo,
        string $rootPath
    ): array {
        if (!class_exists('ProductionReadinessService')) {
            throw new RuntimeException(
                'ProductionReadinessService indisponível.'
            );
        }

        $report =
            ProductionReadinessService::report(
                $pdo,
                $rootPath
            );

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'version' =>
                defined('APP_VERSION')
                    ? (string)APP_VERSION
                    : '',
            'state' =>
                (string)($report['state'] ?? 'attention'),
            'score' =>
                (int)($report['score'] ?? 0),
            'passed' =>
                (int)($report['passed'] ?? 0),
            'checks' =>
                (int)($report['checks'] ?? 0),
            'warnings' =>
                array_values(
                    (array)($report['warnings'] ?? [])
                ),
            'blockers' =>
                array_values(
                    (array)($report['blockers'] ?? [])
                ),
            'sections' =>
                (array)($report['sections'] ?? []),
            'environment' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'hostname' =>
                    function_exists('gethostname')
                        ? (string)(gethostname() ?: '')
                        : '',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function save(
        PDO $pdo,
        string $rootPath,
        string $source = 'manual'
    ): array {
        $snapshot =
            self::current(
                $pdo,
                $rootPath
            );

        $source =
            self::cleanSource(
                $source
            );

        $snapshot['source'] = $source;

        $dir =
            self::directory(
                $rootPath
            );

        self::ensureDirectory(
            $dir
        );

        $stamp =
            date('Ymd-His')
            . '-'
            . substr(
                bin2hex(
                    random_bytes(4)
                ),
                0,
                8
            );

        $file =
            $dir
            . DIRECTORY_SEPARATOR
            . $stamp
            . '.json';

        $json =
            json_encode(
                $snapshot,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

        if (!is_string($json)) {
            throw new RuntimeException(
                'Não foi possível serializar o snapshot.'
            );
        }

        $tmp =
            $file
            . '.tmp';

        if (
            file_put_contents(
                $tmp,
                $json . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Não foi possível gravar o snapshot de saúde.'
            );
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(
                'Não foi possível finalizar o snapshot de saúde.'
            );
        }

        self::prune(
            $rootPath,
            self::MAX_HISTORY
        );

        return $snapshot;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function history(
        string $rootPath,
        int $limit = 30
    ): array {
        $limit =
            max(
                1,
                min(
                    self::MAX_HISTORY,
                    $limit
                )
            );

        $dir =
            self::directory(
                $rootPath
            );

        if (!is_dir($dir)) {
            return [];
        }

        $files =
            glob(
                $dir
                . DIRECTORY_SEPARATOR
                . '*.json'
            )
            ?: [];

        rsort(
            $files,
            SORT_STRING
        );

        $items = [];

        foreach (
            array_slice(
                $files,
                0,
                $limit
            )
            as $file
        ) {
            $content =
                @file_get_contents(
                    $file
                );

            if (!is_string($content)) {
                continue;
            }

            $data =
                json_decode(
                    $content,
                    true
                );

            if (!is_array($data)) {
                continue;
            }

            $data['_file'] =
                basename(
                    $file
                );

            $items[] =
                $data;
        }

        return $items;
    }

    public static function prune(
        string $rootPath,
        int $keep = self::MAX_HISTORY
    ): int {
        $keep =
            max(
                10,
                min(
                    500,
                    $keep
                )
            );

        $dir =
            self::directory(
                $rootPath
            );

        if (!is_dir($dir)) {
            return 0;
        }

        $files =
            glob(
                $dir
                . DIRECTORY_SEPARATOR
                . '*.json'
            )
            ?: [];

        rsort(
            $files,
            SORT_STRING
        );

        $removed = 0;

        foreach (
            array_slice(
                $files,
                $keep
            )
            as $file
        ) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param array<int,array<string,mixed>> $history
     * @return array<string,mixed>
     */
    public static function trend(
        array $history
    ): array {
        if (!$history) {
            return [
                'direction' => 'none',
                'delta' => 0,
                'label' => 'Sem histórico',
            ];
        }

        $current =
            (int)($history[0]['score'] ?? 0);

        if (count($history) < 2) {
            return [
                'direction' => 'stable',
                'delta' => 0,
                'label' => 'Primeiro registro',
            ];
        }

        $previous =
            (int)($history[1]['score'] ?? 0);

        $delta =
            $current
            - $previous;

        return [
            'direction' =>
                $delta > 0
                    ? 'up'
                    : (
                        $delta < 0
                            ? 'down'
                            : 'stable'
                    ),
            'delta' => $delta,
            'label' =>
                $delta > 0
                    ? '+' . $delta . ' ponto(s)'
                    : (
                        $delta < 0
                            ? $delta . ' ponto(s)'
                            : 'Estável'
                    ),
        ];
    }

    public static function storageWritable(
        string $rootPath
    ): bool {
        $dir =
            self::directory(
                $rootPath
            );

        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent =
            dirname(
                $dir
            );

        return
            is_dir($parent)
            && is_writable($parent);
    }

    private static function directory(
        string $rootPath
    ): string {
        return
            rtrim(
                $rootPath,
                DIRECTORY_SEPARATOR
            )
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'health'
            . DIRECTORY_SEPARATOR
            . 'portal';
    }

    private static function ensureDirectory(
        string $dir
    ): void {
        if (is_dir($dir)) {
            if (!is_writable($dir)) {
                throw new RuntimeException(
                    'Pasta de snapshots sem permissão de escrita.'
                );
            }

            return;
        }

        if (
            !@mkdir(
                $dir,
                0775,
                true
            )
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Não foi possível criar a pasta de snapshots.'
            );
        }
    }

    private static function cleanSource(
        string $source
    ): string {
        $source =
            strtolower(
                trim(
                    $source
                )
            );

        $source =
            preg_replace(
                '/[^a-z0-9._-]+/',
                '-',
                $source
            )
            ?: 'manual';

        return
            substr(
                trim(
                    $source,
                    '-.'
                ),
                0,
                50
            )
            ?: 'manual';
    }
}
