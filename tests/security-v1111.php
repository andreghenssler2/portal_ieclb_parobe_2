<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Segurança v1.1.11\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.11') {
    echo "[FALHA] Versão esperada: 1.1.11\n";
    $errors++;
}

$requiredClasses = [
    'SecurityCenterService',
    'SessionSecurityService',
    'TwoFactorService',
];

foreach ($requiredClasses as $class) {
    $ok =
        class_exists($class);

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

    $tables = [
        'login_tentativas',
        'user_sessions',
        'usuario_2fa_recovery_codes',
        'logs',
    ];

    foreach ($tables as $table) {
        $stmt =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );

        $stmt->execute([$table]);

        $ok =
            (int)$stmt->fetchColumn()
            > 0;

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] tabela {$table}\n";

        if (!$ok) {
            $errors++;
        }
    }

    foreach (
        [
            'totp_secret',
            'totp_enabled_at',
            'totp_last_used_step',
        ]
        as $column
    ) {
        $stmt =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=\'usuarios\'
                   AND column_name=?'
            );

        $stmt->execute([$column]);

        $ok =
            (int)$stmt->fetchColumn()
            > 0;

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] usuarios.{$column}\n";

        if (!$ok) {
            $errors++;
        }
    }
} catch (Throwable $e) {
    echo '[FALHA] Banco: '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$checks = [
    'bootstrap.php' => [
        'PORTAL_SECURITY_CENTER_V1111',
        'SecurityCenterService.php',
    ],
    'admin/seguranca.php' => [
        'Central de Segurança',
        'Histórico de login',
        'Bloqueios temporários',
        'Sessões abertas',
        'revoke_session',
        'Autenticação em dois fatores',
        'Auditoria de segurança',
    ],
    'admin/configuracoes/seguranca.php' => [
        'PORTAL_SECURITY_CENTER_LINK_V1111',
        'Central de Segurança',
    ],
    'app/Services/AdminOperationsDashboardService.php' => [
        'PORTAL_SECURITY_QUICK_LINK_V1111',
        'admin/seguranca.php',
    ],
    'app/Services/SecurityCenterService.php' => [
        'activeLockouts',
        'loginHistory',
        'twoFactorSummary',
        'securityAudit',
        'allActiveSessions',
    ],
    'mod/auth/Auth.php' => [
        'security_max_login_attempts',
        'security_lockout_minutes',
        'login_tentativas',
        'TwoFactorService::isEnabled',
        'SessionSecurityService::registerCurrent',
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

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Segurança v1.1.11 aprovada.\n";
exit(0);
