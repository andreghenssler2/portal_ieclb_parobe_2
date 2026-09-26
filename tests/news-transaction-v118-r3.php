<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste transação Notícias v1.1.8 R3\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

try {
    $pdo = Database::connection();

    NewsFeatureService::ensureSchema($pdo);

    if ($pdo->inTransaction()) {
        echo "[FALHA] Já havia transação ativa antes do teste.\n";
        $errors++;
    } else {
        $pdo->beginTransaction();

        echo "[OK] Transação iniciada.\n";

        NewsFeatureService::ensureSchema($pdo);

        if (!$pdo->inTransaction()) {
            echo "[FALHA] ensureSchema encerrou a transação.\n";
            $errors++;
        } else {
            echo "[OK] ensureSchema preservou a transação ativa.\n";
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            echo "[OK] Rollback de teste executado.\n";
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo '[FALHA] '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$file =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'NewsFeatureService.php';

$content =
    is_file($file)
        ? (file_get_contents($file) ?: '')
        : '';

foreach (
    [
        'PORTAL_NEWS_SCHEMA_TX_GUARD_V118_R3',
        'private static array $schemaReady',
        '$pdo->inTransaction()',
    ]
    as $marker
) {
    $ok = str_contains($content, $marker);

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] marcador: {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: correção transacional v1.1.8 R3 aprovada.\n";
exit(0);
