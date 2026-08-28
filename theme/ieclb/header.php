<?php
$themePdo = $pdo ?? Database::connection();
$siteSettings = siteConfigAll($themePdo);

$siteName = trim((string)($siteSettings['site_nome'] ?? '')) ?: 'Paróquia Evangélica de Confissão Luterana de Parobé';
$brandName = trim((string)($siteSettings['hero_titulo'] ?? '')) ?: 'IECLB Parobé';
$defaultTitle = trim((string)($siteSettings['seo_titulo'] ?? '')) ?: 'IECLB Parobé';
$defaultDescription = trim((string)($siteSettings['seo_descricao'] ?? '')) ?: (trim((string)($siteSettings['site_descricao'] ?? '')) ?: 'Portal da IECLB Parobé');
$defaultKeywords = trim((string)($siteSettings['seo_keywords'] ?? ''));

$resolvedTitle = trim((string)($metaTitle ?? '')) ?: $defaultTitle;
$resolvedDescription = trim((string)($metaDescription ?? '')) ?: $defaultDescription;
$resolvedKeywords = trim((string)($metaKeywords ?? '')) ?: $defaultKeywords;
$resolvedCanonical = trim((string)($canonicalUrl ?? '')) ?: currentCanonicalUrl();
$titleSeparator = trim((string)($siteSettings['seo_title_separator'] ?? '-')) ?: '-';
$appendSiteName = (string)($siteSettings['seo_append_site_name'] ?? '1') === '1';
if ($appendSiteName && $resolvedTitle !== $defaultTitle && mb_stripos($resolvedTitle, $defaultTitle) === false) {
    $resolvedTitle .= ' ' . $titleSeparator . ' ' . $defaultTitle;
}
$globalIndex = (string)($siteSettings['seo_robots_index'] ?? '1') === '1' && (string)($siteSettings['privacy_allow_search_engines'] ?? '1') === '1';
$globalFollow = (string)($siteSettings['seo_robots_follow'] ?? '1') === '1';
$resolvedNoindex = !$globalIndex || !empty($metaNoindex);
$robotsValue = ($resolvedNoindex ? 'noindex' : 'index') . ',' . ($globalFollow ? 'follow' : 'nofollow');
$hasSpecificMeta = trim((string)($metaTitle ?? '')) !== '' && trim((string)($metaTitle ?? '')) !== $defaultTitle;
$socialTitle = $hasSpecificMeta ? $resolvedTitle : (trim((string)($siteSettings['seo_social_title'] ?? '')) ?: $resolvedTitle);
$socialDescription = $hasSpecificMeta ? $resolvedDescription : (trim((string)($siteSettings['seo_social_description'] ?? '')) ?: $resolvedDescription);
$openGraphActive = (string)($siteSettings['seo_open_graph_ativo'] ?? '1') === '1';
$twitterCardActive = (string)($siteSettings['seo_twitter_card_ativo'] ?? '1') === '1';
$twitterSite = trim((string)($siteSettings['seo_twitter_site'] ?? ''));

$logoMedia = null;
$faviconMedia = null;
$ogMedia = null;
try {
    $logoId = (int)($siteSettings['site_logo_id'] ?? 0);
    $faviconId = (int)($siteSettings['site_favicon_id'] ?? 0);
    $ogId = (int)($siteSettings['seo_og_image_id'] ?? 0);
    if ($logoId > 0) $logoMedia = MediaService::find($themePdo, $logoId);
    if ($faviconId > 0) $faviconMedia = MediaService::find($themePdo, $faviconId);
    if ($ogId > 0) $ogMedia = MediaService::find($themePdo, $ogId);
} catch (Throwable $e) {
    // Mantém o tema funcionando mesmo se a mídia configurada tiver sido removida.
}

$resolvedImage = trim((string)($metaImage ?? ''));
$resolvedImageAlt = trim((string)($metaImageAlt ?? ''));
$resolvedImageWidth = max(0, (int)($metaImageWidth ?? 0));
$resolvedImageHeight = max(0, (int)($metaImageHeight ?? 0));
$resolvedImageType = trim((string)($metaImageType ?? ''));

if ($resolvedImage !== '' && !preg_match('#^https?://#i', $resolvedImage)) {
    $resolvedImage = mediaUrl($resolvedImage);
}

if ($resolvedImage === '' && $ogMedia) {
    $resolvedImage = mediaUrl((string)$ogMedia['caminho']);
    $resolvedImageAlt = trim((string)($ogMedia['alt_text'] ?? $ogMedia['titulo'] ?? $siteName));
    $resolvedImageWidth = max(0, (int)($ogMedia['largura'] ?? 0));
    $resolvedImageHeight = max(0, (int)($ogMedia['altura'] ?? 0));
    $resolvedImageType = trim((string)($ogMedia['mime_type'] ?? ''));
}

if ($resolvedImageAlt === '') {
    $resolvedImageAlt = $socialTitle;
}

$appearancePrimary = trim((string)($siteSettings['aparencia_cor_primaria'] ?? '#0b5d4b')) ?: '#0b5d4b';
$appearanceSecondary = trim((string)($siteSettings['aparencia_cor_secundaria'] ?? '#6c757d')) ?: '#6c757d';
$appearanceBg = trim((string)($siteSettings['aparencia_cor_fundo'] ?? '#ffffff')) ?: '#ffffff';
$appearanceText = trim((string)($siteSettings['aparencia_cor_texto'] ?? '#1f2937')) ?: '#1f2937';
$appearanceFooter = trim((string)($siteSettings['aparencia_cor_rodape'] ?? '#f8f9fa')) ?: '#f8f9fa';
$appearanceFooterText = trim((string)($siteSettings['aparencia_cor_rodape_texto'] ?? '#495057')) ?: '#495057';
$appearanceContainer = max(900, min(1600, (int)($siteSettings['aparencia_container_max'] ?? 1140)));
$appearanceRadius = max(0, min(40, (int)($siteSettings['aparencia_bordas_arredondadas'] ?? 16)));
$appearanceSticky = (string)($siteSettings['aparencia_cabecalho_sticky'] ?? '0') === '1';
$showBrandWithLogo = (string)($siteSettings['aparencia_mostrar_nome_com_logo'] ?? '0') === '1';
$activeThemeStyle = themeAssetUrl($themePdo, 'style.css');

$menuPrincipal = publicMenu($themePdo, 'principal');

// Compatibilidade durante a atualização: se o novo menu ainda não existir,
// mantém a navegação utilizada até a v0.5.0.
if (!$menuPrincipal) {
    $menuPaginas = [];
    try {
        $menuPaginas = $themePdo->query(
            "SELECT titulo, slug
             FROM paginas
             WHERE status = 'publicado'
               AND exibir_menu = 1
               AND (publicado_em IS NULL OR publicado_em <= NOW())
             ORDER BY ordem ASC, titulo ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        $menuPaginas = [];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($resolvedTitle) ?></title>
    <meta name="description" content="<?= e($resolvedDescription) ?>">
    <?php if ($resolvedKeywords !== ''): ?><meta name="keywords" content="<?= e($resolvedKeywords) ?>"><?php endif; ?>
    <link rel="canonical" href="<?= e($resolvedCanonical) ?>">
    <meta name="robots" content="<?= e($robotsValue) ?>">
    <?php if ((string)($siteSettings['seo_sitemap_ativo'] ?? '1') === '1'): ?><link rel="sitemap" type="application/xml" href="<?= e(url('sitemap.xml')) ?>"><?php endif; ?>
    <?php if ((string)($siteSettings['seo_feed_ativo'] ?? '1') === '1'): ?>
    <link rel="alternate" type="application/rss+xml" title="<?= e($siteName . ' - Notícias') ?>" href="<?= e(rssFeedUrl('posts')) ?>">
    <?php if ((string)($siteSettings['seo_feed_eventos'] ?? '1') === '1'): ?><link rel="alternate" type="application/rss+xml" title="<?= e($siteName . ' - Eventos e Cultos') ?>" href="<?= e(rssFeedUrl('eventos')) ?>"><?php endif; ?>
    <?php if (!empty($alternateFeedUrl)): ?><link rel="alternate" type="application/rss+xml" title="<?= e((string)($alternateFeedTitle ?? 'RSS')) ?>" href="<?= e((string)$alternateFeedUrl) ?>"><?php endif; ?>
    <?php endif; ?>

    <?php if ($openGraphActive): ?>
    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="<?= e((string)($metaOgType ?? 'website')) ?>">
    <meta property="og:title" content="<?= e($socialTitle) ?>">
    <meta property="og:description" content="<?= e($socialDescription) ?>">
    <meta property="og:url" content="<?= e($resolvedCanonical) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <?php if ($resolvedImage !== ''): ?>
    <meta property="og:image" content="<?= e($resolvedImage) ?>">
    <?php if (str_starts_with(strtolower($resolvedImage), 'https://')): ?><meta property="og:image:secure_url" content="<?= e($resolvedImage) ?>"><?php endif; ?>
    <?php if ($resolvedImageType !== ''): ?><meta property="og:image:type" content="<?= e($resolvedImageType) ?>"><?php endif; ?>
    <?php if ($resolvedImageWidth > 0): ?><meta property="og:image:width" content="<?= (int)$resolvedImageWidth ?>"><?php endif; ?>
    <?php if ($resolvedImageHeight > 0): ?><meta property="og:image:height" content="<?= (int)$resolvedImageHeight ?>"><?php endif; ?>
    <meta property="og:image:alt" content="<?= e($resolvedImageAlt) ?>">
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($twitterCardActive): ?>
    <meta name="twitter:card" content="<?= $resolvedImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($socialTitle) ?>">
    <meta name="twitter:description" content="<?= e($socialDescription) ?>">
    <?php if ($twitterSite !== ''): ?><meta name="twitter:site" content="<?= e(str_starts_with($twitterSite, '@') ? $twitterSite : '@' . $twitterSite) ?>"><?php endif; ?>
    <?php if ($resolvedImage !== ''): ?>
    <meta name="twitter:image" content="<?= e($resolvedImage) ?>">
    <meta name="twitter:image:alt" content="<?= e($resolvedImageAlt) ?>">
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($faviconMedia): ?><link rel="icon" href="<?= e(mediaUrl((string)$faviconMedia['caminho'])) ?>"><?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('public/css/site.css')) ?>">
    <?php if ($activeThemeStyle): ?><link rel="stylesheet" href="<?= e($activeThemeStyle) ?>"><?php endif; ?>
    <style>:root{--portal-primary:<?= e($appearancePrimary) ?>;--portal-secondary:<?= e($appearanceSecondary) ?>;--portal-bg:<?= e($appearanceBg) ?>;--portal-text:<?= e($appearanceText) ?>;--portal-footer-bg:<?= e($appearanceFooter) ?>;--portal-footer-text:<?= e($appearanceFooterText) ?>;--portal-container:<?= (int)$appearanceContainer ?>px;--portal-radius:<?= (int)$appearanceRadius ?>px}</style>
</head>
<body>
<header class="border-bottom bg-white portal-header <?= $appearanceSticky ? 'sticky-top shadow-sm' : '' ?>">
    <nav class="navbar navbar-expand-lg container py-3">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= e(url()) ?>">
            <?php if ($logoMedia): ?>
                <img src="<?= e(mediaUrl((string)$logoMedia['caminho'])) ?>" class="site-logo" alt="<?= e($brandName) ?>">
                <?php if ($showBrandWithLogo): ?><span class="site-brand-name"><?= e($brandName) ?></span><?php endif; ?>
            <?php else: ?>
                <span><?= e($brandName) ?></span>
            <?php endif; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-controls="menu" aria-expanded="false" aria-label="Abrir menu"><span class="navbar-toggler-icon"></span></button>
        <div id="menu" class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto gap-lg-2 align-items-lg-center">
                <?php if ($menuPrincipal): ?>
                    <?php foreach ($menuPrincipal as $menuItem): ?>
                        <?php $children = $menuItem['children'] ?? []; $href = menuItemUrl($menuItem); $newTab = (int)($menuItem['nova_aba'] ?? 0) === 1; ?>
                        <?php if ($children): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="<?= e($href) ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false" <?= $newTab ? 'target="_blank" rel="noopener"' : '' ?>><?= e($menuItem['titulo']) ?></a>
                                <ul class="dropdown-menu">
                                    <?php foreach ($children as $child): $childNewTab = (int)($child['nova_aba'] ?? 0) === 1; ?>
                                        <li><a class="dropdown-item" href="<?= e(menuItemUrl($child)) ?>" <?= $childNewTab ? 'target="_blank" rel="noopener"' : '' ?>><?= e($child['titulo']) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="<?= e($href) ?>" <?= $newTab ? 'target="_blank" rel="noopener"' : '' ?>><?= e($menuItem['titulo']) ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url()) ?>">Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('agenda.php')) ?>">Agenda</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('comunidades.php')) ?>">Comunidades</a></li>
                    <?php foreach ($menuPaginas as $menuPagina): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= e(contentUrl('pagina', (string)$menuPagina['slug'])) ?>"><?= e($menuPagina['titulo']) ?></a></li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <li class="nav-item ms-lg-2"><form class="portal-search-form d-flex" action="<?= e(url('busca')) ?>" method="get" role="search"><div class="input-group input-group-sm"><input class="form-control" type="search" name="q" placeholder="Buscar..." aria-label="Buscar" minlength="2" required><button class="btn btn-outline-secondary" type="submit" aria-label="Buscar"><span aria-hidden="true">⌕</span></button></div></form></li>
                <li class="nav-item"><a class="nav-link admin-link" href="<?= e(url('admin/login.php')) ?>">Área administrativa</a></li>
            </ul>
        </div>
    </nav>
</header>
<main>
