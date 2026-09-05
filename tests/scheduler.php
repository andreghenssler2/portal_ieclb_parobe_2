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

if (!class_exists('CronHealthService')) {
    fwrite(STDERR, "[FALHA] CronHealthService indisponível.\n");
    exit(1);
}

$status = CronHealthService::status($pdo, $root);

echo CronHealthService::cliReport($pdo, $root);

$errors = 0;

if (!CronHealthService::isStructurallyReady($status)) {
    $errors++;
}

if (!is_file($root . DIRECTORY_SEPARATOR . 'cron.php')) {
    echo "[FALHA] cron.php não encontrado.\n";
    $errors++;
}

if (!class_exists('SchedulerService')) {
    echo "[FALHA] SchedulerService não carregado.\n";
    $errors++;
} else {
    $registry = SchedulerService::registry();

    if (!$registry) {
        echo "[FALHA] Registry de tarefas vazio.\n";
        $errors++;
    } else {
        echo "[OK] Registry: " . count($registry) . " tarefa(s) conhecida(s).\n";
    }
}

if ((string)$status['heartbeat']['state'] === 'never') {
    echo "[AVISO] Heartbeat ainda não registrado; isso não falha o teste estrutural.\n";
} elseif ((string)$status['heartbeat']['state'] === 'stale') {
    echo "[AVISO] Heartbeat antigo; revise o Cron Job da hospedagem.\n";
}

if ((int)$status['history']['stale_running'] > 0) {
    echo "[AVISO] Há execução(ões) marcadas como executando há mais de 60 minutos.\n";
}

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) estrutural(is).\n";
    exit(1);
}

echo "RESULTADO: estrutura do cron e do agendador disponível.\n";
exit(0);
