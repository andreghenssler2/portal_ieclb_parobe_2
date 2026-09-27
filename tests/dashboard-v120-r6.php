<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Dashboard v1.2.0 R6\n";
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

$indexFile = $root . '/admin/index.php';
$opsFile = $root . '/admin/_dashboard_operations_v110.php';

$index = is_file($indexFile)
    ? (file_get_contents($indexFile) ?: '')
    : '';

$ops = is_file($opsFile)
    ? (file_get_contents($opsFile) ?: '')
    : '';

$checks = [
    [
        'ok' => str_contains($index, 'PORTAL_DASHBOARD_SUMMARY_DEDUP_V120_R6'),
        'label' => 'marcador de remoção da Visão geral',
    ],
    [
        'ok' => !str_contains($index, '<h2 class="h5 mb-1">' . "\n" . '                Visão geral'),
        'label' => 'Visão geral antiga removida',
    ],
    [
        'ok' => str_contains($index, 'PORTAL_DASHBOARD_RECENT_FULLWIDTH_V120_R6'),
        'label' => 'Conteúdo recente em largura total',
    ],
    [
        'ok' => str_contains($ops, 'PORTAL_DASHBOARD_EVENTS_FULLWIDTH_V120_R6'),
        'label' => 'Próximos eventos em largura total',
    ],
    [
        'ok' => str_contains($ops, "admin/ferramentas/saude-central.php"),
        'label' => 'atalho de saúde aponta para central unificada',
    ],
];

foreach ($checks as $check) {
    echo '[' . ($check['ok'] ? 'OK' : 'FALHA') . '] ' . $check['label'] . "\n";

    if (!$check['ok']) {
        $errors++;
    }
}

if (preg_match(
    '~PORTAL_DASHBOARD_EVENTS_FULLWIDTH_V120_R6.*?<div class="col-12">~s',
    $ops
)) {
    echo "[OK] Próximos eventos usa col-12.\n";
} else {
    echo "[FALHA] Próximos eventos não usa col-12.\n";
    $errors++;
}

if (preg_match(
    '~PORTAL_DASHBOARD_RECENT_FULLWIDTH_V120_R6.*?<div class="col-12">~s',
    $index
)) {
    echo "[OK] Conteúdo recente usa col-12.\n";
} else {
    echo "[FALHA] Conteúdo recente não usa col-12.\n";
    $errors++;
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Dashboard v1.2.0 R6 aprovado.\n";
exit(0);
