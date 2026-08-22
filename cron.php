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

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--task=')) {
        $task = trim(substr($arg, 7));
    } elseif ($arg === '--all') {
        $runAll = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Portal IECLB Parobé - Tarefas Agendadas\n\n";
        echo "Uso:\n";
        echo "  php cron.php                 Executa somente tarefas vencidas\n";
        echo "  php cron.php --task=SLUG     Executa uma tarefa específica agora\n";
        echo "  php cron.php --all           Executa todas as tarefas ativas agora\n";
        exit(0);
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
