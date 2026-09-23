<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - validação consolidada v1.1.3 R1\n";
echo str_repeat('=', 82) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if ($version === '1.1.3') {
    echo "[OK] APP_VERSION = 1.1.3\n";
} else {
    echo "[FALHA] APP_VERSION = {$version}\n";
    $errors++;
}

$checks = [
    'app/Services/HomeService.php' =>
        'PORTAL_PUBLIC_SCHEDULE_VISIBILITY_V112',
    'admin/noticias/form.php' =>
        'PORTAL_MODSEC_EDITOR_TRANSPORT_V112_R7',
    'app/Services/EditorialWorkflowService.php' =>
        'PORTAL_ADMIN_WORKFLOW_OVERRIDE_V112_R1',
    'admin/eventos/index.php' =>
        'PORTAL_EVENT_SEARCH_PARAMS_V112_R3',
    'theme/ieclb/footer.php' =>
        'PORTAL_FOOTER_MENU_V112_R4',
    'app/Services/TrustedEmbedService.php' =>
        'removeBooleanAttribute',
    'pagina.php' =>
        'PORTAL_TRUSTED_EMBED_PAGE_V112_R5',
    'noticia.php' =>
        'PORTAL_TRUSTED_EMBED_POST_V112_R5',
    'evento.php' =>
        'PORTAL_TRUSTED_EMBED_EVENT_V112_R5',
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
        . ($ok ? 'OK' : 'FALHA')
        . "] {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

if (class_exists('TrustedEmbedService')) {
    $sample =
        '<iframe '
        . 'src="https://www.google.com/maps/embed?pb=teste" '
        . 'sandbox="" allowfullscreen></iframe>';

    $normalized =
        TrustedEmbedService::normalize(
            $sample
        );

    if (
        str_contains(
            $normalized,
            'allow="fullscreen"'
        )
        && preg_match(
            '~\sallowfullscreen(?:\s|=|>)~i',
            $normalized
        ) !== 1
    ) {
        echo "[OK] Google Maps sem conflito allow/allowfullscreen.\n";
    } else {
        echo "[FALHA] Google Maps ainda apresenta conflito de permissões.\n";
        $errors++;
    }
}

echo str_repeat('=', 82) . "\n";

if ($errors === 0) {
    echo "RESULTADO: v1.1.3 R1 consolidada e aprovada.\n";
    exit(0);
}

echo "RESULTADO: {$errors} falha(s).\n";
exit(1);
