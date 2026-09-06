<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste automação Saúde do Portal v1.1.1\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if (
    version_compare(
        $version,
        '1.1.1',
        '>='
    )
) {
    echo "[OK] APP_VERSION compatível: {$version}\n";
} else {
    echo "[FALHA] APP_VERSION inferior a 1.1.1: {$version}\n";
    $errors++;
}

if (!class_exists('PortalHealthSnapshotService')) {
    echo "[FALHA] PortalHealthSnapshotService indisponível.\n";
    $errors++;
} else {
    echo "[OK] PortalHealthSnapshotService disponível.\n";
}

if (!class_exists('SchedulerService')) {
    echo "[FALHA] SchedulerService indisponível.\n";
    $errors++;
} else {
    echo "[OK] SchedulerService disponível.\n";

    try {
        $pdo =
            Database::connection();

        $registry =
            SchedulerService::registry();

        $task =
            $registry['registrar_saude_portal']
            ?? null;

        if (!is_array($task)) {
            echo "[FALHA] Tarefa registrar_saude_portal ausente do registry.\n";
            $errors++;
        } else {
            echo "[OK] Tarefa registrar_saude_portal registrada no código.\n";

            if (
                (int)($task['interval'] ?? 0)
                === 1440
            ) {
                echo "[OK] Intervalo padrão: 1440 minutos (diário).\n";
            } else {
                echo "[FALHA] Intervalo padrão inesperado.\n";
                $errors++;
            }

            if (!empty($task['enabled'])) {
                echo "[OK] Tarefa habilitada por padrão.\n";
            } else {
                echo "[FALHA] Tarefa deveria estar habilitada por padrão.\n";
                $errors++;
            }
        }

        /*
         * tasks() chama ensureRegistry(), registrando a tarefa na tabela
         * existente sem criar nova estrutura de banco.
         */
        $rows =
            SchedulerService::tasks(
                $pdo
            );

        $dbTask = null;

        foreach ($rows as $row) {
            if (
                (string)($row['slug'] ?? '')
                === 'registrar_saude_portal'
            ) {
                $dbTask = $row;
                break;
            }
        }

        if (is_array($dbTask)) {
            echo "[OK] Tarefa presente em tarefas_agendadas.\n";
            echo '[INFO] Ativa: '
                . (!empty($dbTask['ativa']) ? 'sim' : 'não')
                . '; intervalo: '
                . (int)($dbTask['intervalo_minutos'] ?? 0)
                . " min.\n";
        } else {
            echo "[FALHA] Tarefa não foi registrada em tarefas_agendadas.\n";
            $errors++;
        }

        $history =
            PortalHealthSnapshotService::history(
                $root,
                2
            );

        echo '[INFO] Snapshots existentes: '
            . count($history)
            . " (até 2 consultados).\n";
    } catch (Throwable $e) {
        echo "[FALHA] {$e->getMessage()}\n";
        $errors++;
    }
}

$schedulerFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'SchedulerService.php';

$schedulerContent =
    is_file($schedulerFile)
        ? (file_get_contents($schedulerFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_HEALTH_SNAPSHOT_TASK_V111',
        'PORTAL_HEALTH_SNAPSHOT_HANDLER_V111',
        'PORTAL_HEALTH_SNAPSHOT_ALERT_V111',
        "'registrar_saude_portal'",
        'automaticPortalHealthSnapshot',
    ]
    as $marker
) {
    if (str_contains($schedulerContent, $marker)) {
        echo "[OK] Scheduler marker: {$marker}\n";
    } else {
        echo "[FALHA] Scheduler marker ausente: {$marker}\n";
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na automação da Saúde do Portal.\n";
    exit(1);
}

echo "RESULTADO: automação da Saúde do Portal aprovada.\n";
exit(0);
