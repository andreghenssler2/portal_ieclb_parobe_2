<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - validação final série 1.x\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;
$warnings = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

$seriesOk =
    version_compare($version, '1.0.0', '>=')
    && version_compare($version, '2.0.0', '<');

if ($seriesOk) {
    echo "[OK] APP_VERSION = {$version} (série 1.x)\n";
} else {
    echo "[FALHA] APP_VERSION fora da série 1.x; atual: {$version}\n";
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
        'tests/post-release-v101.php',
        'docs/RELEASE_v1.0.0.md',
        'docs/RELEASE_v1.0.1.md',
        'docs/DEPLOY_PRODUCAO_v1.0.0.md',
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

        echo "\nSaúde operacional consolidada:\n";
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
        echo "[FALHA] Central operacional: {$e->getMessage()}\n";
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
    echo "RESULTADO: {$errors} falha(s); série 1.x não aprovada.\n";
    exit(1);
}

if ($warnings > 0) {
    echo "RESULTADO: Portal {$version} aprovado com {$warnings} aviso(s) de ambiente/operação.\n";
    exit(0);
}

echo "RESULTADO: Portal {$version} aprovado sem avisos automáticos.\n";
exit(0);
