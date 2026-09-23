<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - Diagnóstico v1.1.3 R2\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.3') {
    echo "[ERRO] Versão esperada: 1.1.3\n";
    $errors++;
}

$checks = [
    'app/Services/MaintenanceExpiryService.php' =>
        'PORTAL_MAINTENANCE_AUTO_EXPIRE_V113_R2',
    'bootstrap.php' =>
        'MaintenanceExpiryService::expireIfDue($bootstrapPdo);',
    'tests/maintenance-expiry-v113-r2.php' =>
        'teste expiração da manutenção v1.1.3 R2',
    'docs/RELEASE_v1.1.3_R2.md' =>
        'Expiração automática do modo manutenção',
];

foreach ($checks as $relative => $marker) {
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
    $pdo = Database::connection();

    $enabled =
        (string)siteConfig(
            $pdo,
            'maintenance_enabled',
            '0'
        ) === '1';

    $expectedEnd =
        trim(
            (string)siteConfig(
                $pdo,
                'maintenance_expected_end',
                ''
            )
        );

    echo '[INFO] Manutenção ativa: '
        . ($enabled ? 'sim' : 'não')
        . "\n";

    echo '[INFO] Previsão atual: '
        . ($expectedEnd !== '' ? $expectedEnd : 'sem prazo')
        . "\n";

    if (
        $enabled
        && MaintenanceExpiryService::isExpired(
            true,
            $expectedEnd
        )
    ) {
        echo "[ERRO] Prazo já venceu, mas manutenção continua ativa.\n";
        $errors++;
    } else {
        echo "[OK] Estado da manutenção coerente com o prazo.\n";
    }
} catch (Throwable $e) {
    echo "[ERRO] {$e->getMessage()}\n";
    $errors++;
}

echo str_repeat('=', 82) . "\n";

if ($errors === 0) {
    echo "RESULTADO: v1.1.3 R2 aplicada; expiração automática operacional.\n";
    exit(0);
}

echo "RESULTADO: {$errors} problema(s) encontrado(s).\n";
exit(1);
