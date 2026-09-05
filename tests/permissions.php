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

if (!class_exists('PermissionAuditService')) {
    fwrite(STDERR, "[FALHA] PermissionAuditService indisponível.\n");
    exit(1);
}

$report = PermissionAuditService::report($pdo, $root);

echo "Portal IECLB Parobé - teste de perfis e permissões\n";
echo str_repeat('=', 72) . "\n\n";

echo '[INFO] Perfis: ' . (int)$report['summary']['profiles'] . "\n";
echo '[INFO] Permissões: ' . (int)$report['summary']['permissions'] . "\n";
echo '[INFO] Usuários ativos: ' . (int)$report['summary']['active_users'] . "\n";
echo '[INFO] Páginas administrativas auditadas: ' . (int)$report['summary']['routes'] . "\n";
echo '[INFO] Páginas sem proteção detectável: ' . (int)$report['summary']['unguarded_routes'] . "\n\n";

foreach ($report['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ($report['errors'] as $error) {
    echo "[FALHA] {$error}\n";
}

echo "\n" . str_repeat('=', 72) . "\n";

if ($report['errors']) {
    echo 'RESULTADO: ' . count($report['errors']) . " erro(s) de permissões encontrado(s).\n";
    exit(1);
}

echo "RESULTADO: integridade de perfis e permissões aprovada.\n";
exit(0);
