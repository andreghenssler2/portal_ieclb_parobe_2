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

echo "Portal IECLB Parobé - teste Comunidades v1.1.9 R3\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if (!class_exists('CommunityProfileService')) {
    echo "[FALHA] CommunityProfileService não carregado.\n";
    $errors++;
} else {
    echo "[OK] CommunityProfileService carregado.\n";
}

try {
    $pdo =
        Database::connection();

    CommunityProfileService::ensureSchema(
        $pdo
    );

    if ($pdo->inTransaction()) {
        echo "[FALHA] Já havia transação ativa antes do teste.\n";
        $errors++;
    } else {
        $pdo->beginTransaction();

        echo "[OK] Transação iniciada.\n";

        CommunityProfileService::ensureSchema(
            $pdo
        );

        if (!$pdo->inTransaction()) {
            echo "[FALHA] ensureSchema encerrou a transação.\n";
            $errors++;
        } else {
            echo "[OK] ensureSchema preservou a transação.\n";
        }

        /*
         * load() chama ensureSchema internamente e representa o uso normal
         * do serviço. O ID 0 evita leitura/alteração de dados reais.
         */
        CommunityProfileService::load(
            $pdo,
            0
        );

        if (!$pdo->inTransaction()) {
            echo "[FALHA] load() encerrou a transação.\n";
            $errors++;
        } else {
            echo "[OK] load() preservou a transação.\n";
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            echo "[OK] Rollback de teste executado.\n";
        }
    }
} catch (Throwable $e) {
    if (
        isset($pdo)
        && $pdo instanceof PDO
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    echo '[FALHA] '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$serviceFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'CommunityProfileService.php';

$content =
    is_file($serviceFile)
        ? (file_get_contents($serviceFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_COMMUNITY_SCHEMA_TX_GUARD_V119_R3',
        'private static array $schemaReady',
        '$pdo->inTransaction()',
        'public static function ensureSchema',
        'public static function save',
    ]
    as $marker
) {
    $ok =
        str_contains(
            $content,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] service: {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: correção v1.1.9 R3 aprovada.\n";
exit(0);
