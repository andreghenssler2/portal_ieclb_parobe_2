<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Saúde unificada v1.2.0 R5\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version = defined('APP_VERSION')
    ? (string)APP_VERSION
    : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.2.0') {
    echo "[FALHA] APP_VERSION deve permanecer 1.2.0.\n";
    $errors++;
}

$checks = [
    'admin/_header.php' => [
        'PORTAL_HEALTH_MENU_UNIFIED_V120_R5',
        'saude-central.php',
    ],
    'admin/ferramentas/saude-central.php' => [
        'PORTAL_HEALTH_UNIFIED_V120_R5',
        'SiteHealthService',
        'ProductionDiagnosticsService',
        'PortalHealthSnapshotService',
        'Diagnosticar SMTP',
        'Histórico de saúde',
    ],
    'admin/ferramentas/saude.php' => [
        'PORTAL_HEALTH_REDIRECT_V120_R5',
        'saude-central.php',
    ],
    'admin/ferramentas/saude-portal.php' => [
        'PORTAL_HEALTH_REDIRECT_V120_R5',
        'saude-central.php',
    ],
    'admin/ferramentas/diagnostico.php' => [
        'PORTAL_HEALTH_REDIRECT_V120_R5',
        'saude-central.php',
    ],
];

foreach ($checks as $relative => $markers) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $content =
        is_file($file)
            ? (file_get_contents($file) ?: '')
            : '';

    foreach ($markers as $marker) {
        $ok = str_contains($content, $marker);

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] {$relative}: {$marker}\n";

        if (!$ok) {
            $errors++;
        }
    }
}

$header =
    file_get_contents(
        $root
        . DIRECTORY_SEPARATOR
        . 'admin'
        . DIRECTORY_SEPARATOR
        . '_header.php'
    )
    ?: '';

$menuCount =
    substr_count(
        $header,
        '>Saúde do Portal</a>'
    );

echo '[INFO] Links "Saúde do Portal" no _header.php: '
    . $menuCount
    . "\n";

if ($menuCount !== 1) {
    echo "[FALHA] O menu deve possuir exatamente um link Saúde do Portal.\n";
    $errors++;
}

if (
    str_contains($header, '>Central de Diagnóstico</a>')
) {
    echo "[FALHA] Central de Diagnóstico ainda aparece separada no menu.\n";
    $errors++;
} else {
    echo "[OK] Central de Diagnóstico removida do menu.\n";
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Saúde unificada v1.2.0 R5 aprovada.\n";
exit(0);
