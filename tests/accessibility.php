<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root =
    dirname(
        __DIR__
    );

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

if (!class_exists('AccessibilityAuditService')) {
    fwrite(
        STDERR,
        "[FALHA] AccessibilityAuditService indisponível.\n"
    );

    exit(1);
}

$report =
    AccessibilityAuditService::report(
        $root
    );

echo "Portal IECLB Parobé - teste de acessibilidade v0.98.0\n";
echo str_repeat('=', 74) . "\n";

echo '[INFO] Verificações: '
    . (int)$report['summary']['checks']
    . "\n";

echo '[INFO] Aprovadas: '
    . (int)$report['summary']['passed']
    . "\n";

echo '[INFO] Arquivos públicos analisados: '
    . (int)$report['summary']['scanned_files']
    . "\n";

foreach ($report['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ($report['errors'] as $error) {
    echo "[FALHA] {$error}\n";
}

echo str_repeat('=', 74) . "\n";

if ($report['errors']) {
    echo "RESULTADO: integração de acessibilidade incompleta.\n";
    exit(1);
}

echo "RESULTADO: estrutura de acessibilidade v0.98.0 aprovada.\n";
exit(0);
