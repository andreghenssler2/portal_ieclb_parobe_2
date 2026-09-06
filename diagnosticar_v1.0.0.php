<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - Diagnóstico v1.0.0\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;
$warnings = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.0.0') {
    echo "[ERRO] Versão esperada: 1.0.0\n";
    $errors++;
}

foreach (
    [
        'tests/release-final.php',
        'docs/RELEASE_v1.0.0.md',
        'docs/DEPLOY_PRODUCAO_v1.0.0.md',
        'docs/HISTORICO_v1.0.0.md',
    ]
    as $relative
) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $ok = is_file($file);

    echo '['
        . ($ok ? 'OK' : 'ERRO')
        . "] {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

if (class_exists('ProductionReadinessService')) {
    $pdo = Database::connection();

    $report =
        ProductionReadinessService::report(
            $pdo,
            $root
        );

    echo "\nPré-produção:\n";
    echo '  Estado: ' . strtoupper((string)$report['state']) . "\n";
    echo '  Pontuação: ' . (int)$report['score'] . "%\n";
    echo '  Aprovadas: '
        . (int)$report['passed']
        . '/'
        . (int)$report['checks']
        . "\n";
    echo '  Avisos: ' . count($report['warnings']) . "\n";
    echo '  Bloqueadores: ' . count($report['blockers']) . "\n";

    foreach ($report['warnings'] as $warning) {
        echo "[AVISO] {$warning}\n";
        $warnings++;
    }

    foreach ($report['blockers'] as $blocker) {
        echo "[ERRO] {$blocker}\n";
        $errors++;
    }
} else {
    echo "[ERRO] ProductionReadinessService indisponível.\n";
    $errors++;
}

echo "\n";
echo str_repeat('=', 82) . "\n";

if ($errors === 0) {
    if ($warnings > 0) {
        echo "RESULTADO: v1.0.0 instalada; {$warnings} aviso(s) de ambiente para revisão.\n";
    } else {
        echo "RESULTADO: v1.0.0 instalada e checklist automático sem avisos.\n";
    }

    exit(0);
}

echo "RESULTADO: {$errors} problema(s) encontrado(s).\n";
exit(1);
