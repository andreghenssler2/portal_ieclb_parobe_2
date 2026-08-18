<?php
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();

$stats = [
    'noticias' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'publicado'")->fetchColumn(),
    'rascunhos' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'rascunho'")->fetchColumn(),
    'paginas' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'publicado'")->fetchColumn(),
    'comunidades' => (int)$pdo->query("SELECT COUNT(*) FROM comunidades WHERE ativa = 1")->fetchColumn(),
    'midias' => (int)$pdo->query("SELECT COUNT(*) FROM midias")->fetchColumn(),
];

$ultimasNoticias = $pdo->query("SELECT id, titulo, status, publicado_em, created_at FROM posts ORDER BY id DESC LIMIT 5")->fetchAll();
$ultimasPaginas = $pdo->query("SELECT id, titulo, slug, status, publicado_em, created_at FROM paginas ORDER BY id DESC LIMIT 5")->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-secondary mb-0">Visão geral do portal.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a>
        <a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Notícias publicadas', $stats['noticias']],
        ['Rascunhos de notícias', $stats['rascunhos']],
        ['Páginas publicadas', $stats['paginas']],
        ['Comunidades', $stats['comunidades']],
        ['Mídias', $stats['midias']],
    ] as [$label, $value]): ?>
        <div class="col-sm-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small"><?= e($label) ?></div>
                    <div class="display-6 fw-semibold"><?= (int)$value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Últimas notícias</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Título</th><th>Status</th><th>Data</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$ultimasNoticias): ?><tr><td colspan="4" class="text-secondary">Nenhuma notícia cadastrada.</td></tr><?php endif; ?>
                    <?php foreach ($ultimasNoticias as $post): ?>
                        <tr>
                            <td><?= e($post['titulo']) ?></td>
                            <td><span class="badge text-bg-secondary"><?= e($post['status']) ?></span></td>
                            <td><?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/noticias/form.php?id='.(int)$post['id'])) ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Últimas páginas</div>
            <div class="list-group list-group-flush">
                <?php if (!$ultimasPaginas): ?><div class="list-group-item text-secondary">Nenhuma página cadastrada.</div><?php endif; ?>
                <?php foreach ($ultimasPaginas as $pagina): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3" href="<?= e(url('admin/paginas/form.php?id='.(int)$pagina['id'])) ?>">
                        <div>
                            <div class="fw-semibold"><?= e($pagina['titulo']) ?></div>
                            <small class="text-secondary">/<?= e($pagina['slug']) ?></small>
                        </div>
                        <span class="badge text-bg-secondary"><?= e($pagina['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
