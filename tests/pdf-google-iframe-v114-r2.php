<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste PDF Google iframe v1.1.4 R2\n";
echo str_repeat('=', 84) . "\n";

$errors = 0;

if (!class_exists('TrustedEmbedService')) {
    echo "[FALHA] TrustedEmbedService indisponível.\n";
    exit(1);
}

$localPdf =
    'https://ieclbparobe.com.br/wp-content/uploads/2026/01/Calendario-2026.pdf';

$googleViewer =
    'https://docs.google.com/gview?embedded=true&url='
    . rawurlencode(
        $localPdf
    );

$resolved =
    TrustedEmbedService::localPdfFromGoogleViewer(
        $googleViewer
    );

if ($resolved === $localPdf) {
    echo "[OK] Google Viewer convertido para PDF local.\n";
} else {
    echo "[FALHA] Conversão do Google Viewer falhou.\n";
    $errors++;
}

$html =
    '<iframe '
    . 'src="'
    . htmlspecialchars(
        $googleViewer,
        ENT_QUOTES,
        'UTF-8'
    )
    . '" sandbox="" allow="fullscreen" allowfullscreen '
    . 'width="100%" height="800"></iframe>';

$normalized =
    TrustedEmbedService::normalize(
        $html
    );

foreach (
    [
        $localPdf,
        'title="Documento PDF"',
        'loading="lazy"',
    ]
    as $marker
) {
    $ok =
        str_contains(
            $normalized,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

foreach (
    [
        'docs.google.com',
        ' sandbox=',
        ' allowfullscreen',
    ]
    as $forbidden
) {
    $ok =
        !str_contains(
            strtolower($normalized),
            strtolower($forbidden)
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] removido: {$forbidden}\n";

    if (!$ok) {
        $errors++;
    }
}

$external =
    'https://docs.google.com/gview?url='
    . rawurlencode(
        'https://example.org/documento.pdf'
    );

if (
    TrustedEmbedService::localPdfFromGoogleViewer(
        $external
    ) === null
) {
    echo "[OK] PDF externo não é convertido automaticamente.\n";
} else {
    echo "[FALHA] PDF externo foi convertido indevidamente.\n";
    $errors++;
}

$maps =
    '<iframe src="https://www.google.com/maps/embed?pb=teste" sandbox=""></iframe>';

$mapsNormalized =
    TrustedEmbedService::normalize(
        $maps
    );

if (
    str_contains(
        $mapsNormalized,
        'allow-scripts'
    )
    && str_contains(
        $mapsNormalized,
        'allow-same-origin'
    )
) {
    echo "[OK] Correção de Google Maps preservada.\n";
} else {
    echo "[FALHA] Regressão na correção de Google Maps.\n";
    $errors++;
}

echo str_repeat('=', 84) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: PDF local sem Google Viewer aprovado.\n";
exit(0);
