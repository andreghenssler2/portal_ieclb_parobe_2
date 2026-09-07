<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste status agendado automático v1.1.2 R2\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if ($version === '1.1.2') {
    echo "[OK] APP_VERSION = 1.1.2\n";
} else {
    echo "[FALHA] APP_VERSION esperado: 1.1.2; atual: {$version}\n";
    $errors++;
}

$formFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'admin'
    . DIRECTORY_SEPARATOR
    . 'noticias'
    . DIRECTORY_SEPARATOR
    . 'form.php';

$content =
    is_file($formFile)
        ? (file_get_contents($formFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_AUTO_SCHEDULE_STATUS_V112_R2',
        'id="postStatus"',
        'id="postPublishAt"',
        '$requestedPublishAt',
        "$status = 'agendado';",
        'postPublishAt.addEventListener',
    ]
    as $marker
) {
    if (str_contains($content, $marker)) {
        echo "[OK] {$marker}\n";
    } else {
        echo "[FALHA] Marcador ausente: {$marker}\n";
        $errors++;
    }
}

if (
    str_contains(
        $content,
        'A data/hora do agendamento precisa estar no futuro.'
    )
) {
    echo "[OK] Validação de data futura presente.\n";
} else {
    echo "[FALHA] Validação de data futura ausente.\n";
    $errors++;
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na correção R2.\n";
    exit(1);
}

echo "RESULTADO: status Agendado automático configurado corretamente.\n";
exit(0);
