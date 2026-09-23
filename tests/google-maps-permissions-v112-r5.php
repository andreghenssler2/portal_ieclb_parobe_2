<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste permissões Google Maps v1.1.2 R5\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

if (!class_exists('TrustedEmbedService')) {
    echo "[FALHA] TrustedEmbedService indisponível.\n";
    exit(1);
}

echo "[OK] TrustedEmbedService carregado.\n";

$sample =
    '<iframe '
    . 'src="https://www.google.com/maps/embed?pb=teste" '
    . 'sandbox="" '
    . 'width="600" height="450"></iframe>';

$result =
    TrustedEmbedService::normalize(
        $sample
    );

foreach (
    [
        'allow-scripts',
        'allow-same-origin',
        'allow-forms',
        'allow-popups',
        'allow-popups-to-escape-sandbox',
        'allow="fullscreen"',
    ]
    as $marker
) {
    if (str_contains($result, $marker)) {
        echo "[OK] Permissão: {$marker}\n";
    } else {
        echo "[FALHA] Permissão ausente: {$marker}\n";
        $errors++;
    }
}

$untrusted =
    '<iframe src="https://example.org/embed" sandbox=""></iframe>';

if (
    TrustedEmbedService::normalize(
        $untrusted
    ) === $untrusted
) {
    echo "[OK] Iframe não confiável não é relaxado.\n";
} else {
    echo "[FALHA] Iframe externo não confiável foi alterado.\n";
    $errors++;
}

if (
    TrustedEmbedService::isTrustedGoogleMapsEmbed(
        'https://www.google.com/maps/embed?pb=1'
    )
    && !TrustedEmbedService::isTrustedGoogleMapsEmbed(
        'https://example.org/maps/embed'
    )
) {
    echo "[OK] Allowlist de Google Maps validada.\n";
} else {
    echo "[FALHA] Allowlist de Google Maps inválida.\n";
    $errors++;
}

$publicFiles = [
    'pagina.php' => 'TrustedEmbedService::normalize',
    'noticia.php' => 'TrustedEmbedService::normalize',
    'evento.php' => 'TrustedEmbedService::normalize',
    'bootstrap.php' => 'TrustedEmbedService.php',
];

foreach ($publicFiles as $relative => $marker) {
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

    if (str_contains($content, $marker)) {
        echo "[OK] {$relative}\n";
    } else {
        echo "[FALHA] Integração ausente: {$relative}\n";
        $errors++;
    }
}

try {
    $pdo =
        Database::connection();

    $checks = [
        [
            'table' => 'paginas',
            'column' => 'conteudo',
            'label' => 'Páginas',
        ],
        [
            'table' => 'posts',
            'column' => 'conteudo',
            'label' => 'Notícias',
        ],
        [
            'table' => 'eventos',
            'column' => 'descricao',
            'label' => 'Eventos',
        ],
    ];

    foreach ($checks as $check) {
        $sql =
            'SELECT COUNT(*) FROM `'
            . $check['table']
            . '` WHERE `'
            . $check['column']
            . "` LIKE '%google.com/maps/embed%'";

        try {
            $count =
                (int)$pdo
                    ->query($sql)
                    ->fetchColumn();

            echo '[INFO] '
                . $check['label']
                . ' com Google Maps: '
                . $count
                . "\n";
        } catch (Throwable $ignored) {
        }
    }
} catch (Throwable $e) {
    echo "[AVISO] Contagem de embeds: {$e->getMessage()}\n";
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na correção de permissões.\n";
    exit(1);
}

echo "RESULTADO: permissões de Google Maps aprovadas.\n";
exit(0);
