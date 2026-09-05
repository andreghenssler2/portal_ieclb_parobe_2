<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
$errors = [];
$warnings = [];

$required = [
    'public/css/mobile-v97.css' => [
        '@media (max-width: 991.98px)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '100dvh',
        'min-height: 44px',
        'prefers-reduced-motion',
    ],
    'public/css/admin-mobile-v97.css' => [
        '@media (max-width: 991.98px)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '--bs-offcanvas-width',
        'min-height: 44px',
        'prefers-reduced-motion',
    ],
    'theme/ieclb/header.php' => [
        'public/css/mobile-v97.css',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
    ],
    'admin/_header.php' => [
        'public/css/admin-mobile-v97.css',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
    ],
];

echo "Portal IECLB Parobé - teste de responsividade v0.97.0\n";
echo str_repeat('=', 74) . "\n";

foreach ($required as $relative => $markers) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (!is_file($file)) {
        $errors[] = "Arquivo ausente: {$relative}";
        continue;
    }

    $content = file_get_contents($file) ?: '';

    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) {
            $errors[] = "{$relative}: marcador ausente: {$marker}";
        }
    }
}

$publicHeader = file_get_contents($root . '/theme/ieclb/header.php') ?: '';
$adminHeader = file_get_contents($root . '/admin/_header.php') ?: '';

if (substr_count($publicHeader, 'public/css/mobile-v97.css') !== 1) {
    $errors[] = 'mobile-v97.css deve ser carregado exatamente uma vez no tema público.';
}

if (substr_count($adminHeader, 'public/css/admin-mobile-v97.css') !== 1) {
    $errors[] = 'admin-mobile-v97.css deve ser carregado exatamente uma vez no Admin.';
}

if (!str_contains($publicHeader, 'responsive-v51.css')) {
    $warnings[] = 'responsive-v51.css não foi localizado; a v0.97 foi projetada como refinamento dessa base.';
}

foreach ($warnings as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ($errors as $error) {
    echo "[FALHA] {$error}\n";
}

if (!$errors) {
    echo "[OK] CSS público mobile carregado.\n";
    echo "[OK] CSS administrativo mobile carregado.\n";
    echo "[OK] Viewport configurado no Portal e no Admin.\n";
    echo "[OK] Breakpoints 992/768/576 presentes.\n";
    echo "[OK] Alvos de toque e redução de movimento presentes.\n";
}

echo str_repeat('=', 74) . "\n";

if ($errors) {
    echo "RESULTADO: " . count($errors) . " falha(s) de responsividade.\n";
    exit(1);
}

echo "RESULTADO: camada mobile v0.97.0 integrada.\n";
exit(0);
