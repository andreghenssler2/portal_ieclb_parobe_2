<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste expiração da manutenção v1.1.3 R2\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

if (!class_exists('MaintenanceExpiryService')) {
    echo "[FALHA] MaintenanceExpiryService indisponível.\n";
    exit(1);
}

echo "[OK] MaintenanceExpiryService carregado.\n";

$tz =
    new DateTimeZone(
        date_default_timezone_get()
    );

$now =
    new DateTimeImmutable(
        '2026-09-23 19:30:00',
        $tz
    );

$cases = [
    [
        'label' => 'desativado',
        'enabled' => false,
        'end' => '2026-09-23 19:00:00',
        'expected' => false,
    ],
    [
        'label' => 'sem prazo',
        'enabled' => true,
        'end' => '',
        'expected' => false,
    ],
    [
        'label' => 'prazo futuro',
        'enabled' => true,
        'end' => '2026-09-23 20:00:00',
        'expected' => false,
    ],
    [
        'label' => 'prazo encerrado',
        'enabled' => true,
        'end' => '2026-09-23 19:29:00',
        'expected' => true,
    ],
    [
        'label' => 'prazo exatamente agora',
        'enabled' => true,
        'end' => '2026-09-23 19:30:00',
        'expected' => true,
    ],
];

foreach ($cases as $case) {
    $actual =
        MaintenanceExpiryService::isExpired(
            (bool)$case['enabled'],
            (string)$case['end'],
            $now
        );

    $ok =
        $actual
        === (bool)$case['expected'];

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . '] '
        . $case['label']
        . "\n";

    if (!$ok) {
        $errors++;
    }
}

$bootstrap =
    file_get_contents(
        $root
        . DIRECTORY_SEPARATOR
        . 'bootstrap.php'
    )
    ?: '';

foreach (
    [
        'MaintenanceExpiryService.php',
        'MaintenanceExpiryService::expireIfDue',
        'enforceMaintenanceMode($bootstrapPdo)',
    ]
    as $marker
) {
    if (str_contains($bootstrap, $marker)) {
        echo "[OK] bootstrap: {$marker}\n";
    } else {
        echo "[FALHA] bootstrap: {$marker}\n";
        $errors++;
    }
}

echo str_repeat('=', 82) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na expiração automática.\n";
    exit(1);
}

echo "RESULTADO: expiração automática do modo manutenção aprovada.\n";
exit(0);
