<?php

declare(strict_types=1);

/**
 * Gera derivados redimensionados para imagens da Biblioteca de Mídia.
 *
 * O arquivo original nunca é sobrescrito. As variantes ficam em
 * uploads/.../variants/ e são registradas em midia_variantes.
 */
final class ImageOptimizationService
{
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function driver(): string
    {
        if (class_exists('Imagick')) {
            return 'imagick';
        }
        if (function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled')) {
            return 'gd';
        }
        return 'none';
    }

    public static function driverLabel(): string
    {
        return match (self::driver()) {
            'imagick' => 'Imagick',
            'gd' => 'GD',
            default => 'Nenhum',
        };
    }

    public static function webpSupported(): bool
    {
        if (self::driver() === 'imagick') {
            try {
                return in_array('WEBP', array_map('strtoupper', \Imagick::queryFormats('WEBP')), true);
            } catch (Throwable $e) {
                return false;
            }
        }
        return function_exists('imagewebp');
    }

    /** @return array{enabled:bool,webp:bool,widths:array<int,int>,quality:int} */
    public static function settings(PDO $pdo): array
    {
        return [
            'enabled' => siteConfig($pdo, 'media_optimize_enabled', '1') === '1',
            'webp' => siteConfig($pdo, 'media_generate_webp', '1') === '1' && self::webpSupported(),
            'widths' => self::parseWidths(siteConfig($pdo, 'media_variant_widths', '320,640,1024,1600')),
            'quality' => max(50, min(95, (int)siteConfig($pdo, 'media_image_quality', '82'))),
        ];
    }

    /** @return int[] */
    public static function parseWidths(string $raw): array
    {
        $parts = preg_split('/[^0-9]+/', $raw) ?: [];
        $widths = [];
        foreach ($parts as $part) {
            $width = (int)$part;
            if ($width >= 160 && $width <= 4096) {
                $widths[$width] = $width;
            }
        }
        if (!$widths) {
            $widths = [320 => 320, 640 => 640, 1024 => 1024, 1600 => 1600];
        }
        ksort($widths, SORT_NUMERIC);
        return array_values($widths);
    }

    public static function isSupportedMedia(array $media): bool
    {
        return in_array(strtolower((string)($media['mime_type'] ?? '')), self::SUPPORTED_MIMES, true)
            && trim((string)($media['caminho'] ?? '')) !== '';
    }

    /**
     * @return array{ok:bool,created:int,skipped:int,message:string,original_bytes:int,variant_bytes:int}
     */
    public static function optimizeMedia(PDO $pdo, int $mediaId, bool $force = false): array
    {
        $media = self::findMedia($pdo, $mediaId);
        if (!$media) {
            return self::result(false, 0, 0, 'Mídia não encontrada.');
        }
        if (!self::isSupportedMedia($media)) {
            return self::result(false, 0, 1, 'Formato não otimizado. JPEG, PNG e WebP são suportados.');
        }
        if (self::driver() === 'none') {
            return self::result(false, 0, 0, 'Nenhuma biblioteca de imagem disponível. Habilite GD ou Imagick no PHP.');
        }

        $settings = self::settings($pdo);
        if (!$settings['enabled'] && !$force) {
            return self::result(false, 0, 1, 'Otimização automática está desativada.');
        }

        $root = dirname(__DIR__, 2);
        $relative = ltrim(str_replace('\\', '/', (string)$media['caminho']), '/');
        $absolute = $root . '/' . $relative;
        if (!is_file($absolute)) {
            return self::result(false, 0, 0, 'Arquivo original não encontrado no servidor.');
        }

        $info = @getimagesize($absolute);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return self::result(false, 0, 0, 'Não foi possível ler as dimensões da imagem.');
        }
        $originalWidth = (int)$info[0];
        $originalHeight = (int)$info[1];
        $mime = strtolower((string)($info['mime'] ?? $media['mime_type'] ?? ''));
        [$originalWidth, $originalHeight] = self::orientedDimensions($absolute, $mime, $originalWidth, $originalHeight);
        if (!in_array($mime, self::SUPPORTED_MIMES, true)) {
            return self::result(false, 0, 1, 'Formato de imagem não suportado para otimização.');
        }

        // Evita estouro de memória em GD com fotografias gigantescas.
        if (self::driver() === 'gd' && ($originalWidth * $originalHeight) > 40000000) {
            return self::result(false, 0, 1, 'Imagem muito grande para processamento seguro com GD. Use Imagick ou reduza o original.');
        }

        if ($force) {
            self::deleteVariants($pdo, $mediaId, true);
        }

        $maxConfigured = max($settings['widths']);
        $canonicalWidth = min($originalWidth, $maxConfigured);
        $targets = [];
        foreach ($settings['widths'] as $width) {
            if ($width < $originalWidth) {
                $targets[$width] = $width;
            }
        }
        $targets[$canonicalWidth] = $canonicalWidth;
        ksort($targets, SORT_NUMERIC);

        $variantDir = dirname($absolute) . '/variants';
        if (!is_dir($variantDir) && !@mkdir($variantDir, 0755, true) && !is_dir($variantDir)) {
            return self::result(false, 0, 0, 'Não foi possível criar a pasta de variantes da imagem.');
        }

        $baseName = pathinfo($absolute, PATHINFO_FILENAME);
        $created = 0;
        $skipped = 0;
        $variantBytes = 0;

        foreach ($targets as $targetWidth) {
            $targetHeight = max(1, (int)round($originalHeight * ($targetWidth / $originalWidth)));
            $formats = [self::sourceExtension($mime)];
            if ($settings['webp'] && $mime !== 'image/webp') {
                $formats[] = 'webp';
            }
            $formats = array_values(array_unique($formats));

            foreach ($formats as $format) {
                $fileName = $baseName . '-' . $targetWidth . 'w.' . $format;
                $variantAbsolute = $variantDir . '/' . $fileName;
                $variantRelative = trim(dirname($relative), './') . '/variants/' . $fileName;
                $variantRelative = ltrim(str_replace('\\', '/', $variantRelative), '/');

                if (!$force && is_file($variantAbsolute) && filesize($variantAbsolute) > 0) {
                    self::upsertVariant($pdo, $mediaId, $targetWidth, $targetHeight, $format, $variantRelative, (int)filesize($variantAbsolute), $settings['quality']);
                    $variantBytes += (int)filesize($variantAbsolute);
                    $skipped++;
                    continue;
                }

                try {
                    self::render($absolute, $mime, $variantAbsolute, $format, $targetWidth, $targetHeight, $settings['quality']);
                    if (is_file($variantAbsolute) && filesize($variantAbsolute) > 0) {
                        @chmod($variantAbsolute, 0644);
                        $bytes = (int)filesize($variantAbsolute);
                        self::upsertVariant($pdo, $mediaId, $targetWidth, $targetHeight, $format, $variantRelative, $bytes, $settings['quality']);
                        $variantBytes += $bytes;
                        $created++;
                    }
                } catch (Throwable $e) {
                    @unlink($variantAbsolute);
                    // Uma variante com falha não deve impedir as demais.
                }
            }
        }

        $message = $created > 0
            ? $created . ' variante(s) gerada(s).'
            : ($skipped > 0 ? 'As variantes já estavam atualizadas.' : 'Nenhuma variante pôde ser gerada.');

        return [
            'ok' => $created > 0 || $skipped > 0,
            'created' => $created,
            'skipped' => $skipped,
            'message' => $message,
            'original_bytes' => (int)($media['tamanho'] ?? @filesize($absolute) ?: 0),
            'variant_bytes' => $variantBytes,
        ];
    }

    public static function deleteVariants(PDO $pdo, int $mediaId, bool $deleteFiles = true): int
    {
        $media = self::findMedia($pdo, $mediaId);
        $rows = [];
        try {
            $stmt = $pdo->prepare('SELECT caminho FROM midia_variantes WHERE midia_id=:id');
            $stmt->execute(['id' => $mediaId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        if ($deleteFiles) {
            $root = dirname(__DIR__, 2);
            foreach ($rows as $row) {
                $file = $root . '/' . ltrim((string)($row['caminho'] ?? ''), '/');
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            // Remove também derivados órfãos que tenham o mesmo basename.
            if ($media && !empty($media['caminho'])) {
                $original = $root . '/' . ltrim((string)$media['caminho'], '/');
                $variantDir = dirname($original) . '/variants';
                $base = pathinfo($original, PATHINFO_FILENAME);
                foreach (glob($variantDir . '/' . $base . '-*w.*') ?: [] as $file) {
                    if (is_file($file)) @unlink($file);
                }
                if (is_dir($variantDir) && count(scandir($variantDir) ?: []) <= 2) {
                    @rmdir($variantDir);
                }
            }
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM midia_variantes WHERE midia_id=:id');
            $stmt->execute(['id' => $mediaId]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function variants(PDO $pdo, int $mediaId): array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM midia_variantes WHERE midia_id=:id ORDER BY largura ASC, formato ASC');
            $stmt->execute(['id' => $mediaId]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function bestUrlForMedia(PDO $pdo, int $mediaId, int $preferredWidth = 1600): string
    {
        $preferredWidth = max(160, min(4096, $preferredWidth));
        $rows = self::variants($pdo, $mediaId);
        if ($rows) {
            usort($rows, static function (array $a, array $b) use ($preferredWidth): int {
                $wa = (int)($a['largura'] ?? 0);
                $wb = (int)($b['largura'] ?? 0);
                $score = static function (array $row, int $w) use ($preferredWidth): array {
                    $formatScore = strtolower((string)($row['formato'] ?? '')) === 'webp' ? 0 : 1;
                    $distance = $w >= $preferredWidth ? ($w - $preferredWidth) : (($preferredWidth - $w) + 10000);
                    return [$distance, $formatScore];
                };
                return $score($a, $wa) <=> $score($b, $wb);
            });
            $path = trim((string)($rows[0]['caminho'] ?? ''));
            if ($path !== '') return url(ltrim($path, '/'));
        }

        $media = self::findMedia($pdo, $mediaId);
        return $media ? self::rawPublicUrl((string)$media['caminho']) : '';
    }

    /**
     * Usada pelo helper mediaUrl(). Procura a maior variante local existente,
     * preferindo WebP, e mantém a URL original como fallback.
     */
    public static function publicUrlForPath(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $root = dirname(__DIR__, 2);
        $absolute = $root . '/' . $relative;
        $dir = dirname($absolute) . '/variants';
        $base = pathinfo($absolute, PATHINFO_FILENAME);

        if (is_dir($dir)) {
            foreach (['webp', pathinfo($absolute, PATHINFO_EXTENSION), 'jpg', 'png'] as $ext) {
                $ext = strtolower(trim((string)$ext));
                if ($ext === '') continue;
                $matches = glob($dir . '/' . $base . '-*w.' . $ext) ?: [];
                $best = null;
                $bestWidth = 0;
                foreach ($matches as $file) {
                    if (preg_match('/-(\d+)w\.[a-z0-9]+$/i', basename($file), $m)) {
                        $width = (int)$m[1];
                        if ($width > $bestWidth && is_file($file) && filesize($file) > 0) {
                            $bestWidth = $width;
                            $best = $file;
                        }
                    }
                }
                if ($best) {
                    $bestRelative = ltrim(str_replace('\\', '/', substr($best, strlen($root))), '/');
                    return url($bestRelative);
                }
            }
        }

        return self::rawPublicUrl($relative);
    }

    private static function findMedia(PDO $pdo, int $id): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM midias WHERE id=:id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function upsertVariant(PDO $pdo, int $mediaId, int $width, int $height, string $format, string $path, int $size, int $quality): void
    {
        $mime = match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
        $stmt = $pdo->prepare(
            'INSERT INTO midia_variantes (midia_id,largura,altura,formato,mime_type,caminho,tamanho,qualidade,created_at,updated_at)
             VALUES (:midia,:largura,:altura,:formato,:mime,:caminho,:tamanho,:qualidade,NOW(),NOW())
             ON DUPLICATE KEY UPDATE altura=VALUES(altura),mime_type=VALUES(mime_type),caminho=VALUES(caminho),tamanho=VALUES(tamanho),qualidade=VALUES(qualidade),updated_at=NOW()'
        );
        $stmt->execute([
            'midia' => $mediaId,
            'largura' => $width,
            'altura' => $height,
            'formato' => $format === 'jpeg' ? 'jpg' : $format,
            'mime' => $mime,
            'caminho' => $path,
            'tamanho' => $size,
            'qualidade' => $quality,
        ]);
    }

    private static function render(string $source, string $sourceMime, string $destination, string $format, int $width, int $height, int $quality): void
    {
        if (self::driver() === 'imagick') {
            self::renderImagick($source, $destination, $format, $width, $height, $quality);
            return;
        }
        self::renderGd($source, $sourceMime, $destination, $format, $width, $height, $quality);
    }

    private static function renderImagick(string $source, string $destination, string $format, int $width, int $height, int $quality): void
    {
        $image = new \Imagick($source);
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
        }
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        $image->stripImage();
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $image->thumbnailImage($width, $height, true, true);
        $image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
        if (in_array($format, ['jpg', 'jpeg', 'webp'], true)) {
            $image->setImageCompressionQuality($quality);
        }
        if (!$image->writeImage($destination)) {
            throw new RuntimeException('Imagick não conseguiu gravar a variante.');
        }
        $image->clear();
        $image->destroy();
    }

    private static function renderGd(string $source, string $sourceMime, string $destination, string $format, int $width, int $height, int $quality): void
    {
        $src = match ($sourceMime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$src) {
            throw new RuntimeException('GD não conseguiu abrir a imagem original.');
        }

        $src = self::orientGd($src, $source, $sourceMime);

        $dst = imagecreatetruecolor($width, $height);
        if (!$dst) {
            imagedestroy($src);
            throw new RuntimeException('GD não conseguiu criar a imagem redimensionada.');
        }

        if (in_array($format, ['png', 'webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $width, $height, $white);
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);

        $ok = match ($format) {
            'jpg', 'jpeg' => function_exists('imagejpeg') && imagejpeg($dst, $destination, $quality),
            'png' => function_exists('imagepng') && imagepng($dst, $destination, max(0, min(9, (int)round((100 - $quality) / 11.111)))),
            'webp' => function_exists('imagewebp') && imagewebp($dst, $destination, $quality),
            default => false,
        };

        imagedestroy($dst);
        imagedestroy($src);
        if (!$ok) {
            throw new RuntimeException('GD não conseguiu gravar a variante ' . $format . '.');
        }
    }


    /** @return array{0:int,1:int} */
    private static function orientedDimensions(string $source, string $mime, int $width, int $height): array
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return [$width, $height];
        }
        try {
            $exif = @exif_read_data($source);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if (in_array($orientation, [5, 6, 7, 8], true)) {
                return [$height, $width];
            }
        } catch (Throwable $e) {}
        return [$width, $height];
    }

    private static function orientGd($image, string $source, string $mime)
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        try {
            $exif = @exif_read_data($source);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if ($orientation === 3) {
                $rotated = imagerotate($image, 180, 0);
            } elseif ($orientation === 6) {
                $rotated = imagerotate($image, -90, 0);
            } elseif ($orientation === 8) {
                $rotated = imagerotate($image, 90, 0);
            } else {
                $rotated = false;
            }
            if ($rotated) {
                imagedestroy($image);
                return $rotated;
            }
        } catch (Throwable $e) {}
        return $image;
    }

    private static function sourceExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private static function rawPublicUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return url(ltrim(str_replace('\\', '/', $path), '/'));
    }

    /** @return array{ok:bool,created:int,skipped:int,message:string,original_bytes:int,variant_bytes:int} */
    private static function result(bool $ok, int $created, int $skipped, string $message): array
    {
        return [
            'ok' => $ok,
            'created' => $created,
            'skipped' => $skipped,
            'message' => $message,
            'original_bytes' => 0,
            'variant_bytes' => 0,
        ];
    }
}
