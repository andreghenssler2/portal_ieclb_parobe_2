<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste vídeos v1.1.6 R2\n";
echo str_repeat('=', 84) . "\n";

$errors = 0;
$version = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";
if ($version !== '1.1.6') {
    echo "[FALHA] Versão esperada: 1.1.6\n";
    $errors++;
}

if (!method_exists(MediaService::class, 'isVideo') || !MediaService::isVideo(['mime_type' => 'video/mp4'])) {
    echo "[FALHA] MediaService::isVideo.\n";
    $errors++;
} else {
    echo "[OK] MediaService::isVideo.\n";
}

$checks = [
    'admin/midias/index.php' => [
        'PORTAL_VIDEO_LIBRARY_V116_R2',
        "'videos'",
        "m.mime_type IN ('video/mp4','application/mp4')",
        '<video',
    ],
    'admin/midias/editar.php' => [
        'PORTAL_VIDEO_DETAILS_V116_R2',
        '<video',
    ],
    'admin/_editor_media_picker.php' => [
        'data-media-kind',
        'video/mp4',
        '<video',
    ],
    'admin/midias/upload-editor.php' => [
        "'kind' => \$isVideo ? 'video' : 'image'",
        'Este seletor aceita somente imagens e vídeos MP4.',
    ],
    'public/js/editor-media-picker.js' => [
        "item.kind === 'video'",
        '<video controls preload="metadata"',
    ],
    'admin/noticias/form.php' => [
        'PORTAL_VIDEO_EDITOR_V116_R2',
        "mime_type IN ('video/mp4','application/mp4')",
    ],
    'admin/paginas/form.php' => [
        'PORTAL_VIDEO_EDITOR_V116_R2',
        'PORTAL_PAGE_MODSEC_TRANSPORT_V116_R2',
    ],
];

foreach ($checks as $relative => $markers) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = is_file($file) ? (file_get_contents($file) ?: '') : '';

    foreach ($markers as $marker) {
        $ok = str_contains($content, $marker);
        echo '[' . ($ok ? 'OK' : 'FALHA') . "] {$relative}: {$marker}\n";
        if (!$ok) $errors++;
    }
}

echo str_repeat('=', 84) . "\n";
if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: recursos de vídeo v1.1.6 R2 aprovados.\n";
exit(0);
