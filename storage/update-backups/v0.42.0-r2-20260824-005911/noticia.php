<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$slug = routeSlug('noticia');
$stmt = $pdo->prepare("SELECT p.*, c.nome AS comunidade_nome, u.nome AS autor_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt, m.largura AS imagem_capa_largura, m.altura AS imagem_capa_altura, m.mime_type AS imagem_capa_mime FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN usuarios u ON u.id=p.autor_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.slug=:slug AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) LIMIT 1");
$stmt->execute(['slug'=>$slug]);
$post=$stmt->fetch();
if (!$post) { http_response_code(404); $metaTitle='Notícia não encontrada'; require __DIR__.'/theme/ieclb/header.php'; echo '<div class="container py-5"><h1>Notícia não encontrada</h1></div>'; require themeFile($pdo, 'footer.php'); exit; }
NewsAnalyticsService::trackView($pdo, (int)$post['id']);
// v0.42.0-r1 - categorias com fallback legado.
$postCategories = [];
try {
    $categoryStmt = $pdo->prepare(
        "SELECT c.nome,c.slug
         FROM post_categorias pc
         INNER JOIN categorias c ON c.id=pc.categoria_id
         WHERE pc.post_id=:id
         ORDER BY pc.principal DESC,c.nome"
    );
    $categoryStmt->execute(['id'=>$post['id']]);
    $postCategories = $categoryStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    // Compatibilidade com base antiga: usa posts.categoria_id.
    if (!empty($post['categoria_id'])) {
        $categoryStmt = $pdo->prepare(
            "SELECT nome,slug FROM categorias WHERE id=:id LIMIT 1"
        );
        $categoryStmt->execute(['id'=>(int)$post['categoria_id']]);
        $legacyCategory = $categoryStmt->fetch();
        if ($legacyCategory) {
            $postCategories = [$legacyCategory];
        }
    }
}
$cover = $post['imagem_capa_midia'] ?: ($post['imagem_capa'] ?? '');
$metaTitle = trim((string)($post['seo_titulo'] ?? '')) ?: $post['titulo'];
$metaDescription = trim((string)($post['seo_descricao'] ?? '')) ?: ($post['resumo'] ?: trim(strip_tags(mb_substr((string)$post['conteudo'], 0, 160))));
$metaNoindex = (int)($post['seo_noindex'] ?? 0) === 1;
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$metaImageAlt = trim((string)($post['imagem_capa_alt'] ?? '')) ?: (string)$post['titulo'];
$metaImageWidth = (int)($post['imagem_capa_largura'] ?? 0);
$metaImageHeight = (int)($post['imagem_capa_altura'] ?? 0);
$metaImageType = trim((string)($post['imagem_capa_mime'] ?? ''));
$canonicalUrl = contentUrl('noticia', (string)$post['slug']);
$metaOgType = 'article';
$relatedPosts = NewsEngagementService::related($pdo, $post, 4);
require themeFile($pdo, 'header.php');
?>
<article class="container py-5 content-reading"><div class="mb-4"><div class="text-secondary mb-2"><?php if ($postCategories): ?><?php foreach ($postCategories as $i=>$cat): ?><?= $i ? ' · ' : '' ?><a class="text-reset text-decoration-none" href="<?= e(categoryUrl((string)$cat['slug'])) ?>"><?= e($cat['nome']) ?></a><?php endforeach; ?><?php else: ?>Notícia<?php endif; ?> · <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div><h1 class="display-5 fw-bold"><?= e($post['titulo']) ?></h1><div class="text-secondary">Publicado em <?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?><?php if ($post['autor_nome']): ?> · <?= e($post['autor_nome']) ?><?php endif; ?></div></div>
<?php if ($cover): ?><img class="article-cover mb-4" src="<?= e(mediaUrl($cover)) ?>" alt="<?= e($post['imagem_capa_alt'] ?: $post['titulo']) ?>"><?php endif; ?>
<?php if ($post['resumo']): ?><p class="lead"><?= e($post['resumo']) ?></p><?php endif; ?><div class="article-body"><?= $post['conteudo'] ?></div></article>
<?php if ($relatedPosts): ?>
<section class="container pb-5">
    <div class="border-top pt-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <h2 class="h3 fw-bold mb-0">Leia também</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('mais-lidas')) ?>">Ver mais lidas</a>
        </div>

        <div class="row g-4">
            <?php foreach ($relatedPosts as $related): ?>
                <div class="col-md-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm overflow-hidden">
                        <?php if (!empty($related['imagem_capa_midia'])): ?>
                            <a href="<?= e(contentUrl('noticia', (string)$related['slug'])) ?>">
                                <img
                                    src="<?= e(mediaUrl((string)$related['imagem_capa_midia'])) ?>"
                                    alt="<?= e((string)($related['imagem_capa_alt'] ?: $related['titulo'])) ?>"
                                    class="card-img-top"
                                    style="height:170px;object-fit:cover"
                                >
                            </a>
                        <?php endif; ?>

                        <div class="card-body">
                            <div class="small text-secondary mb-2">
                                <?= e($related['comunidade_nome'] ?: 'Paroquial') ?>
                                ·
                                <?= e(formatDateOnlyBr((string)($related['publicado_em'] ?: $related['created_at']))) ?>
                            </div>

                            <h3 class="h5 card-title">
                                <a
                                    class="text-reset text-decoration-none"
                                    href="<?= e(contentUrl('noticia', (string)$related['slug'])) ?>"
                                >
                                    <?= e($related['titulo']) ?>
                                </a>
                            </h3>

                            <?php if (!empty($related['resumo'])): ?>
                                <p class="card-text text-secondary small mb-0">
                                    <?= e(portalExcerpt((string)$related['resumo'], 130)) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require themeFile($pdo, 'footer.php'); ?>
