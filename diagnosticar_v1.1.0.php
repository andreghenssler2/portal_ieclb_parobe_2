<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - Diagnóstico v1.1.0\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.0') {
    echo "[ERRO] Versão esperada: 1.1.0\n";
    $errors++;
}

$files = [
    'app/Services/PortalHealthSnapshotService.php' =>
        'final class PortalHealthSnapshotService',
    'admin/ferramentas/saude-portal.php' =>
        "Auth::requirePermission('configuracoes.gerenciar')",
    'tests/portal-health.php' =>
        'teste Saúde do Portal v1.1.0',
    'docs/RELEASE_v1.1.0.md' =>
        'Saúde do Portal',
    'bootstrap.php' =>
        'PortalHealthSnapshotService.php',
    'admin/_header.php' =>
        'PORTAL_HEALTH_MENU_V110',
];

foreach ($files as $relative => $marker) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $content =
        is_file($file)
            ? (file_get_contents($file) ?: '')
            : '';

    $ok =
        $content !== ''
        && str_contains(
            $content,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'ERRO')
        . "] {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

if (class_exists('PortalHealthSnapshotService')) {
    try {
        $pdo =
            Database::connection();

        $current =
            PortalHealthSnapshotService::current(
                $pdo,
                $root
            );

        $history =
            PortalHealthSnapshotService::history(
                $root,
                10
            );

        echo "\nSaúde do Portal:\n";
        echo '  Estado: '
            . strtoupper((string)$current['state'])
            . "\n";
        echo '  Pontuação: '
            . (int)$current['score']
            . "%\n";
        echo '  Aprovadas: '
            . (int)$current['passed']
            . '/'
            . (int)$current['checks']
            . "\n";
        echo '  Avisos: '
            . count($current['warnings'])
            . "\n";
        echo '  Bloqueadores: '
            . count($current['blockers'])
            . "\n";
        echo '  Snapshots salvos: '
            . count($history)
            . " (últimos 10 consultados)\n";

        foreach ($current['blockers'] as $blocker) {
            echo "[ERRO] {$blocker}\n";
            $errors++;
        }
    } catch (Throwable $e) {
        echo "[ERRO] Saúde do Portal: {$e->getMessage()}\n";
        $errors++;
    }
} else {
    echo "[ERRO] PortalHealthSnapshotService indisponível.\n";
    $errors++;
}

echo "\n" . str_repeat('=', 82) . "\n";

if ($errors === 0) {
    echo "RESULTADO: v1.1.0 instalada e módulo Saúde do Portal operacional.\n";
    exit(0);
}

echo "RESULTADO: {$errors} problema(s) encontrado(s).\n";
exit(1);
