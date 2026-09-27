<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root =
    dirname(__DIR__);

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Backup v1.1.12\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.12') {
    echo "[FALHA] Versão esperada: 1.1.12\n";
    $errors++;
}

foreach (
    [
        'BackupService',
        'FullBackupService',
        'AutomaticBackupService',
        'BackupRestoreTestService',
        'BackupIntegrityService',
        'SchedulerService',
    ]
    as $class
) {
    $ok =
        class_exists(
            $class
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] classe {$class}\n";

    if (!$ok) {
        $errors++;
    }
}

try {
    $pdo =
        Database::connection();

    SchedulerService::ensureRegistry(
        $pdo
    );

    $stmt =
        $pdo->prepare(
            "SELECT
                slug,
                ativa,
                intervalo_minutos
             FROM tarefas_agendadas
             WHERE slug IN (
                'backup_banco_automatico',
                'backup_completo_automatico',
                'backup_integridade_automatico'
             )
             ORDER BY slug"
        );

    $stmt->execute();

    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        )
        ?: [];

    $found =
        array_column(
            $rows,
            null,
            'slug'
        );

    foreach (
        [
            'backup_banco_automatico',
            'backup_completo_automatico',
            'backup_integridade_automatico',
        ]
        as $slug
    ) {
        $ok =
            isset(
                $found[$slug]
            );

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] tarefa {$slug}\n";

        if (!$ok) {
            $errors++;
        }
    }

    $service =
        new BackupIntegrityService(
            $pdo,
            $root
        );

    $status =
        $service->status();

    echo '[INFO] Limite de antiguidade: '
        . (int)(
            $status['stale_hours']
            ?? 0
        )
        . "h\n";

    echo '[INFO] Backup banco encontrado: '
        . (
            !empty(
                $status['database']['exists']
            )
                ? 'sim'
                : 'não'
        )
        . "\n";

    echo '[INFO] Backup completo encontrado: '
        . (
            !empty(
                $status['full']['exists']
            )
                ? 'sim'
                : 'não'
        )
        . "\n";
} catch (Throwable $e) {
    echo '[FALHA] Banco/serviços: '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$checks = [
    'bootstrap.php' => [
        'PORTAL_BACKUP_INTEGRITY_V1112',
        'BackupIntegrityService.php',
    ],
    'app/Services/SchedulerService.php' => [
        'PORTAL_BACKUP_INTEGRITY_TASK_V1112',
        'PORTAL_BACKUP_INTEGRITY_HANDLER_V1112',
        'backup_integridade_automatico',
    ],
    'admin/ferramentas/backups.php' => [
        'PORTAL_BACKUP_CENTER_LINK_V1112',
        'Backup e Integridade',
    ],
    'admin/ferramentas/backup-integridade.php' => [
        'Backup e Integridade',
        'Agendamento automático',
        'Retenção e alerta',
        'Verificação de integridade',
        'backup-download.php',
    ],
    'app/Services/BackupIntegrityService.php' => [
        'runScheduled',
        'verifyDatabaseBackup',
        'verifyFullBackup',
        'integrity-last.json',
    ],
];

foreach (
    $checks
    as $relative => $markers
) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );

    $content =
        is_file($file)
            ? (
                file_get_contents(
                    $file
                )
                ?: ''
            )
            : '';

    foreach ($markers as $marker) {
        $ok =
            str_contains(
                $content,
                $marker
            );

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] {$relative}: {$marker}\n";

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

echo "RESULTADO: Backup v1.1.12 aprovado.\n";
exit(0);
