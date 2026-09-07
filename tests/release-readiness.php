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

$portalVersion =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : 'não identificada';

echo "Portal IECLB Parobé - checklist operacional série 1.x - Portal {$portalVersion}\n";
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
    echo "RESULTADO: existem bloqueadores operacionais para revisão.\n";
    exit(1);
}

if ($report['warnings']) {
    echo "RESULTADO: estrutura aprovada, com aviso(s) de ambiente/operação para revisão.\n";
    exit(0);
}

echo "RESULTADO: checklist operacional aprovado sem avisos.\n";
exit(0);
