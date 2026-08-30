<?php

declare(strict_types=1);

/**
 * Diagnóstico do armazenamento da Biblioteca de Mídia.
 *
 * Ações automáticas são conservadoras:
 * - registros de variantes sem arquivo físico podem ser removidos;
 * - arquivos derivados .thumb.webp/.optimized.webp sem registro podem ser apagados;
 * - arquivos originais órfãos são apenas listados, nunca apagados automaticamente.
 */
final class MediaIntegrityService
{
    public function __construct(
        private PDO $pdo,
        private string $root
    ) {
        $this->root =
            rtrim(
                str_replace('\\', '/', $this->root),
                '/'
            );
    }

    /**
     * @return array<string,int|bool>
     */
    public function databaseSummary(): array
    {
        $summary = [
            'media' => 0,
            'images' => 0,
            'documents' => 0,
            'variants' => 0,
            'variant_webp' => 0,
            'variant_thumb' => 0,
            'variant_table' => false,
        ];

        try {
            $row =
                $this->pdo
                    ->query(
                        "SELECT
                            COUNT(*) AS total,
                            SUM(
                                CASE
                                    WHEN mime_type LIKE 'image/%'
                                    THEN 1
                                    ELSE 0
                                END
                            ) AS images,
                            SUM(
                                CASE
                                    WHEN mime_type NOT LIKE 'image/%'
                                    THEN 1
                                    ELSE 0
                                END
                            ) AS documents
                         FROM midias"
                    )
                    ->fetch(PDO::FETCH_ASSOC)
                ?: [];

            $summary['media'] =
                (int)($row['total'] ?? 0);

            $summary['images'] =
                (int)($row['images'] ?? 0);

            $summary['documents'] =
                (int)($row['documents'] ?? 0);
        } catch (Throwable $ignored) {
        }

        if ($this->tableExists('midia_variantes')) {
            $summary['variant_table'] = true;

            try {
                $row =
                    $this->pdo
                        ->query(
                            "SELECT
                                COUNT(*) AS total,
                                SUM(
                                    CASE
                                        WHEN tipo='webp'
                                        THEN 1
                                        ELSE 0
                                    END
                                ) AS webp,
                                SUM(
                                    CASE
                                        WHEN tipo='thumb'
                                        THEN 1
                                        ELSE 0
                                    END
                                ) AS thumb
                             FROM midia_variantes"
                        )
                        ->fetch(PDO::FETCH_ASSOC)
                    ?: [];

                $summary['variants'] =
                    (int)($row['total'] ?? 0);

                $summary['variant_webp'] =
                    (int)($row['webp'] ?? 0);

                $summary['variant_thumb'] =
                    (int)($row['thumb'] ?? 0);
            } catch (Throwable $ignored) {
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *   scanned_files:int,
     *   scan_limit:int,
     *   scan_truncated:bool,
     *   missing_originals:array<int,array<string,mixed>>,
     *   size_mismatches:array<int,array<string,mixed>>,
     *   missing_variants:array<int,array<string,mixed>>,
     *   orphan_files:array<int,array<string,mixed>>,
     *   orphan_generated:array<int,array<string,mixed>>,
     *   totals:array<string,int>
     * }
     */
    public function scan(
        int $scanLimit = 10000
    ): array {
        $scanLimit =
            max(
                500,
                min(
                    50000,
                    $scanLimit
                )
            );

        $registered = [];
        $missingOriginals = [];
        $sizeMismatches = [];
        $missingVariants = [];
        $orphanFiles = [];
        $orphanGenerated = [];

        /*
         * Registros principais.
         */
        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        id,
                        nome_original,
                        caminho,
                        mime_type,
                        tamanho,
                        largura,
                        altura
                     FROM midias
                     ORDER BY id ASC"
                );

            foreach (
                $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
                as $media
            ) {
                $relative =
                    $this->normalizeRelativePath(
                        (string)(
                            $media['caminho']
                            ?? ''
                        )
                    );

                if ($relative === '') {
                    continue;
                }

                $registered[
                    strtolower($relative)
                ] = true;

                $absolute =
                    $this->absolutePath(
                        $relative
                    );

                if (
                    $absolute === ''
                    || !is_file($absolute)
                ) {
                    $missingOriginals[] = [
                        'id' =>
                            (int)$media['id'],
                        'name' =>
                            (string)$media['nome_original'],
                        'path' =>
                            $relative,
                        'mime_type' =>
                            (string)$media['mime_type'],
                        'stored_size' =>
                            (int)$media['tamanho'],
                        'url' =>
                            url(
                                'admin/midias/editar.php?id='
                                . (int)$media['id']
                            ),
                    ];

                    continue;
                }

                $actualSize =
                    @filesize(
                        $absolute
                    );

                if (
                    is_int($actualSize)
                    && $actualSize >= 0
                    && $actualSize !== (int)$media['tamanho']
                ) {
                    $sizeMismatches[] = [
                        'id' =>
                            (int)$media['id'],
                        'name' =>
                            (string)$media['nome_original'],
                        'path' =>
                            $relative,
                        'stored_size' =>
                            (int)$media['tamanho'],
                        'actual_size' =>
                            $actualSize,
                        'url' =>
                            url(
                                'admin/midias/editar.php?id='
                                . (int)$media['id']
                            ),
                    ];
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Não foi possível analisar a tabela de mídias: '
                . $e->getMessage()
            );
        }

        /*
         * Variantes da v0.72.
         */
        if ($this->tableExists('midia_variantes')) {
            try {
                $stmt =
                    $this->pdo->query(
                        "SELECT
                            v.id,
                            v.midia_id,
                            v.tipo,
                            v.caminho,
                            v.tamanho,
                            m.nome_original
                         FROM midia_variantes v
                         LEFT JOIN midias m
                            ON m.id=v.midia_id
                         ORDER BY v.id ASC"
                    );

                foreach (
                    $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
                    as $variant
                ) {
                    $relative =
                        $this->normalizeRelativePath(
                            (string)(
                                $variant['caminho']
                                ?? ''
                            )
                        );

                    if ($relative === '') {
                        continue;
                    }

                    $registered[
                        strtolower($relative)
                    ] = true;

                    $absolute =
                        $this->absolutePath(
                            $relative
                        );

                    if (
                        $absolute === ''
                        || !is_file($absolute)
                    ) {
                        $missingVariants[] = [
                            'id' =>
                                (int)$variant['id'],
                            'media_id' =>
                                (int)$variant['midia_id'],
                            'type' =>
                                (string)$variant['tipo'],
                            'path' =>
                                $relative,
                            'media_name' =>
                                (string)(
                                    $variant['nome_original']
                                    ?? ''
                                ),
                            'url' =>
                                (int)$variant['midia_id'] > 0
                                    ? url(
                                        'admin/midias/editar.php?id='
                                        . (int)$variant['midia_id']
                                    )
                                    : '',
                        ];
                    }
                }
            } catch (Throwable $ignored) {
            }
        }

        /*
         * Arquivos físicos em uploads/.
         */
        $uploads =
            $this->root
            . '/uploads';

        $scanned = 0;
        $truncated = false;

        if (is_dir($uploads)) {
            $iterator =
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $uploads,
                        FilesystemIterator::SKIP_DOTS
                    )
                );

            foreach ($iterator as $file) {
                if (
                    !$file->isFile()
                    || $file->isLink()
                ) {
                    continue;
                }

                $name =
                    $file->getFilename();

                if (
                    in_array(
                        $name,
                        [
                            '.htaccess',
                            'index.php',
                            '.gitkeep',
                        ],
                        true
                    )
                ) {
                    continue;
                }

                $scanned++;

                if ($scanned > $scanLimit) {
                    $truncated = true;
                    break;
                }

                $absolute =
                    str_replace(
                        '\\',
                        '/',
                        $file->getPathname()
                    );

                $relative =
                    $this->normalizeRelativePath(
                        ltrim(
                            substr(
                                $absolute,
                                strlen($this->root)
                            ),
                            '/'
                        )
                    );

                if ($relative === '') {
                    continue;
                }

                if (
                    isset(
                        $registered[
                            strtolower($relative)
                        ]
                    )
                ) {
                    continue;
                }

                $item = [
                    'path' => $relative,
                    'size' =>
                        (int)$file->getSize(),
                    'modified_at' =>
                        date(
                            'Y-m-d H:i:s',
                            $file->getMTime()
                        ),
                    'age_seconds' =>
                        max(
                            0,
                            time()
                            - $file->getMTime()
                        ),
                    'url' =>
                        mediaUrl(
                            $relative
                        ),
                ];

                if (
                    $this->isGeneratedVariantPath(
                        $relative
                    )
                ) {
                    $orphanGenerated[] =
                        $item;
                } else {
                    /*
                     * Original ou arquivo manual: somente diagnóstico.
                     */
                    $orphanFiles[] =
                        $item;
                }
            }
        }

        return [
            'scanned_files' =>
                min(
                    $scanned,
                    $scanLimit
                ),
            'scan_limit' =>
                $scanLimit,
            'scan_truncated' =>
                $truncated,

            'missing_originals' =>
                $missingOriginals,

            'size_mismatches' =>
                $sizeMismatches,

            'missing_variants' =>
                $missingVariants,

            'orphan_files' =>
                $orphanFiles,

            'orphan_generated' =>
                $orphanGenerated,

            'totals' => [
                'missing_originals' =>
                    count(
                        $missingOriginals
                    ),
                'size_mismatches' =>
                    count(
                        $sizeMismatches
                    ),
                'missing_variants' =>
                    count(
                        $missingVariants
                    ),
                'orphan_files' =>
                    count(
                        $orphanFiles
                    ),
                'orphan_generated' =>
                    count(
                        $orphanGenerated
                    ),
            ],
        ];
    }

    /**
     * Remove apenas registros de variantes cujo arquivo físico não existe.
     *
     * @return int
     */
    public function removeMissingVariantRecords(): int
    {
        if (!$this->tableExists('midia_variantes')) {
            return 0;
        }

        $scan =
            $this->scan(
                50000
            );

        $ids =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn(array $item): int =>
                                (int)(
                                    $item['id']
                                    ?? 0
                                ),
                            $scan['missing_variants']
                        ),
                        static fn(int $id): bool =>
                            $id > 0
                    )
                )
            );

        if (!$ids) {
            return 0;
        }

        $deleted = 0;

        $stmt =
            $this->pdo->prepare(
                "DELETE FROM midia_variantes
                 WHERE id=:id"
            );

        foreach ($ids as $id) {
            $stmt->execute([
                'id' => $id,
            ]);

            $deleted +=
                $stmt->rowCount() > 0
                    ? 1
                    : 0;
        }

        return $deleted;
    }

    /**
     * Exclui somente arquivos derivados conhecidos e não registrados.
     *
     * Arquivos com menos de uma hora são preservados para evitar corrida com
     * algum processamento ainda em andamento.
     *
     * @return array{removed:int,bytes:int,failed:int}
     */
    public function removeOrphanGeneratedFiles(): array
    {
        $scan =
            $this->scan(
                50000
            );

        $removed = 0;
        $bytes = 0;
        $failed = 0;

        foreach (
            $scan['orphan_generated']
            as $item
        ) {
            if (
                (int)(
                    $item['age_seconds']
                    ?? 0
                )
                < 3600
            ) {
                continue;
            }

            $relative =
                $this->normalizeRelativePath(
                    (string)(
                        $item['path']
                        ?? ''
                    )
                );

            if (
                $relative === ''
                || !$this->isGeneratedVariantPath(
                    $relative
                )
            ) {
                continue;
            }

            $absolute =
                $this->absolutePath(
                    $relative
                );

            if (
                $absolute === ''
                || !is_file($absolute)
            ) {
                continue;
            }

            $size =
                @filesize(
                    $absolute
                );

            if (@unlink($absolute)) {
                $removed++;

                if (
                    is_int($size)
                    && $size > 0
                ) {
                    $bytes += $size;
                }
            } else {
                $failed++;
            }
        }

        return [
            'removed' => $removed,
            'bytes' => $bytes,
            'failed' => $failed,
        ];
    }

    private function tableExists(
        string $table
    ): bool {
        if (
            !preg_match(
                '/^[a-zA-Z0-9_]+$/',
                $table
            )
        ) {
            return false;
        }

        try {
            $stmt =
                $this->pdo->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.tables
                     WHERE table_schema=DATABASE()
                       AND table_name=:table_name"
                );

            $stmt->execute([
                'table_name' => $table,
            ]);

            return
                (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function normalizeRelativePath(
        string $path
    ): string {
        $path =
            trim(
                str_replace(
                    '\\',
                    '/',
                    $path
                )
            );

        if (
            $path === ''
            || preg_match(
                '~^(?:https?:)?//~i',
                $path
            )
        ) {
            return '';
        }

        $path =
            ltrim(
                $path,
                '/'
            );

        while (
            str_starts_with(
                $path,
                './'
            )
        ) {
            $path =
                substr(
                    $path,
                    2
                );
        }

        if (
            $path === ''
            || str_contains(
                $path,
                '..'
            )
        ) {
            return '';
        }

        return $path;
    }

    private function absolutePath(
        string $relative
    ): string {
        $relative =
            $this->normalizeRelativePath(
                $relative
            );

        if ($relative === '') {
            return '';
        }

        return
            $this->root
            . '/'
            . $relative;
    }

    private function isGeneratedVariantPath(
        string $relative
    ): bool {
        $relative =
            strtolower(
                $relative
            );

        return
            str_ends_with(
                $relative,
                '.thumb.webp'
            )
            || str_ends_with(
                $relative,
                '.optimized.webp'
            );
    }
}
