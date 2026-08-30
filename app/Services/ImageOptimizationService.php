<?php

declare(strict_types=1);

/**
 * Otimização e variantes de imagens.
 *
 * Requer GD apenas para gerar/reescalar imagens. Quando GD não estiver
 * disponível, o upload continua funcionando normalmente.
 */
final class ImageOptimizationService
{
    private static bool $schemaEnsured = false;

    public const VARIANT_WEBP = 'webp';
    public const VARIANT_THUMB = 'thumb';

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS midia_variantes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                midia_id INT NOT NULL,
                tipo VARCHAR(20) NOT NULL,
                caminho VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                largura INT NULL,
                altura INT NULL,
                tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_midia_variante (midia_id,tipo),
                KEY idx_midia_variante_tipo (tipo),
                KEY idx_midia_variante_midia (midia_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled');
    }

    /**
     * @return array<string,mixed>
     */
    public static function settings(PDO $pdo): array
    {
        return [
            'enabled' =>
                siteConfig(
                    $pdo,
                    'media_optimize_images',
                    '1'
                ) === '1',

            'max_width' =>
                max(
                    0,
                    min(
                        6000,
                        (int)siteConfig(
                            $pdo,
                            'media_image_max_width',
                            '1920'
                        )
                    )
                ),

            'webp' =>
                siteConfig(
                    $pdo,
                    'media_generate_webp',
                    '1'
                ) === '1',

            'webp_quality' =>
                max(
                    45,
                    min(
                        95,
                        (int)siteConfig(
                            $pdo,
                            'media_webp_quality',
                            '82'
                        )
                    )
                ),

            'thumb' =>
                siteConfig(
                    $pdo,
                    'media_generate_thumbnail',
                    '1'
                ) === '1',

            'thumb_width' =>
                max(
                    160,
                    min(
                        1200,
                        (int)siteConfig(
                            $pdo,
                            'media_thumbnail_width',
                            '480'
                        )
                    )
                ),
        ];
    }

    /**
     * Processa uma mídia de imagem.
     *
     * @return array<string,mixed>
     */
    public static function process(
        PDO $pdo,
        int $mediaId,
        bool $force = false
    ): array {
        self::ensureSchema($pdo);

        $media =
            MediaService::find(
                $pdo,
                $mediaId
            );

        if (!$media) {
            throw new RuntimeException(
                'Mídia não encontrada.'
            );
        }

        if (!MediaService::isImage($media)) {
            return [
                'processed' => false,
                'reason' => 'not_image',
                'media' => $media,
                'variants' => [],
            ];
        }

        $settings =
            self::settings($pdo);

        if (!$settings['enabled'] && !$force) {
            return [
                'processed' => false,
                'reason' => 'disabled',
                'media' => $media,
                'variants' => self::variants($pdo, $mediaId),
            ];
        }

        if (!self::gdAvailable()) {
            return [
                'processed' => false,
                'reason' => 'gd_unavailable',
                'media' => $media,
                'variants' => self::variants($pdo, $mediaId),
            ];
        }

        $mime =
            strtolower(
                trim(
                    (string)(
                        $media['mime_type']
                        ?? ''
                    )
                )
            );

        /*
         * GIF pode ser animado; GD perderia a animação.
         */
        if ($mime === 'image/gif') {
            return [
                'processed' => false,
                'reason' => 'gif_skipped',
                'media' => $media,
                'variants' => self::variants($pdo, $mediaId),
            ];
        }

        $absolute =
            self::absolutePath(
                (string)$media['caminho']
            );

        if (!is_file($absolute)) {
            throw new RuntimeException(
                'Arquivo físico da mídia não encontrado.'
            );
        }

        $image =
            self::loadImage(
                $absolute,
                $mime
            );

        if (!$image) {
            return [
                'processed' => false,
                'reason' => 'unsupported_decoder',
                'media' => $media,
                'variants' => self::variants($pdo, $mediaId),
            ];
        }

        try {
            $width =
                imagesx($image);

            $height =
                imagesy($image);

            if (
                $width <= 0
                || $height <= 0
            ) {
                throw new RuntimeException(
                    'Dimensões da imagem inválidas.'
                );
            }

            $maxWidth =
                (int)$settings['max_width'];

            if (
                $maxWidth > 0
                && $width > $maxWidth
            ) {
                $newWidth =
                    $maxWidth;

                $newHeight =
                    max(
                        1,
                        (int)round(
                            $height
                            * (
                                $newWidth
                                / $width
                            )
                        )
                    );

                $resized =
                    self::resize(
                        $image,
                        $newWidth,
                        $newHeight,
                        $mime
                    );

                if ($resized) {
                    if (
                        self::saveOriginalFormat(
                            $resized,
                            $absolute,
                            $mime,
                            (int)$settings['webp_quality']
                        )
                    ) {
                        imagedestroy($image);
                        $image = $resized;

                        $width =
                            $newWidth;

                        $height =
                            $newHeight;

                        @chmod(
                            $absolute,
                            0644
                        );

                        $size =
                            @filesize(
                                $absolute
                            );

                        $stmt =
                            $pdo->prepare(
                                "UPDATE midias
                                 SET
                                    largura=:largura,
                                    altura=:altura,
                                    tamanho=:tamanho
                                 WHERE id=:id"
                            );

                        $stmt->execute([
                            'largura' => $width,
                            'altura' => $height,
                            'tamanho' =>
                                is_int($size)
                                    ? $size
                                    : (int)$media['tamanho'],
                            'id' => $mediaId,
                        ]);
                    } else {
                        imagedestroy(
                            $resized
                        );
                    }
                }
            }

            if (
                $settings['webp']
                && function_exists('imagewebp')
                && $mime !== 'image/webp'
            ) {
                $webpPath =
                    self::variantPath(
                        (string)$media['caminho'],
                        '.optimized.webp'
                    );

                self::saveWebpVariant(
                    $pdo,
                    $mediaId,
                    self::VARIANT_WEBP,
                    $image,
                    $webpPath,
                    $width,
                    $height,
                    (int)$settings['webp_quality']
                );
            } elseif ($mime === 'image/webp') {
                /*
                 * Não cria cópia WebP de um WebP original.
                 */
                self::deleteVariant(
                    $pdo,
                    $mediaId,
                    self::VARIANT_WEBP
                );
            }

            if (
                $settings['thumb']
                && function_exists('imagewebp')
            ) {
                $thumbWidth =
                    min(
                        $width,
                        (int)$settings['thumb_width']
                    );

                $thumbHeight =
                    max(
                        1,
                        (int)round(
                            $height
                            * (
                                $thumbWidth
                                / $width
                            )
                        )
                    );

                $thumb =
                    self::resize(
                        $image,
                        $thumbWidth,
                        $thumbHeight,
                        'image/webp'
                    );

                if ($thumb) {
                    $thumbPath =
                        self::variantPath(
                            (string)$media['caminho'],
                            '.thumb.webp'
                        );

                    self::saveWebpVariant(
                        $pdo,
                        $mediaId,
                        self::VARIANT_THUMB,
                        $thumb,
                        $thumbPath,
                        $thumbWidth,
                        $thumbHeight,
                        max(
                            55,
                            min(
                                90,
                                (int)$settings['webp_quality'] - 4
                            )
                        )
                    );

                    imagedestroy(
                        $thumb
                    );
                }
            }

        } finally {
            if (
                is_object($image)
                || is_resource($image)
            ) {
                @imagedestroy($image);
            }
        }

        $media =
            MediaService::find(
                $pdo,
                $mediaId
            )
            ?: $media;

        return [
            'processed' => true,
            'reason' => 'ok',
            'media' => $media,
            'variants' => self::variants($pdo, $mediaId),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function variants(
        PDO $pdo,
        int $mediaId
    ): array {
        self::ensureSchema($pdo);

        $stmt =
            $pdo->prepare(
                "SELECT
                    id,
                    midia_id,
                    tipo,
                    caminho,
                    mime_type,
                    largura,
                    altura,
                    tamanho,
                    created_at,
                    updated_at
                 FROM midia_variantes
                 WHERE midia_id=:midia_id
                 ORDER BY tipo"
            );

        $stmt->execute([
            'midia_id' => $mediaId,
        ]);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        foreach ($rows as &$row) {
            $row['url'] =
                mediaUrl(
                    (string)$row['caminho']
                );

            $row['exists'] =
                is_file(
                    self::absolutePath(
                        (string)$row['caminho']
                    )
                );
        }

        unset($row);

        return $rows;
    }

    public static function bestUrl(
        PDO $pdo,
        int $mediaId,
        string $type,
        string $fallbackPath
    ): string {
        self::ensureSchema($pdo);

        if (
            !in_array(
                $type,
                [
                    self::VARIANT_WEBP,
                    self::VARIANT_THUMB,
                ],
                true
            )
        ) {
            return mediaUrl(
                $fallbackPath
            );
        }

        $stmt =
            $pdo->prepare(
                "SELECT caminho
                 FROM midia_variantes
                 WHERE midia_id=:midia_id
                   AND tipo=:tipo
                 LIMIT 1"
            );

        $stmt->execute([
            'midia_id' => $mediaId,
            'tipo' => $type,
        ]);

        $path =
            trim(
                (string)(
                    $stmt->fetchColumn()
                    ?: ''
                )
            );

        if (
            $path !== ''
            && is_file(
                self::absolutePath(
                    $path
                )
            )
        ) {
            return mediaUrl($path);
        }

        return mediaUrl(
            $fallbackPath
        );
    }

    public static function deleteVariants(
        PDO $pdo,
        int $mediaId
    ): void {
        self::ensureSchema($pdo);

        $stmt =
            $pdo->prepare(
                "SELECT caminho
                 FROM midia_variantes
                 WHERE midia_id=:midia_id"
            );

        $stmt->execute([
            'midia_id' => $mediaId,
        ]);

        $paths =
            $stmt->fetchAll(
                PDO::FETCH_COLUMN
            ) ?: [];

        $delete =
            $pdo->prepare(
                "DELETE FROM midia_variantes
                 WHERE midia_id=:midia_id"
            );

        $delete->execute([
            'midia_id' => $mediaId,
        ]);

        foreach ($paths as $path) {
            $absolute =
                self::absolutePath(
                    (string)$path
                );

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    /**
     * @return array{
     *   total_images:int,
     *   webp:int,
     *   thumbs:int
     * }
     */
    public static function summary(
        PDO $pdo
    ): array {
        self::ensureSchema($pdo);

        $images =
            (int)$pdo
                ->query(
                    "SELECT COUNT(*)
                     FROM midias
                     WHERE mime_type LIKE 'image/%'"
                )
                ->fetchColumn();

        $stmt =
            $pdo->query(
                "SELECT
                    SUM(CASE WHEN tipo='webp' THEN 1 ELSE 0 END) AS webp,
                    SUM(CASE WHEN tipo='thumb' THEN 1 ELSE 0 END) AS thumbs
                 FROM midia_variantes"
            );

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            ) ?: [];

        return [
            'total_images' => $images,
            'webp' =>
                (int)($row['webp'] ?? 0),
            'thumbs' =>
                (int)($row['thumbs'] ?? 0),
        ];
    }

    /**
     * @return array{
     *   processed:int,
     *   skipped:int,
     *   errors:array<int,string>,
     *   last_id:int,
     *   has_more:bool
     * }
     */
    public static function processBatch(
        PDO $pdo,
        int $afterId = 0,
        int $limit = 20,
        bool $force = false
    ): array {
        self::ensureSchema($pdo);

        $afterId =
            max(
                0,
                $afterId
            );

        $limit =
            max(
                1,
                min(
                    50,
                    $limit
                )
            );

        $stmt =
            $pdo->prepare(
                "SELECT id
                 FROM midias
                 WHERE mime_type LIKE 'image/%'
                   AND id > :after_id
                 ORDER BY id ASC
                 LIMIT {$limit}"
            );

        $stmt->execute([
            'after_id' => $afterId,
        ]);

        $ids =
            array_map(
                'intval',
                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            );

        $processed = 0;
        $skipped = 0;
        $errors = [];
        $lastId = $afterId;

        foreach ($ids as $id) {
            $lastId =
                max(
                    $lastId,
                    $id
                );

            try {
                $result =
                    self::process(
                        $pdo,
                        $id,
                        $force
                    );

                if (
                    $result['processed']
                    ?? false
                ) {
                    $processed++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors[] =
                    '#'
                    . $id
                    . ': '
                    . $e->getMessage();
            }
        }

        $moreStmt =
            $pdo->prepare(
                "SELECT COUNT(*)
                 FROM midias
                 WHERE mime_type LIKE 'image/%'
                   AND id > :after_id"
            );

        $moreStmt->execute([
            'after_id' => $lastId,
        ]);

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' =>
                (int)$moreStmt->fetchColumn() > 0,
        ];
    }

    private static function deleteVariant(
        PDO $pdo,
        int $mediaId,
        string $type
    ): void {
        self::ensureSchema($pdo);

        $stmt =
            $pdo->prepare(
                "SELECT caminho
                 FROM midia_variantes
                 WHERE midia_id=:midia_id
                   AND tipo=:tipo
                 LIMIT 1"
            );

        $stmt->execute([
            'midia_id' => $mediaId,
            'tipo' => $type,
        ]);

        $path =
            trim(
                (string)(
                    $stmt->fetchColumn()
                    ?: ''
                )
            );

        $delete =
            $pdo->prepare(
                "DELETE FROM midia_variantes
                 WHERE midia_id=:midia_id
                   AND tipo=:tipo"
            );

        $delete->execute([
            'midia_id' => $mediaId,
            'tipo' => $type,
        ]);

        if ($path !== '') {
            $absolute =
                self::absolutePath(
                    $path
                );

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    private static function saveWebpVariant(
        PDO $pdo,
        int $mediaId,
        string $type,
        mixed $image,
        string $relativePath,
        int $width,
        int $height,
        int $quality
    ): void {
        $absolute =
            self::absolutePath(
                $relativePath
            );

        $dir =
            dirname($absolute);

        if (
            !is_dir($dir)
            && !@mkdir(
                $dir,
                0755,
                true
            )
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Não foi possível criar a pasta da variante.'
            );
        }

        if (
            !imagewebp(
                $image,
                $absolute,
                $quality
            )
        ) {
            throw new RuntimeException(
                'Não foi possível gerar a variante WebP.'
            );
        }

        @chmod(
            $absolute,
            0644
        );

        $size =
            @filesize($absolute);

        $stmt =
            $pdo->prepare(
                "INSERT INTO midia_variantes
                    (
                        midia_id,
                        tipo,
                        caminho,
                        mime_type,
                        largura,
                        altura,
                        tamanho,
                        created_at,
                        updated_at
                    )
                 VALUES
                    (
                        :midia_id,
                        :tipo,
                        :caminho,
                        'image/webp',
                        :largura,
                        :altura,
                        :tamanho,
                        NOW(),
                        NOW()
                    )
                 ON DUPLICATE KEY UPDATE
                    caminho=VALUES(caminho),
                    mime_type=VALUES(mime_type),
                    largura=VALUES(largura),
                    altura=VALUES(altura),
                    tamanho=VALUES(tamanho),
                    updated_at=NOW()"
            );

        $stmt->execute([
            'midia_id' => $mediaId,
            'tipo' => $type,
            'caminho' => $relativePath,
            'largura' => $width,
            'altura' => $height,
            'tamanho' =>
                is_int($size)
                    ? $size
                    : 0,
        ]);
    }

    private static function loadImage(
        string $path,
        string $mime
    ): mixed {
        return match ($mime) {
            'image/jpeg' =>
                function_exists('imagecreatefromjpeg')
                    ? @imagecreatefromjpeg($path)
                    : false,

            'image/png' =>
                function_exists('imagecreatefrompng')
                    ? @imagecreatefrompng($path)
                    : false,

            'image/webp' =>
                function_exists('imagecreatefromwebp')
                    ? @imagecreatefromwebp($path)
                    : false,

            default => false,
        };
    }

    private static function resize(
        mixed $source,
        int $width,
        int $height,
        string $mime
    ): mixed {
        $canvas =
            imagecreatetruecolor(
                $width,
                $height
            );

        if (!$canvas) {
            return false;
        }

        /*
         * Transparência para PNG/WebP.
         */
        if (
            in_array(
                $mime,
                [
                    'image/png',
                    'image/webp',
                ],
                true
            )
        ) {
            imagealphablending(
                $canvas,
                false
            );

            imagesavealpha(
                $canvas,
                true
            );

            $transparent =
                imagecolorallocatealpha(
                    $canvas,
                    0,
                    0,
                    0,
                    127
                );

            imagefilledrectangle(
                $canvas,
                0,
                0,
                $width,
                $height,
                $transparent
            );
        }

        $ok =
            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                imagesx($source),
                imagesy($source)
            );

        if (!$ok) {
            imagedestroy(
                $canvas
            );

            return false;
        }

        return $canvas;
    }

    private static function saveOriginalFormat(
        mixed $image,
        string $path,
        string $mime,
        int $quality
    ): bool {
        return match ($mime) {
            'image/jpeg' =>
                function_exists('imagejpeg')
                    ? imagejpeg(
                        $image,
                        $path,
                        max(
                            75,
                            min(
                                94,
                                $quality + 4
                            )
                        )
                    )
                    : false,

            'image/png' =>
                function_exists('imagepng')
                    ? imagepng(
                        $image,
                        $path,
                        6
                    )
                    : false,

            'image/webp' =>
                function_exists('imagewebp')
                    ? imagewebp(
                        $image,
                        $path,
                        $quality
                    )
                    : false,

            default => false,
        };
    }

    private static function variantPath(
        string $sourcePath,
        string $suffix
    ): string {
        $sourcePath =
            ltrim(
                str_replace(
                    '\\',
                    '/',
                    $sourcePath
                ),
                '/'
            );

        $dir =
            trim(
                str_replace(
                    '\\',
                    '/',
                    dirname($sourcePath)
                ),
                './'
            );

        $name =
            pathinfo(
                $sourcePath,
                PATHINFO_FILENAME
            );

        $file =
            $name
            . $suffix;

        return
            $dir !== ''
            && $dir !== '.'
                ? $dir . '/' . $file
                : $file;
    }

    private static function absolutePath(
        string $relativePath
    ): string {
        $relativePath =
            ltrim(
                str_replace(
                    '\\',
                    '/',
                    $relativePath
                ),
                '/'
            );

        if (
            $relativePath === ''
            || str_contains(
                $relativePath,
                '..'
            )
        ) {
            return '';
        }

        return
            dirname(__DIR__, 2)
            . '/'
            . $relativePath;
    }
}
