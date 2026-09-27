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

echo "Portal IECLB Parobé - teste v1.1.10 R1\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$file =
    $root
    . DIRECTORY_SEPARATOR
    . 'admin'
    . DIRECTORY_SEPARATOR
    . 'index.php';

$content =
    is_file($file)
        ? (file_get_contents($file) ?: '')
        : '';

$oldHeadingCount =
    substr_count(
        $content,
        'Próximos eventos e cultos'
    );

$newIncludeCount =
    substr_count(
        $content,
        '_dashboard_operations_v110.php'
    );

echo "[INFO] Bloco antigo 'Próximos eventos e cultos': {$oldHeadingCount}\n";
echo "[INFO] Central Operacional v1.1.10: {$newIncludeCount}\n";

if ($oldHeadingCount !== 0) {
    echo "[FALHA] O bloco antigo de eventos ainda está presente.\n";
    $errors++;
} else {
    echo "[OK] Bloco antigo de eventos removido.\n";
}

if ($newIncludeCount !== 1) {
    echo "[FALHA] Central Operacional não foi encontrada exatamente uma vez.\n";
    $errors++;
} else {
    echo "[OK] Central Operacional preservada.\n";
}

if (
    !str_contains(
        $content,
        'PORTAL_ADMIN_EVENTS_DEDUP_V1110_R1'
    )
) {
    echo "[FALHA] Marcador R1 não encontrado.\n";
    $errors++;
} else {
    echo "[OK] Marcador R1 encontrado.\n";
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: v1.1.10 R1 aprovada.\n";
exit(0);
