<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - Diagnóstico v1.1.4 R2\n";
echo str_repeat('=', 84) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.4') {
    echo "[ERRO] Versão esperada: 1.1.4\n";
    $errors++;
}

$file =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'TrustedEmbedService.php';

$content =
    is_file($file)
        ? (file_get_contents($file) ?: '')
        : '';

foreach (
    [
        'PORTAL_LOCAL_PDF_EMBED_V114_R2',
        'localPdfFromGoogleViewer',
        'isLocalPdfUrl',
        'GOOGLE_MAPS_SANDBOX',
    ]
    as $marker
) {
    $ok =
        str_contains(
            $content,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'ERRO')
        . "] {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

if (class_exists('TrustedEmbedService')) {
    $pdf =
        'https://ieclbparobe.com.br/wp-content/uploads/2026/01/Calendario-2026.pdf';

    $viewer =
        'https://docs.google.com/gview?embedded=true&url='
        . rawurlencode(
            $pdf
        );

    $result =
        TrustedEmbedService::localPdfFromGoogleViewer(
            $viewer
        );

    $ok =
        $result === $pdf;

    echo '['
        . ($ok ? 'OK' : 'ERRO')
        . "] Calendario-2026.pdf\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 84) . "\n";

if ($errors === 0) {
    echo "RESULTADO: PDF Google Viewer corrigido automaticamente.\n";
    exit(0);
}

echo "RESULTADO: {$errors} problema(s).\n";
exit(1);
