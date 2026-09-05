<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');

PageHierarchyService::ensureSchema($pdo);
ContentBlockService::ensureSchema($pdo);

$pagina = PageHierarchyService::findPublishedByRequest($pdo);

if (!$pagina) {
    http_response_code(404);
    $metaTitle = 'Página não encontrada - ' . $siteLabel;
    $metaDescription = 'O conteúdo solicitado não está disponível.';
    $metaNoindex = true;
    require themeFile($pdo, 'header.php');
    echo '<div class="container py-5"><h1 class="h2">Página não encontrada</h1><p class="text-secondary">O conteúdo solicitado não está disponível.</p><a class="btn btn-primary" href="' . e(url()) . '">Voltar ao início</a></div>';
    require themeFile($pdo, 'footer.php');
    exit;
}

redirectCanonicalContent('pagina', (string)$pagina['slug']);
/*
 * v0.88.0 - cache individual da Página.
 */
if (
    class_exists('ContentPageCacheService')
) {
    ContentPageCacheService::begin(
        $pdo,
        'pagina',
        (int)$pagina['id'],
        (string)(
            $pagina['updated_at']
            ?? $pagina['created_at']
            ?? ''
        ),
        (string)(
            $pagina['conteudo']
            ?? ''
        )
    );
}

$pageBlocks = ContentBlockService::load(
    $pdo,
    'pagina',
    (int)$pagina['id']
);
$pageBlockText = ContentBlockService::plainText($pageBlocks, 300);
$pageAncestors = PageHierarchyService::ancestors($pdo, (int)$pagina['id']);
$pageChildren = PageHierarchyService::publishedChildren($pdo, (int)$pagina['id']);

$cover = $pagina['imagem_capa_midia'] ?? null;
$metaTitle = trim((string)($pagina['seo_titulo'] ?? '')) ?: $pagina['titulo'];
$paginaTextoMeta = trim(strip_tags(mb_substr((string)$pagina['conteudo'], 0, 160)));
$metaDescription = trim((string)($pagina['seo_descricao'] ?? ''))
    ?: ($pagina['resumo'] ?: ($paginaTextoMeta !== '' ? $paginaTextoMeta : $pageBlockText));
$metaNoindex = (int)($pagina['seo_noindex'] ?? 0) === 1;
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$metaImageAlt = trim((string)($pagina['imagem_capa_alt'] ?? '')) ?: (string)$pagina['titulo'];
$metaImageWidth = (int)($pagina['imagem_capa_largura'] ?? 0);
$metaImageHeight = (int)($pagina['imagem_capa_altura'] ?? 0);
$metaImageType = trim((string)($pagina['imagem_capa_mime'] ?? ''));$metaOgType = 'article';
$canonicalUrl = contentUrl('pagina', (string)$pagina['slug']);
$showPageCoverGlobal =
    siteConfig(
        $pdo,
        'media_page_cover_show',
        '0'
    ) === '1';

$pageContentPublic =
    (string)(
        $pagina['conteudo']
        ?? ''
    );

$pageSummaryPublic =
    trim(
        html_entity_decode(
            strip_tags(
                (string)(
                    $pagina['resumo']
                    ?? ''
                )
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );

$normalizePageText =
    static function (
        string $value
    ): string {
        $value =
            html_entity_decode(
                strip_tags($value),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

        $value =
            str_replace(
                "\u{00A0}",
                ' ',
                $value
            );

        return
            preg_replace(
                '/\s+/u',
                ' ',
                trim($value)
            )
            ?? trim($value);
    };

if (
    $pageSummaryPublic !== ''
    && preg_match(
        '~^\s*(<p\b[^>]*>[\s\S]*?</p>)~iu',
        $pageContentPublic,
        $firstParagraphMatch
    ) === 1
) {
    $firstParagraphHtml =
        (string)$firstParagraphMatch[1];

    if (
        $normalizePageText(
            $firstParagraphHtml
        )
        === $normalizePageText(
            $pageSummaryPublic
        )
    ) {
        $pageContentPublic =
            substr(
                $pageContentPublic,
                strlen(
                    (string)$firstParagraphMatch[0]
                )
            );
    }
}

require themeFile($pdo, 'header.php');
?>
<article class="container py-5 content-reading">
    <?php if ($pageAncestors): ?>
        <nav aria-label="Navegação da página" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="<?= e(url()) ?>">Início</a></li>
                <?php foreach ($pageAncestors as $ancestor): ?>
                    <li class="breadcrumb-item">
                        <a href="<?= e(contentUrl('pagina', (string)$ancestor['slug'])) ?>">
                            <?= e((string)$ancestor['titulo']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e((string)$pagina['titulo']) ?></li>
            </ol>
        </nav>
    <?php endif; ?>

    <header class="mb-4">
        <h1 class="display-5 fw-bold mb-3"><?= e($pagina['titulo']) ?></h1>
        </header>

    <?php if ($cover): ?>
<?php if ($cover && $showPageCoverGlobal): ?>
<img
    class="article-cover mb-4"
    src="<?= e(mediaUrl((string)$cover)) ?>"
    alt="<?= e((string)($pagina['imagem_capa_alt'] ?: $pagina['titulo'])) ?>"
>
<?php endif; ?>
    <?php endif; ?>

    <?php if (trim($pageContentPublic) !== ''): ?>
        <div class="article-body"><?= $pageContentPublic ?></div>
    <?php endif; ?>

    <?= ContentBlockService::render($pdo, 'pagina', (int)$pagina['id']) ?>

    <?php if ($pageChildren): ?>
        <section class="mt-5 pt-4 border-top">
            <h2 class="h4 mb-3">Nesta seção</h2>
            <div class="list-group">
                <?php foreach ($pageChildren as $child): ?>
                    <a
                        class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
                        href="<?= e(contentUrl('pagina', (string)$child['slug'])) ?>"
                    >
                        <span>
                            <strong><?= e((string)$child['titulo']) ?></strong>
                            <?php if (!empty($child['resumo'])): ?>
                                <small class="d-block text-secondary mt-1"><?= e((string)$child['resumo']) ?></small>
                            <?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</article>
<?php require themeFile($pdo, 'footer.php'); ?>
