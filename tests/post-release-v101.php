<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste pós-release v1.0.1\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if ($version === '1.0.1') {
    echo "[OK] APP_VERSION = 1.0.1\n";
} else {
    echo "[FALHA] APP_VERSION esperado 1.0.1; atual: {$version}\n";
    $errors++;
}

$checks = [
    'tests/release-readiness.php' => [
        'checklist operacional série 1.x',
        'ambiente/operação',
    ],
    'tests/release-final.php' => [
        'validação final série 1.x',
        'série 1.x',
    ],
    'app/Services/ProductionReadinessService.php' => [
        'PORTAL_OPCACHE_CONTEXT_V101',
        "version_compare(\$version, '1.0.0', '>=')",
    ],
    'app/Services/PerformanceHealthService.php' => [
        'PORTAL_OPCACHE_DIAGNOSTIC_V101',
    ],
    'app/Services/InboundMailService.php' => [
        'PORTAL_IMAP_OPTIONAL_V101',
    ],
    'docs/RELEASE_v1.0.1.md' => [
        'v1.0.1',
    ],
];

foreach ($checks as $relative => $markers) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (!is_file($file)) {
        echo "[FALHA] Arquivo ausente: {$relative}\n";
        $errors++;
        continue;
    }

    $content = file_get_contents($file);

    if (!is_string($content)) {
        echo "[FALHA] Não foi possível ler: {$relative}\n";
        $errors++;
        continue;
    }

    $ok = true;

    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) {
            echo "[FALHA] {$relative}: marcador ausente: {$marker}\n";
            $errors++;
            $ok = false;
        }
    }

    if ($ok) {
        echo "[OK] {$relative}\n";
    }
}

if (class_exists('ProductionReadinessService')) {
    try {
        $pdo = Database::connection();

        $report =
            ProductionReadinessService::report(
                $pdo,
                $root
            );

        $blockers =
            count(
                (array)($report['blockers'] ?? [])
            );

        if ($blockers === 0) {
            echo "[OK] Central operacional sem bloqueadores.\n";
        } else {
            echo "[FALHA] Central operacional possui {$blockers} bloqueador(es).\n";
            $errors += $blockers;
        }

        if (
            PHP_SAPI === 'cli'
            && !function_exists('opcache_get_status')
        ) {
            echo "[INFO] OPcache não disponível no CLI; validação web continua sendo responsabilidade do servidor.\n";
        }
    } catch (Throwable $e) {
        echo "[FALHA] Central operacional: {$e->getMessage()}\n";
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) no pós-release v1.0.1.\n";
    exit(1);
}

echo "RESULTADO: pós-release v1.0.1 aprovado.\n";
exit(0);
