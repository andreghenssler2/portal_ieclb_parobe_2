<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root =
    dirname(__DIR__);

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Notícias v1.1.8\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.8') {
    echo "[FALHA] Versão esperada: 1.1.8\n";
    $errors++;
}

if (!class_exists('NewsFeatureService')) {
    echo "[FALHA] NewsFeatureService não carregado.\n";
    $errors++;
} else {
    echo "[OK] NewsFeatureService carregado.\n";
}

try {
    $pdo =
        Database::connection();

    NewsFeatureService::ensureSchema($pdo);

    foreach (
        [
            'post_publicacao_extras',
            'post_galeria_midias',
            'post_anexos_midias',
        ]
        as $table
    ) {
        $stmt =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );

        $stmt->execute([$table]);

        $ok =
            (int)$stmt->fetchColumn() > 0;

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] Tabela {$table}\n";

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
        'PORTAL_NEWS_FEATURES_V118',
        'NewsFeatureService.php',
    ],
    'admin/noticias/form.php' => [
        'PORTAL_NEWS_EDITOR_FEATURES_V118',
        'NewsFeatureService::save',
        '_news_features.php',
        'preview.php?id=',
    ],
    'admin/noticias/_news_features.php' => [
        'publicado_ate',
        'destaque_inicio',
        'destaque_fim',
        'video_midia_id',
        'galeria_ids[]',
        'anexo_ids[]',
    ],
    'admin/noticias/preview.php' => [
        'Pré-visualização administrativa',
        'NewsFeatureService::renderAssets',
    ],
    'noticia.php' => [
        'PORTAL_NEWS_PUBLIC_FEATURES_V118',
        'post_publicacao_extras',
        'NewsFeatureService::renderAssets',
    ],
    'index.php' => [
        'PORTAL_NEWS_PERIODS_V118',
        'pfx.publicado_ate',
        'pfx.destaque_inicio',
        'pfx.destaque_fim',
    ],
    'app/Services/HomeService.php' => [
        'PORTAL_NEWS_EXPIRATION_HOME_V118',
        'post_publicacao_extras',
    ],
    'app/Services/SearchService.php' => [
        'PORTAL_NEWS_EXPIRATION_SEARCH_V118',
        'post_publicacao_extras',
    ],
    'app/Services/NewsEngagementService.php' => [
        'PORTAL_NEWS_EXPIRATION_RELATED_V118',
    ],
    'app/Services/NewsAnalyticsService.php' => [
        'PORTAL_NEWS_EXPIRATION_RANKING_V118',
    ],
];

foreach (
    $checks
    as $relative => $markers
) {
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

echo "RESULTADO: Notícias v1.1.8 aprovada.\n";
exit(0);
