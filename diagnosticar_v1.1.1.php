<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - Diagnóstico v1.1.1\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.1') {
    echo "[ERRO] Versão esperada: 1.1.1\n";
    $errors++;
}

$files = [
    'tests/portal-health-automation.php' =>
        'teste automação Saúde do Portal v1.1.1',
    'docs/RELEASE_v1.1.1.md' =>
        'Snapshots automáticos',
    'app/Services/SchedulerService.php' =>
        'PORTAL_HEALTH_SNAPSHOT_ALERT_V111',
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

try {
    $pdo =
        Database::connection();

    if (!class_exists('SchedulerService')) {
        throw new RuntimeException(
            'SchedulerService indisponível.'
        );
    }

    $tasks =
        SchedulerService::tasks(
            $pdo
        );

    $healthTask = null;

    foreach ($tasks as $task) {
        if (
            (string)($task['slug'] ?? '')
            === 'registrar_saude_portal'
        ) {
            $healthTask = $task;
            break;
        }
    }

    if (is_array($healthTask)) {
        echo "[OK] Tarefa registrar_saude_portal registrada.\n";
        echo '     Ativa: '
            . (!empty($healthTask['ativa']) ? 'sim' : 'não')
            . "\n";
        echo '     Intervalo: '
            . (int)($healthTask['intervalo_minutos'] ?? 0)
            . " minuto(s)\n";
        echo '     Próxima execução: '
            . (string)($healthTask['proxima_execucao_em'] ?? '')
            . "\n";
    } else {
        echo "[ERRO] Tarefa registrar_saude_portal ausente.\n";
        $errors++;
    }

    if (class_exists('PortalHealthSnapshotService')) {
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
        echo '  Avisos: '
            . count((array)$current['warnings'])
            . "\n";
        echo '  Bloqueadores: '
            . count((array)$current['blockers'])
            . "\n";
        echo '  Snapshots salvos: '
            . count($history)
            . " (últimos 10 consultados)\n";

        foreach ((array)$current['blockers'] as $blocker) {
            echo "[ERRO] {$blocker}\n";
            $errors++;
        }
    } else {
        echo "[ERRO] PortalHealthSnapshotService indisponível.\n";
        $errors++;
    }
} catch (Throwable $e) {
    echo "[ERRO] {$e->getMessage()}\n";
    $errors++;
}

echo "\n" . str_repeat('=', 82) . "\n";

if ($errors === 0) {
    echo "RESULTADO: v1.1.1 instalada e snapshots automáticos configurados.\n";
    exit(0);
}

echo "RESULTADO: {$errors} problema(s) encontrado(s).\n";
exit(1);
