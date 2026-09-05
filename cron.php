<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PORTAL_CRON_REQUEST', true);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();

$task = '';
$runAll = false;
$healthOnly = false;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--task=')) {
        $task = trim(substr($arg, 7));
    } elseif ($arg === '--all') {
        $runAll = true;
    } elseif ($arg === '--health') {
        $healthOnly = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Portal IECLB Parobé - Tarefas Agendadas\n\n";
        echo "Uso:\n";
        echo "  php cron.php                 Executa somente tarefas vencidas\n";
        echo "  php cron.php --task=SLUG     Executa uma tarefa específica agora\n";
        echo "  php cron.php --all           Executa todas as tarefas ativas agora\n";
        echo "  php cron.php --health        Mostra a saúde do cron sem executar tarefas\n";
        exit(0);
    }
}

/* PORTAL_CRON_HEALTH_V094 */
if ($healthOnly) {
    $health = CronHealthService::status($pdo, __DIR__);
    echo CronHealthService::cliReport($pdo, __DIR__);
    exit(CronHealthService::isStructurallyReady($health) ? 0 : 1);
}

if ($task === '' && !$runAll) {
    try {
        CronHealthService::recordHeartbeat(__DIR__);
    } catch (Throwable $heartbeatError) {
        fwrite(
            STDERR,
            '[AVISO] Heartbeat do cron: '
            . $heartbeatError->getMessage()
            . PHP_EOL
        );
    }
}

try {
    SchedulerService::ensureRegistry($pdo);

    if ($task !== '') {
        $result = SchedulerService::runOne($pdo, $task, 'cli');
        echo '[' . strtoupper((string)$result['status']) . '] ' . $result['name'] . ': ' . $result['message'] . PHP_EOL;
        exit(($result['status'] ?? '') === 'erro' ? 1 : 0);
    }

    if ($runAll) {
        $failed = false;
        foreach (SchedulerService::tasks($pdo) as $row) {
            if (empty($row['ativa'])) {
                continue;
            }
            $result = SchedulerService::runOne($pdo, (string)$row['slug'], 'cli');
            echo '[' . strtoupper((string)$result['status']) . '] ' . $result['name'] . ': ' . $result['message'] . PHP_EOL;
            if (($result['status'] ?? '') === 'erro') {
                $failed = true;
            }
        }
        exit($failed ? 1 : 0);
    }

    $run = SchedulerService::runDue($pdo, 'cron');
    if ($run['locked']) {
        echo '[INFO] ' . $run['message'] . PHP_EOL;
        exit(0);
    }

    echo '[INFO] ' . $run['message'] . PHP_EOL;
    foreach ($run['results'] as $result) {
        echo '[' . strtoupper((string)$result['status']) . '] ' . $result['name'] . ': ' . $result['message'] . PHP_EOL;
    }

    exit($run['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
