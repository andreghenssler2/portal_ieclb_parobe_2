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

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'AdminOperationsDashboardService.php';

echo "Portal IECLB Parobé - teste Administração v1.1.10\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.10') {
    echo "[FALHA] Versão esperada: 1.1.10\n";
    $errors++;
}

if (!class_exists('AdminOperationsDashboardService')) {
    echo "[FALHA] AdminOperationsDashboardService não carregado.\n";
    $errors++;
} else {
    echo "[OK] AdminOperationsDashboardService carregado.\n";
}

$checks = [
    'admin/index.php' => [
        'PORTAL_ADMIN_OPERATIONS_V1110',
        'AdminOperationsDashboardService',
        '_dashboard_operations_v110.php',
    ],
    'admin/_dashboard_operations_v110.php' => [
        'Central operacional',
        'Últimos acessos administrativos',
        'Notícias aguardando publicação',
        'Espaço usado por mídias',
        'Atalhos rápidos da administração',
    ],
    'app/Services/AdminOperationsDashboardService.php' => [
        'operationalAlerts',
        'recentAccesses',
        'waitingNews',
        'mediaStats',
        'ProductionReadinessService::report',
        'CronHealthService::status',
        'BackupService',
        'MailDnsHealthService::report',
    ],
];

foreach ($checks as $relative => $markers) {
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
            ? (file_get_contents($file) ?: '')
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

try {
    $pdo = Database::connection();

    /*
     * O serviço exige Auth para filtrar módulos. O teste não força login;
     * valida apenas que as consultas básicas de infraestrutura existem.
     */
    $media =
        $pdo
            ->query(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(tamanho),0) AS bytes
                 FROM midias'
            )
            ->fetch(PDO::FETCH_ASSOC)
        ?: [];

    echo "[INFO] Mídias: "
        . (int)($media['total'] ?? 0)
        . " / "
        . formatBytes((int)($media['bytes'] ?? 0))
        . "\n";

    $futureEvents =
        (int)$pdo
            ->query(
                "SELECT COUNT(*)
                 FROM eventos
                 WHERE status='publicado'
                   AND data_inicio>=NOW()"
            )
            ->fetchColumn();

    echo "[INFO] Eventos futuros: {$futureEvents}\n";
} catch (Throwable $e) {
    echo '[FALHA] Banco: '
        . $e->getMessage()
        . "\n";

    $errors++;
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Administração v1.1.10 aprovada.\n";
exit(0);
