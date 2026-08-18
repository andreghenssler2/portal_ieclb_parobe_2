<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$destaque = $pdo->query("SELECT p.*, c.nome AS comunidade_nome FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) AND p.destaque=1 ORDER BY p.publicado_em DESC, p.id DESC LIMIT 1")->fetch();
$posts = $pdo->query("SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN categorias cat ON cat.id=p.categoria_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) ORDER BY p.publicado_em DESC, p.id DESC LIMIT 9")->fetchAll();
$comunidades = $pdo->query("SELECT * FROM comunidades WHERE ativa=1 ORDER BY ordem,nome")->fetchAll();

$metaTitle = 'IECLB Parobé';
require __DIR__ . '/theme/ieclb/header.php';
?>
<section class="hero py-5 border-bottom">
    <div class="container py-4">
        <span class="text-uppercase small fw-semibold text-secondary">Paróquia Evangélica de Confissão Luterana</span>
        <h1 class="display-4 fw-bold mt-2">IECLB Parobé</h1>
        <p class="lead col-lg-7">Notícias, cultos, eventos e informações das comunidades da Paróquia de Parobé.</p>
    </div>
</section>

<?php if ($destaque): ?>
<section class="container py-5">
    <div class="p-4 p-lg-5 bg-dark text-white rounded-4">
        <div class="small text-uppercase opacity-75 mb-2"><?= e($destaque['comunidade_nome'] ?: 'Paroquial') ?></div>
        <h2 class="display-6 fw-bold"><?= e($destaque['titulo']) ?></h2>
        <?php if ($destaque['resumo']): ?><p class="lead opacity-75"><?= e($destaque['resumo']) ?></p><?php endif; ?>
        <a class="btn btn-light" href="<?= e(url('noticia.php?slug=' . urlencode($destaque['slug']))) ?>">Leia mais</a>
    </div>
</section>
<?php endif; ?>

<section class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4"><h2 class="h3 mb-0">Últimas notícias</h2></div>
    <div class="row g-4">
        <?php if (!$posts): ?><div class="col-12"><div class="alert alert-light border">Ainda não há notícias publicadas.</div></div><?php endif; ?>
        <?php foreach ($posts as $post): ?>
        <div class="col-md-6 col-lg-4">
            <article class="card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="small text-secondary mb-2"><?= e($post['categoria_nome'] ?: 'Notícia') ?> · <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div>
                    <h3 class="h5"><a class="stretched-link text-decoration-none text-dark" href="<?= e(url('noticia.php?slug=' . urlencode($post['slug']))) ?>"><?= e($post['titulo']) ?></a></h3>
                    <?php if ($post['resumo']): ?><p class="text-secondary mb-0"><?= e($post['resumo']) ?></p><?php endif; ?>
                </div>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="container py-5">
    <h2 class="h3 mb-4">Nossas comunidades</h2>
    <div class="row g-3">
        <?php foreach ($comunidades as $comunidade): ?>
        <div class="col-sm-6 col-lg"><div class="p-3 border rounded-3 bg-white h-100"><strong><?= e($comunidade['nome']) ?></strong><div class="small text-secondary"><?= e($comunidade['cidade'] ?? '') ?></div></div></div>
        <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/theme/ieclb/footer.php'; ?>
