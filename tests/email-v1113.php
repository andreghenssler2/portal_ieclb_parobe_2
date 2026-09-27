<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste E-mail v1.1.13\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.13') {
    echo "[FALHA] Versão esperada: 1.1.13\n";
    $errors++;
}

foreach (
    [
        'MailService',
        'MailDnsHealthService',
        'MailRetryQueueService',
        'MailOperationsService',
        'SchedulerService',
    ]
    as $class
) {
    $ok = class_exists($class);
    echo '[' . ($ok ? 'OK' : 'FALHA') . "] classe {$class}\n";

    if (!$ok) {
        $errors++;
    }
}

try {
    $pdo = Database::connection();

    MailRetryQueueService::ensureSchema($pdo);
    SchedulerService::ensureRegistry($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name=?'
    );
    $stmt->execute(['email_fila_falhas']);

    $ok = (int)$stmt->fetchColumn() > 0;
    echo '[' . ($ok ? 'OK' : 'FALHA') . "] tabela email_fila_falhas\n";

    if (!$ok) {
        $errors++;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM tarefas_agendadas
         WHERE slug='reenviar_emails_falhos'"
    );
    $stmt->execute();

    $ok = (int)$stmt->fetchColumn() > 0;
    echo '[' . ($ok ? 'OK' : 'FALHA') . "] tarefa reenviar_emails_falhos\n";

    if (!$ok) {
        $errors++;
    }

    $health = MailOperationsService::health($pdo);

    echo '[INFO] Transporte: ' . (string)$health['transport'] . "\n";
    echo '[INFO] DNS: '
        . (int)($health['dns']['score'] ?? 0)
        . '/'
        . (int)($health['dns']['max_score'] ?? 4)
        . "\n";
    echo '[INFO] Falhas 24h: ' . (int)$health['failed_24h'] . "\n";
} catch (Throwable $e) {
    echo '[FALHA] Banco/serviços: ' . $e->getMessage() . "\n";
    $errors++;
}

$checks = [
    'bootstrap.php' => [
        'PORTAL_MAIL_QUEUE_V1113',
        'MailRetryQueueService.php',
        'MailOperationsService.php',
    ],
    'app/Services/MailService.php' => [
        'PORTAL_MAIL_QUEUE_FAILURE_V1113',
        'MailRetryQueueService::enqueue',
        'queue_on_failure',
    ],
    'app/Services/SchedulerService.php' => [
        'PORTAL_MAIL_QUEUE_TASK_V1113',
        'PORTAL_MAIL_QUEUE_HANDLER_V1113',
        'reenviar_emails_falhos',
    ],
    'admin/configuracoes/email.php' => [
        'PORTAL_MAIL_HEALTH_LINK_V1113',
        'Saúde do E-mail',
        'Fila de falhas',
    ],
    'admin/configuracoes/email-saude.php' => [
        'Teste avançado de envio',
        'Histórico de falhas',
        'Autenticação do domínio',
    ],
    'admin/configuracoes/email-fila.php' => [
        'Fila de E-mails',
        'Processar agora',
        'mail_queue_max_attempts',
    ],
];

foreach ($checks as $relative => $markers) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = is_file($file) ? (file_get_contents($file) ?: '') : '';

    foreach ($markers as $marker) {
        $ok = str_contains($content, $marker);
        echo '[' . ($ok ? 'OK' : 'FALHA') . "] {$relative}: {$marker}\n";

        if (!$ok) {
            $errors++;
        }
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: E-mail v1.1.13 aprovado.\n";
exit(0);
