<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

$pdo = Database::connection();

if (!class_exists('ProductionReadinessService')) {
    fwrite(
        STDERR,
        "[FALHA] ProductionReadinessService indisponível.\n"
    );

    exit(1);
}

$report =
    ProductionReadinessService::report(
        $pdo,
        $root
    );

echo "Portal IECLB Parobé - checklist de pré-produção v0.99.0\n";
echo str_repeat('=', 78) . "\n";

echo '[INFO] Estado: '
    . strtoupper(
        (string)$report['state']
    )
    . "\n";

echo '[INFO] Pontuação: '
    . (int)$report['score']
    . "%\n";

echo '[INFO] Aprovadas: '
    . (int)$report['passed']
    . '/'
    . (int)$report['checks']
    . "\n";

echo '[INFO] Avisos: '
    . count($report['warnings'])
    . "\n";

echo '[INFO] Bloqueadores: '
    . count($report['blockers'])
    . "\n\n";

foreach ($report['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ($report['blockers'] as $blocker) {
    echo "[FALHA] {$blocker}\n";
}

echo str_repeat('=', 78) . "\n";

if ($report['blockers']) {
    echo "RESULTADO: existem bloqueadores para a entrada em produção.\n";
    exit(1);
}

if ($report['warnings']) {
    echo "RESULTADO: estrutura aprovada, com aviso(s) para revisão antes da v1.0.\n";
    exit(0);
}

echo "RESULTADO: checklist automático aprovado sem avisos.\n";
exit(0);
