<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id = max(0, (int)($_GET['id'] ?? 0));

if (
    !in_array($type, ['post', 'evento', 'comunidade'], true)
    || $id <= 0
) {
    http_response_code(404);
    exit;
}

$title = '';
$subtitle = '';

try {
    if ($type === 'post') {
        $stmt = $pdo->prepare(
            "SELECT titulo,resumo
             FROM posts
             WHERE id=?
               AND status='publicado'
               AND (publicado_em IS NULL OR publicado_em<=NOW())
               AND NOT EXISTS (
                    SELECT 1
                    FROM post_publicacao_extras ex
                    WHERE ex.post_id=posts.id
                      AND ex.publicado_ate IS NOT NULL
                      AND ex.publicado_ate<=NOW()
               )
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $title = (string)$row['titulo'];
            $subtitle = 'Notícia';
        }
    } elseif ($type === 'evento') {
        $stmt = $pdo->prepare(
            "SELECT titulo,data_inicio
             FROM eventos
             WHERE id=?
               AND status='publicado'
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $title = (string)$row['titulo'];
            $subtitle = 'Evento';

            if (
                !empty($row['data_inicio'])
                && strtotime((string)$row['data_inicio']) !== false
            ) {
                $subtitle .= ' · ' . date(
                    'd/m/Y',
                    strtotime((string)$row['data_inicio'])
                );
            }
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT nome,cidade,uf
             FROM comunidades
             WHERE id=?
               AND ativa=1
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $title = (string)$row['nome'];
            $subtitle = 'Comunidade';

            $place = trim(
                (string)($row['cidade'] ?? '')
                . (
                    !empty($row['uf'])
                        ? '/' . (string)$row['uf']
                        : ''
                )
            );

            if ($place !== '') {
                $subtitle .= ' · ' . $place;
            }
        }
    }
} catch (Throwable $ignored) {
}

if ($title === '') {
    http_response_code(404);
    exit;
}

$siteName = trim(
    siteConfig(
        $pdo,
        'site_nome',
        defined('APP_NAME')
            ? (string)APP_NAME
            : 'IECLB Parobé'
    )
);

$fallback =
    __DIR__
    . DIRECTORY_SEPARATOR
    . 'public'
    . DIRECTORY_SEPARATOR
    . 'images'
    . DIRECTORY_SEPARATOR
    . 'social-auto-default-v1114.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('X-Content-Type-Options: nosniff');

if (
    !extension_loaded('gd')
    || !function_exists('imagecreatetruecolor')
) {
    if (is_file($fallback)) {
        readfile($fallback);
        exit;
    }

    http_response_code(503);
    exit;
}

$width = 1200;
$height = 630;

$image = imagecreatetruecolor(
    $width,
    $height
);

if (!$image) {
    if (is_file($fallback)) {
        readfile($fallback);
        exit;
    }

    http_response_code(503);
    exit;
}

$hexToRgb =
    static function (
        string $hex,
        array $default
    ): array {
        $hex = ltrim(trim($hex), '#');

        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return $default;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    };

$primary = $hexToRgb(
    siteConfig($pdo, 'aparencia_cor_primaria', '#0b5d4b'),
    [11, 93, 75]
);

$secondary = $hexToRgb(
    siteConfig($pdo, 'aparencia_cor_secundaria', '#6c757d'),
    [108, 117, 125]
);

$bg = imagecolorallocate(
    $image,
    $primary[0],
    $primary[1],
    $primary[2]
);

$accent = imagecolorallocate(
    $image,
    $secondary[0],
    $secondary[1],
    $secondary[2]
);

$white = imagecolorallocate(
    $image,
    255,
    255,
    255
);

$soft = imagecolorallocate(
    $image,
    230,
    238,
    235
);

imagefilledrectangle(
    $image,
    0,
    0,
    $width,
    $height,
    $bg
);

imagefilledrectangle(
    $image,
    0,
    500,
    $width,
    $height,
    $accent
);

imagefilledrectangle(
    $image,
    75,
    76,
    1125,
    84,
    $white
);

$fontCandidates = [
    __DIR__ . '/public/fonts/DejaVuSans-Bold.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
];

$font = '';

foreach ($fontCandidates as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}

$plainCandidates = [
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
];

$plainFont = '';

foreach ($plainCandidates as $candidate) {
    if (is_file($candidate)) {
        $plainFont = $candidate;
        break;
    }
}

$wrap =
    static function (
        string $text,
        int $maxChars
    ): array {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === ''
                ? $word
                : $line . ' ' . $word;

            if (
                mb_strlen($candidate) > $maxChars
                && $line !== ''
            ) {
                $lines[] = $line;
                $line = $word;

                if (count($lines) >= 3) {
                    break;
                }
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '' && count($lines) < 3) {
            $lines[] = $line;
        }

        return array_slice($lines, 0, 3);
    };

$titleLines = $wrap($title, 31);

if (
    $font !== ''
    && function_exists('imagettftext')
) {
    $y = 170;

    foreach ($titleLines as $line) {
        imagettftext(
            $image,
            48,
            0,
            80,
            $y,
            $white,
            $font,
            $line
        );

        $y += 66;
    }

    if ($plainFont !== '') {
        imagettftext(
            $image,
            27,
            0,
            82,
            455,
            $soft,
            $plainFont,
            $subtitle
        );

        imagettftext(
            $image,
            27,
            0,
            82,
            570,
            $white,
            $plainFont,
            $siteName
        );
    }
} else {
    $y = 150;

    foreach ($titleLines as $line) {
        imagestring(
            $image,
            5,
            80,
            $y,
            $line,
            $white
        );

        $y += 40;
    }

    imagestring(
        $image,
        4,
        80,
        445,
        $subtitle,
        $soft
    );

    imagestring(
        $image,
        4,
        80,
        555,
        $siteName,
        $white
    );
}

imagepng(
    $image,
    null,
    6
);

imagedestroy(
    $image
);
