<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste MP4 v1.1.4 R1\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo '[INFO] APP_VERSION: ' . $version . "\n";

if ($version !== '1.1.4') {
    echo "[FALHA] Versão esperada: 1.1.4\n";
    $errors++;
}

if (!class_exists('MediaService')) {
    echo "[FALHA] MediaService indisponível.\n";
    exit(1);
}

$reflection =
    new ReflectionClass(
        MediaService::class
    );

$allowed =
    $reflection->getConstant(
        'ALLOWED_MIME_TYPES'
    );

$mimeOk =
    is_array($allowed)
    && ($allowed['video/mp4'] ?? null) === 'mp4'
    && ($allowed['application/mp4'] ?? null) === 'mp4';

echo '['
    . ($mimeOk ? 'OK' : 'FALHA')
    . "] MIME MP4\n";

if (!$mimeOk) {
    $errors++;
}

$videoMethodOk =
    method_exists(
        MediaService::class,
        'isVideo'
    )
    && MediaService::isVideo([
        'mime_type' => 'video/mp4',
    ])
    && MediaService::isVideo([
        'mime_type' => 'application/mp4',
    ])
    && !MediaService::isVideo([
        'mime_type' => 'image/jpeg',
    ]);

echo '['
    . ($videoMethodOk ? 'OK' : 'FALHA')
    . "] MediaService::isVideo\n";

if (!$videoMethodOk) {
    $errors++;
}

$serviceFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'MediaService.php';

$serviceContent =
    is_file($serviceFile)
        ? (file_get_contents($serviceFile) ?: '')
        : '';

if (
    str_contains(
        $serviceContent,
        'PORTAL_MP4_UPLOAD_V114_R1'
    )
) {
    echo "[OK] Marcador do MediaService.\n";
} else {
    echo "[FALHA] Marcador do MediaService ausente.\n";
    $errors++;
}

$indexFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'admin'
    . DIRECTORY_SEPARATOR
    . 'midias'
    . DIRECTORY_SEPARATOR
    . 'index.php';

$indexContent =
    is_file($indexFile)
        ? (file_get_contents($indexFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_MP4_UPLOAD_V114_R1',
        'video/mp4',
        '.mp4',
    ]
    as $marker
) {
    $ok =
        str_contains(
            $indexContent,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] Biblioteca: {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: upload MP4 v1.1.4 aprovado.\n";
exit(0);
