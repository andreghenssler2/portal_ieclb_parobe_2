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

if (!class_exists('PerformanceHealthService')) {
    fwrite(
        STDERR,
        "[FALHA] PerformanceHealthService indisponível.\n"
    );

    exit(1);
}

$report =
    PerformanceHealthService::report(
        $pdo
    );

$benchmark =
    PerformanceHealthService::benchmark(
        $pdo,
        10
    );

echo "Portal IECLB Parobé - teste de desempenho\n";
echo str_repeat('=', 72) . "\n";

echo '[INFO] PHP: '
    . (string)$report['php']['version']
    . "\n";

echo '[INFO] OPcache: '
    . (
        $report['php']['opcache_enabled']
            ? 'ativo'
            : 'inativo'
    )
    . "\n";

echo '[INFO] Cache: '
    . (
        $report['cache']['enabled']
            ? 'ativo'
            : 'inativo'
    )
    . "\n";

echo '[INFO] Arquivos de cache: '
    . (int)$report['cache']['stats']['files']
    . "\n";

echo '[INFO] Expirados: '
    . (int)$report['cache']['stats']['expired']
    . "\n";

echo '[INFO] Banco SELECT 1 médio: '
    . (string)$benchmark['database_ms']['average']
    . " ms\n";

echo '[INFO] Banco SELECT 1 p95: '
    . (string)$benchmark['database_ms']['p95']
    . " ms\n";

if (
    $benchmark['cache']['write_ms']
    !== null
) {
    echo '[INFO] Cache escrita: '
        . (string)$benchmark['cache']['write_ms']
        . " ms\n";

    echo '[INFO] Cache leitura: '
        . (string)$benchmark['cache']['read_ms']
        . " ms\n";
}

foreach ($report['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ($report['errors'] as $error) {
    echo "[FALHA] {$error}\n";
}

echo str_repeat('=', 72) . "\n";

if ($report['errors']) {
    echo "RESULTADO: falha estrutural de desempenho encontrada.\n";
    exit(1);
}

if (
    $report['cache']['enabled']
    && empty(
        $benchmark['cache']['verified']
    )
) {
    echo "RESULTADO: cache ativo, mas benchmark de integridade falhou.\n";
    exit(1);
}

echo "RESULTADO: diagnóstico de desempenho concluído.\n";
exit(0);
