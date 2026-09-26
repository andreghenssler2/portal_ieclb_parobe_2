<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Agenda v1.1.7\n";
echo str_repeat('=', 84) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.7') {
    echo "[FALHA] Versão esperada: 1.1.7\n";
    $errors++;
}

if (!method_exists(EventCalendarService::class, 'googleCalendarUrl')) {
    echo "[FALHA] EventCalendarService::googleCalendarUrl ausente.\n";
    $errors++;
} else {
    $sample = [
        'id' => 1,
        'slug' => 'teste',
        'titulo' => 'Evento teste',
        'resumo' => 'Resumo',
        'descricao' => '',
        'local' => 'Igreja',
        'endereco' => 'Parobé',
        'data_inicio' => '2026-11-08 10:00:00',
        'data_fim' => '2026-11-08 11:00:00',
    ];

    $google =
        EventCalendarService::googleCalendarUrl(
            $sample
        );

    $ok =
        str_contains(
            $google,
            'calendar.google.com/calendar/render'
        )
        && str_contains(
            $google,
            'action=TEMPLATE'
        );

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] Google Calendar URL\n";

    if (!$ok) {
        $errors++;
    }
}

if (!method_exists(EventCalendarService::class, 'recurrenceOccurrences')) {
    echo "[FALHA] recurrenceOccurrences ausente.\n";
    $errors++;
} else {
    $weekly =
        EventCalendarService::recurrenceOccurrences(
            '2026-11-01 09:00:00',
            '2026-11-01 10:00:00',
            'semanal',
            3
        );

    $ok =
        count($weekly) === 3
        && ($weekly[1]['data_inicio'] ?? '') === '2026-11-08 09:00:00'
        && ($weekly[2]['data_inicio'] ?? '') === '2026-11-15 09:00:00';

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] Recorrência semanal\n";

    if (!$ok) {
        $errors++;
    }
}

$checks = [
    'agenda.php' => [
        'PORTAL_AGENDA_V117',
        'portal-calendar-day-mobile',
        'window.print()',
        'Google Agenda',
        'Legenda',
    ],
    'evento.php' => [
        'PORTAL_EVENT_GOOGLE_CALENDAR_V117',
        'googleCalendarUrl',
    ],
    'admin/eventos/index.php' => [
        'PORTAL_EVENT_DUPLICATE_V117',
        'duplicar.php',
        'Duplicar',
    ],
    'admin/eventos/form.php' => [
        'PORTAL_EVENT_RECURRENCE_V117',
        'repeticao',
        'repeticoes',
        'recurrenceOccurrences',
    ],
    'admin/eventos/duplicar.php' => [
        'agenda.duplicar',
        "'status' =>",
        "'rascunho'",
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

echo str_repeat('=', 84) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Agenda v1.1.7 aprovada.\n";
exit(0);
