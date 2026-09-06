<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - validação final v1.0.0\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;
$warnings = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if ($version === '1.0.0') {
    echo "[OK] APP_VERSION = 1.0.0\n";
} else {
    echo "[FALHA] APP_VERSION esperado 1.0.0; atual: {$version}\n";
    $errors++;
}

foreach (
    [
        'bootstrap.php',
        'admin/_header.php',
        'theme/ieclb/header.php',
        'theme/ieclb/footer.php',
        'tests/run.php',
        'tests/release-readiness.php',
        'tests/accessibility.php',
        'docs/RELEASE_v1.0.0.md',
        'docs/DEPLOY_PRODUCAO_v1.0.0.md',
        'docs/CHECKLIST_GO_LIVE_v0.99.md',
    ]
    as $relative
) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (is_file($file)) {
        echo "[OK] {$relative}\n";
    } else {
        echo "[FALHA] Arquivo ausente: {$relative}\n";
        $errors++;
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

        echo "\nPré-produção consolidada:\n";
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
            echo "[FALHA] {$blocker}\n";
            $errors++;
        }
    } catch (Throwable $e) {
        echo "[FALHA] Central de Pré-produção: {$e->getMessage()}\n";
        $errors++;
    }
} else {
    echo "[FALHA] ProductionReadinessService indisponível.\n";
    $errors++;
}

if (class_exists('AccessibilityAuditService')) {
    try {
        $access =
            AccessibilityAuditService::report(
                $root
            );

        if (
            empty($access['errors'])
            && empty($access['warnings'])
        ) {
            echo "[OK] Acessibilidade sem erros/avisos automáticos.\n";
        } else {
            foreach ((array)($access['errors'] ?? []) as $error) {
                echo "[FALHA] Acessibilidade: {$error}\n";
                $errors++;
            }

            foreach ((array)($access['warnings'] ?? []) as $warning) {
                echo "[AVISO] Acessibilidade: {$warning}\n";
                $warnings++;
            }
        }
    } catch (Throwable $e) {
        echo "[FALHA] Auditoria de acessibilidade: {$e->getMessage()}\n";
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s); release v1.0.0 não aprovada.\n";
    exit(1);
}

if ($warnings > 0) {
    echo "RESULTADO: v1.0.0 aprovada com {$warnings} aviso(s) de ambiente/produção.\n";
    exit(0);
}

echo "RESULTADO: v1.0.0 aprovada sem avisos automáticos.\n";
exit(0);
