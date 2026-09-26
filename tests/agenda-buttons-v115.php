<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Agenda v1.1.5\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.5') {
    echo "[FALHA] Versão esperada: 1.1.5\n";
    $errors++;
}

$agendaFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'agenda.php';

$agenda =
    is_file($agendaFile)
        ? (file_get_contents($agendaFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_AGENDA_BUTTONS_V115',
        'portal-agenda-filter-actions',
        '>Filtrar</span>',
        '>Limpar</span>',
        'Mês anterior',
        'Próximo mês',
        'aria-hidden="true">‹</span>',
        'aria-hidden="true">›</span>',
    ]
    as $marker
) {
    $ok =
        str_contains(
            $agenda,
            $marker
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] agenda.php: {$marker}\n";

    if (!$ok) {
        $errors++;
    }
}

$headerFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'theme'
    . DIRECTORY_SEPARATOR
    . 'ieclb'
    . DIRECTORY_SEPARATOR
    . 'header.php';

$header =
    is_file($headerFile)
        ? (file_get_contents($headerFile) ?: '')
        : '';

$iconsOk =
    str_contains(
        $header,
        'bootstrap-icons@1.11.3'
    )
    && str_contains(
        $header,
        'PORTAL_PUBLIC_ICONS_V115'
    );

echo '['
    . ($iconsOk ? 'OK' : 'FALHA')
    . "] Bootstrap Icons no tema público\n";

if (!$iconsOk) {
    $errors++;
}

echo str_repeat('=', 82) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: botões da Agenda v1.1.5 aprovados.\n";
exit(0);
