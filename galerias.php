<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$galerias = $pdo->query(
    "SELECT g.id, g.titulo, g.slug, g.descricao, g.publicado_em,
            m.caminho AS capa_caminho, m.alt_text AS capa_alt,
            (SELECT COUNT(*) FROM galeria_midias gm WHERE gm.galeria_id=g.id) AS total_fotos
     FROM galerias g
     LEFT JOIN midias m ON m.id=g.imagem_capa_id
     WHERE g.status='publicado' AND (g.publicado_em IS NULL OR g.publicado_em <= NOW())
     ORDER BY COALESCE(g.publicado_em,g.created_at) DESC, g.id DESC"
)->fetchAll();

$metaTitle = 'Galerias - ' . (siteConfig($pdo, 'seo_titulo', 'IECLB Parobé'));
$metaDescription = 'Galerias de fotos da IECLB Parobé.';
require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="mb-4">
        <h1 class="display-6 fw-bold mb-2">Galerias</h1>
        <p class="text-secondary mb-0">Registros em fotos da vida comunitária da Paróquia.</p>
    </div>
    <div class="row g-4">
        <?php if (!$galerias): ?><div class="col-12"><div class="alert alert-light border">Ainda não há galerias publicadas.</div></div><?php endif; ?>
        <?php foreach ($galerias as $g): ?>
            <div class="col-md-6 col-lg-4">
                <article class="card h-100 border-0 shadow-sm overflow-hidden gallery-card">
                    <?php if ($g['capa_caminho']): ?>
                        <img src="<?= e(mediaUrl($g['capa_caminho'])) ?>" class="card-img-top gallery-card-cover" alt="<?= e($g['capa_alt'] ?: $g['titulo']) ?>">
                    <?php else: ?>
                        <div class="gallery-card-cover gallery-card-placeholder d-flex align-items-center justify-content-center">Galeria</div>
                    <?php endif; ?>
                    <div class="card-body p-4">
                        <div class="small text-secondary mb-2"><?= (int)$g['total_fotos'] ?> foto(s)</div>
                        <h2 class="h5"><a class="stretched-link text-decoration-none text-dark" href="<?= e(contentUrl('galeria', (string)$g['slug'])) ?>"><?= e($g['titulo']) ?></a></h2>
                        <?php if ($g['descricao']): ?><p class="text-secondary mb-0"><?= e(mb_strimwidth(strip_tags((string)$g['descricao']), 0, 150, '…')) ?></p><?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
