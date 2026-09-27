<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste SEO v1.1.14\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version = defined('APP_VERSION')
    ? (string)APP_VERSION
    : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.14') {
    echo "[FALHA] Versão esperada: 1.1.14\n";
    $errors++;
}

$ok = class_exists('SeoSharingService');

echo '[' . ($ok ? 'OK' : 'FALHA') . "] classe SeoSharingService\n";

if (!$ok) {
    $errors++;
}

try {
    $pdo = Database::connection();

    SeoSharingService::ensureSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name=?'
    );

    $stmt->execute(['seo_redirects']);

    $ok = (int)$stmt->fetchColumn() > 0;

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] tabela seo_redirects\n";

    if (!$ok) {
        $errors++;
    }

    $automatic = siteConfig(
        $pdo,
        'seo_auto_social_image',
        '1'
    );

    echo "[INFO] Imagem social automática: "
        . ($automatic === '1' ? 'ativa' : 'inativa')
        . "\n";
} catch (Throwable $e) {
    echo '[FALHA] Banco/SEO: '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$checks = [
    'bootstrap.php' => [
        'PORTAL_SEO_SHARING_V1114',
        'SeoSharingService.php',
        'maybeRedirectRequest',
    ],
    'theme/ieclb/header.php' => [
        'PORTAL_SEO_SOCIAL_AUTO_V1114',
        'PORTAL_STRUCTURED_DATA_V1114',
        'automaticSocialImageUrl',
        'application/ld+json',
    ],
    'sitemap.php' => [
        'PORTAL_SITEMAP_V1114',
        'post_publicacao_extras',
        'automaticSocialImageUrl',
    ],
    'admin/seo/social.php' => [
        'PORTAL_SEO_SOCIAL_ADMIN_V1114',
        'seo_auto_social_image',
        'Redirects antigos',
    ],
    'admin/seo/redirects.php' => [
        'Redirects de conteúdos antigos',
        'saveRedirect',
    ],
    'social-image.php' => [
        'social-auto-default-v1114.png',
        '1200',
        '630',
    ],
];

foreach ($checks as $relative => $markers) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $content = is_file($file)
        ? (file_get_contents($file) ?: '')
        : '';

    foreach ($markers as $marker) {
        $ok = str_contains($content, $marker);

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] {$relative}: {$marker}\n";

        if (!$ok) {
            $errors++;
        }
    }
}

$fallback =
    $root
    . DIRECTORY_SEPARATOR
    . 'public'
    . DIRECTORY_SEPARATOR
    . 'images'
    . DIRECTORY_SEPARATOR
    . 'social-auto-default-v1114.png';

$ok = is_file($fallback)
    && filesize($fallback) > 0;

echo '[' . ($ok ? 'OK' : 'FALHA') . "] imagem social padrão\n";

if (!$ok) {
    $errors++;
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: SEO v1.1.14 aprovado.\n";
exit(0);
