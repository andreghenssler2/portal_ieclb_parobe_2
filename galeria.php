<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$slug = routeSlug('galeria');

if ($slug === '') {
    http_response_code(404);
    exit('Galeria não encontrada.');
}

$stmt = $pdo->prepare(
    "SELECT g.*, m.caminho AS capa_caminho, m.alt_text AS capa_alt, m.largura AS capa_largura, m.altura AS capa_altura, m.mime_type AS capa_mime
     FROM galerias g
     LEFT JOIN midias m ON m.id=g.imagem_capa_id
     WHERE g.slug=:slug AND g.status='publicado' AND (g.publicado_em IS NULL OR g.publicado_em <= NOW())
     LIMIT 1"
);
$stmt->execute(['slug' => $slug]);
$galeria = $stmt->fetch();
if (!$galeria) {
    http_response_code(404);
    exit('Galeria não encontrada.');
}

redirectCanonicalContent('galeria', (string)$galeria['slug']);
$stmt = $pdo->prepare(
    "SELECT gm.legenda, gm.ordem, m.id, m.caminho, m.titulo, m.alt_text, m.nome_original, m.largura, m.altura
     FROM galeria_midias gm
     INNER JOIN midias m ON m.id=gm.midia_id
     WHERE gm.galeria_id=:id AND m.mime_type LIKE 'image/%'
     ORDER BY gm.ordem ASC, gm.id ASC"
);
$stmt->execute(['id' => $galeria['id']]);
$fotos = $stmt->fetchAll();

$metaTitle = trim((string)($galeria['seo_titulo'] ?? '')) ?: $galeria['titulo'];
$metaDescription = trim((string)($galeria['seo_descricao'] ?? '')) ?: (trim((string)$galeria['descricao']) ?: ('Galeria de fotos: ' . $galeria['titulo']));
$metaNoindex = (int)($galeria['seo_noindex'] ?? 0) === 1;
$canonicalUrl = contentUrl('galeria', (string)$galeria['slug']);
$metaImage = $galeria['capa_caminho'] ? mediaUrl((string)$galeria['capa_caminho']) : '';
$metaImageAlt = trim((string)($galeria['capa_alt'] ?? '')) ?: (string)$galeria['titulo'];
$metaImageWidth = (int)($galeria['capa_largura'] ?? 0);
$metaImageHeight = (int)($galeria['capa_altura'] ?? 0);
$metaImageType = trim((string)($galeria['capa_mime'] ?? ''));require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <nav class="small mb-3"><a href="<?= e(url('galerias.php')) ?>" class="text-decoration-none">Galerias</a> <span class="text-secondary">/</span> <span class="text-secondary"><?= e($galeria['titulo']) ?></span></nav>
            <h1 class="display-6 fw-bold mb-3"><?= e($galeria['titulo']) ?></h1>
            <?php if ($galeria['descricao']): ?><p class="lead text-secondary mb-4"><?= nl2br(e((string)$galeria['descricao'])) ?></p><?php endif; ?>

            <?php if (!$fotos): ?>
                <div class="alert alert-light border">Esta galeria ainda não possui fotos.</div>
            <?php else: ?>
                <div class="row g-3 gallery-public-grid">
                    <?php foreach ($fotos as $i => $foto): ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <button type="button" class="gallery-photo-button w-100 border-0 p-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#galleryModal" data-index="<?= $i ?>">
                                <img src="<?= e(mediaUrl($foto['caminho'])) ?>" class="gallery-photo-thumb" alt="<?= e($foto['alt_text'] ?: $foto['legenda'] ?: $foto['titulo'] ?: $galeria['titulo']) ?>" loading="lazy">
                            </button>
                            <?php if ($foto['legenda']): ?><div class="small text-secondary mt-1"><?= e($foto['legenda']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($fotos): ?>
<div class="modal fade" id="galleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-0">
            <div class="modal-header border-0"><h2 class="modal-title fs-6" id="galleryModalCaption"></h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body text-center pt-0"><img id="galleryModalImage" class="gallery-modal-image" src="" alt=""></div>
            <div class="modal-footer border-0 justify-content-between"><button class="btn btn-outline-light" type="button" id="galleryPrev">Anterior</button><span class="small" id="galleryCounter"></span><button class="btn btn-outline-light" type="button" id="galleryNext">Próxima</button></div>
        </div>
    </div>
</div>
<script>
const galleryPhotos = <?= json_encode(array_map(static function ($f) {
    return [
        'src' => mediaUrl((string)$f['caminho']),
        'alt' => (string)($f['alt_text'] ?: $f['legenda'] ?: $f['titulo'] ?: ''),
        'caption' => (string)($f['legenda'] ?: $f['titulo'] ?: ''),
    ];
}, $fotos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let galleryIndex = 0;
function showGalleryPhoto(index) {
    galleryIndex = (index + galleryPhotos.length) % galleryPhotos.length;
    const photo = galleryPhotos[galleryIndex];
    const image = document.getElementById('galleryModalImage');
    image.src = photo.src;
    image.alt = photo.alt;
    document.getElementById('galleryModalCaption').textContent = photo.caption;
    document.getElementById('galleryCounter').textContent = (galleryIndex + 1) + ' / ' + galleryPhotos.length;
}
document.querySelectorAll('.gallery-photo-button').forEach(function (button) {
    button.addEventListener('click', function () { showGalleryPhoto(Number(button.dataset.index || 0)); });
});
document.getElementById('galleryPrev')?.addEventListener('click', function () { showGalleryPhoto(galleryIndex - 1); });
document.getElementById('galleryNext')?.addEventListener('click', function () { showGalleryPhoto(galleryIndex + 1); });
document.addEventListener('keydown', function (event) {
    if (!document.getElementById('galleryModal')?.classList.contains('show')) return;
    if (event.key === 'ArrowLeft') showGalleryPhoto(galleryIndex - 1);
    if (event.key === 'ArrowRight') showGalleryPhoto(galleryIndex + 1);
});
</script>
<?php endif; ?>
<?php require themeFile($pdo, 'footer.php'); ?>
