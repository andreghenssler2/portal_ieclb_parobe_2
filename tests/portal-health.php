<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Saúde do Portal v1.1.0\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

if (!class_exists('PortalHealthSnapshotService')) {
    echo "[FALHA] PortalHealthSnapshotService indisponível.\n";
    exit(1);
}

echo "[OK] PortalHealthSnapshotService carregado.\n";

if (
    version_compare(
        defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0',
        '1.1.0',
        '>='
    )
) {
    echo "[OK] APP_VERSION compatível.\n";
} else {
    echo "[FALHA] APP_VERSION inferior a 1.1.0.\n";
    $errors++;
}

try {
    $pdo =
        Database::connection();

    $current =
        PortalHealthSnapshotService::current(
            $pdo,
            $root
        );

    foreach (
        [
            'state',
            'score',
            'passed',
            'checks',
            'warnings',
            'blockers',
            'sections',
        ]
        as $key
    ) {
        if (!array_key_exists($key, $current)) {
            echo "[FALHA] Chave ausente no diagnóstico: {$key}\n";
            $errors++;
        }
    }

    if (
        (int)($current['checks'] ?? 0)
        > 0
    ) {
        echo "[OK] Diagnóstico operacional retornou verificações.\n";
    } else {
        echo "[FALHA] Diagnóstico operacional sem verificações.\n";
        $errors++;
    }

    $history =
        PortalHealthSnapshotService::history(
            $root,
            5
        );

    if (is_array($history)) {
        echo "[OK] Histórico pode ser consultado.\n";
    } else {
        echo "[FALHA] Histórico inválido.\n";
        $errors++;
    }

    $trend =
        PortalHealthSnapshotService::trend(
            $history
        );

    if (
        isset(
            $trend['direction'],
            $trend['delta'],
            $trend['label']
        )
    ) {
        echo "[OK] Tendência operacional disponível.\n";
    } else {
        echo "[FALHA] Tendência operacional inválida.\n";
        $errors++;
    }

    echo '[INFO] Estado atual: '
        . strtoupper((string)$current['state'])
        . '; '
        . (int)$current['score']
        . "%; "
        . count((array)$current['warnings'])
        . ' aviso(s); '
        . count((array)$current['blockers'])
        . " bloqueador(es).\n";
} catch (Throwable $e) {
    echo "[FALHA] {$e->getMessage()}\n";
    $errors++;
}

$page =
    $root
    . DIRECTORY_SEPARATOR
    . 'admin'
    . DIRECTORY_SEPARATOR
    . 'ferramentas'
    . DIRECTORY_SEPARATOR
    . 'saude-portal.php';

if (is_file($page)) {
    $content =
        file_get_contents($page)
        ?: '';

    if (
        str_contains(
            $content,
            "Auth::requirePermission('configuracoes.gerenciar')"
        )
        && str_contains(
            $content,
            'Csrf::validate'
        )
    ) {
        echo "[OK] Página administrativa protegida e ação POST com CSRF.\n";
    } else {
        echo "[FALHA] Proteção da página administrativa incompleta.\n";
        $errors++;
    }
} else {
    echo "[FALHA] Página admin/ferramentas/saude-portal.php ausente.\n";
    $errors++;
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na Saúde do Portal.\n";
    exit(1);
}

echo "RESULTADO: módulo Saúde do Portal aprovado.\n";
exit(0);
