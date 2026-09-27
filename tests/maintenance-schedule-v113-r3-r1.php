<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste manutenção agendada v1.1.3 R3 R1\n";
echo str_repeat('=', 84) . "\n";

$errors = 0;

if (!class_exists('MaintenanceExpiryService')) {
    echo "[FALHA] MaintenanceExpiryService indisponível.\n";
    exit(1);
}

$tz = new DateTimeZone(date_default_timezone_get());
$now = new DateTimeImmutable('2026-09-23 19:30:00', $tz);

$cases = [
    ['desativado', false, '', '', false, 'disabled'],
    ['agendado para o futuro', true, '2026-09-23 20:00:00', '2026-09-23 22:00:00', false, 'scheduled'],
    ['ativo no período', true, '2026-09-23 19:00:00', '2026-09-23 22:00:00', true, 'active'],
    ['encerrado', true, '2026-09-23 18:00:00', '2026-09-23 19:00:00', false, 'expired'],
];

foreach ($cases as [$label, $enabled, $start, $end, $expectedActive, $expectedState]) {
    $result = MaintenanceExpiryService::windowState(
        $enabled,
        $start,
        $end,
        $now
    );

    $ok =
        $result['active'] === $expectedActive
        && $result['state'] === $expectedState;

    echo '[' . ($ok ? 'OK' : 'FALHA') . '] ' . $label
        . ' => ' . $result['state'] . "\n";

    if (!$ok) {
        $errors++;
    }
}

$checks = [
    'app/Helpers/functions.php' => [
        'PORTAL_MAINTENANCE_SCHEDULE_V113_R3',
        'maintenance_start_at',
        'maintenance_end_at',
        "'configured_enabled'",
    ],
    'admin/ferramentas/manutencao.php' => [
        'PORTAL_MAINTENANCE_ADMIN_SCHEDULE_V113_R3',
        'Data/hora de início',
        'Data/hora final',
    ],
    'bootstrap.php' => [
        'MaintenanceExpiryService.php',
        'MaintenanceExpiryService::expireIfDue($bootstrapPdo);',
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

echo str_repeat('=', 84) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: manutenção com início/fim aprovada.\n";
exit(0);
