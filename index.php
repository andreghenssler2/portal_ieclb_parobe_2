<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$destaque = $pdo->query("SELECT p.*, c.nome AS comunidade_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) AND p.destaque=1 ORDER BY p.publicado_em DESC, p.id DESC LIMIT 1")->fetch();
$posts = $pdo->query("SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) ORDER BY p.publicado_em DESC, p.id DESC LIMIT 9")->fetchAll();
$comunidades = $pdo->query("SELECT * FROM comunidades WHERE ativa=1 ORDER BY ordem,nome")->fetchAll();
$agenda = $pdo->query(
    "SELECT e.id, e.tipo, e.titulo, e.slug, e.resumo, e.data_inicio, e.local, e.santa_ceia, c.nome AS comunidade_nome
     FROM eventos e
     LEFT JOIN comunidades c ON c.id = e.comunidade_id
     WHERE e.status = 'publicado' AND e.data_inicio >= NOW()
     ORDER BY e.data_inicio ASC
     LIMIT 6"
)->fetchAll();

$metaTitle = 'IECLB Parobé';
require __DIR__ . '/theme/ieclb/header.php';
?>
<section class="hero py-5 border-bottom">
    <div class="container py-4"><span class="text-uppercase small fw-semibold text-secondary">Paróquia Evangélica de
            Confissão Luterana</span>
        <h1 class="display-4 fw-bold mt-2">IECLB Parobé</h1>
        <p class="lead col-lg-7">Notícias, cultos, eventos e informações das comunidades da Paróquia de Parobé.</p>
    </div>
</section>

<?php if ($destaque):
    $cover = $destaque['imagem_capa_midia'] ?: $destaque['imagem_capa']; ?>
    <section class="container py-5">
        <div class="featured-story bg-dark text-white rounded-4 overflow-hidden">
            <div class="row g-0 align-items-stretch">
                <?php if ($cover): ?>
                    <div class="col-lg-6"><img class="featured-story-image" src="<?= e(mediaUrl($cover)) ?>"
                            alt="<?= e($destaque['imagem_capa_alt'] ?: $destaque['titulo']) ?>"></div><?php endif; ?>
                <div class="<?= $cover ? 'col-lg-6' : 'col-12' ?> p-4 p-lg-5 d-flex flex-column justify-content-center">
                    <div class="small text-uppercase opacity-75 mb-2"><?= e($destaque['comunidade_nome'] ?: 'Paroquial') ?>
                    </div>
                    <h2 class="display-6 fw-bold"><?= e($destaque['titulo']) ?></h2><?php if ($destaque['resumo']): ?>
                        <p class="lead opacity-75"><?= e($destaque['resumo']) ?></p><?php endif; ?>
                    <div><a class="btn btn-light"
                            href="<?= e(url('noticia.php?slug=' . urlencode($destaque['slug']))) ?>">Leia mais</a></div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h3 mb-0">Próximos cultos e eventos</h2>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('agenda.php')) ?>">Ver agenda completa</a>
    </div>
    <div class="row g-3">
        <?php if (!$agenda): ?>
            <div class="col-12">
                <div class="alert alert-light border">Nenhum culto ou evento futuro publicado.</div>
            </div><?php endif; ?>
        <?php foreach ($agenda as $evento): ?>
            <div class="col-md-6 col-xl-4">
                <a class="agenda-home-item text-decoration-none text-dark d-block h-100"
                    href="<?= e(url('evento.php?slug=' . urlencode($evento['slug']))) ?>">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex gap-3">
                                <div class="agenda-date-box text-center flex-shrink-0">
                                    <div class="small text-uppercase"><?= e(formatMonthShortBr($evento['data_inicio'])) ?>
                                    </div>
                                    <div class="fs-3 fw-bold lh-1">
                                        <?= e((new DateTime($evento['data_inicio']))->format('d')) ?></div>
                                </div>
                                <div>
                                    <div class="small text-secondary mb-1"><?= e(eventTypeLabel($evento['tipo'])) ?> ·
                                        <?= e(formatTimeBr($evento['data_inicio'])) ?>    <?php if ((int) $evento['santa_ceia'] === 1): ?>
                                            · Santa Ceia<?php endif; ?></div>
                                    <h3 class="h6 fw-bold mb-1"><?= e($evento['titulo']) ?></h3>
                                    <div class="small text-secondary">
                                        <?= e($evento['comunidade_nome'] ?: 'Paroquial') ?>    <?php if ($evento['local']): ?> ·
                                            <?= e($evento['local']) ?>    <?php endif; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h3 mb-0">Últimas notícias</h2>
    </div>
    <div class="row g-4">
        <?php if (!$posts): ?>
            <div class="col-12">
                <div class="alert alert-light border">Ainda não há notícias publicadas.</div>
            </div><?php endif; ?>
        <?php foreach ($posts as $post):
            $cover = $post['imagem_capa_midia'] ?: $post['imagem_capa']; ?>
            <div class="col-md-6 col-lg-4">
                <article class="card h-100 border-0 shadow-sm overflow-hidden"><?php if ($cover): ?><img
                            src="<?= e(mediaUrl($cover)) ?>" class="card-img-top news-card-image"
                            alt="<?= e($post['imagem_capa_alt'] ?: $post['titulo']) ?>"><?php endif; ?>
                    <div class="card-body p-4">
                        <div class="small text-secondary mb-2"><?= e($post['categoria_nome'] ?: 'Notícia') ?> ·
                            <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div>
                        <h3 class="h5"><a class="stretched-link text-decoration-none text-dark"
                                href="<?= e(url('noticia.php?slug=' . urlencode($post['slug']))) ?>"><?= e($post['titulo']) ?></a>
                        </h3><?php if ($post['resumo']): ?>
                            <p class="text-secondary mb-0"><?= e($post['resumo']) ?></p><?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="container py-5">
    <h2 class="h3 mb-4">Nossas comunidades</h2>
    <div class="row g-3"><?php foreach ($comunidades as $comunidade): ?>
            <div class="col-sm-6 col-lg">
                <div class="p-3 border rounded-3 bg-white h-100"><strong><?= e($comunidade['nome']) ?></strong>
                    <div class="small text-secondary"><?= e($comunidade['cidade'] ?? '') ?></div>
                </div>
            </div><?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/theme/ieclb/footer.php'; ?>