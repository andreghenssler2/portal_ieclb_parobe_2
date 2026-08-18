<?php
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();

$stats = [
    'noticias' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'publicado'")->fetchColumn(),
    'rascunhos' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'rascunho'")->fetchColumn(),
    'paginas' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'publicado'")->fetchColumn(),
    'agenda' => (int)$pdo->query("SELECT COUNT(*) FROM eventos WHERE status = 'publicado' AND data_inicio >= NOW()")->fetchColumn(),
    'comunidades' => (int)$pdo->query("SELECT COUNT(*) FROM comunidades WHERE ativa = 1")->fetchColumn(),
    'midias' => (int)$pdo->query("SELECT COUNT(*) FROM midias")->fetchColumn(),
];

$ultimasNoticias = $pdo->query("SELECT id, titulo, status, publicado_em, created_at FROM posts ORDER BY id DESC LIMIT 5")->fetchAll();
$proximosEventos = $pdo->query(
    "SELECT e.id, e.tipo, e.titulo, e.data_inicio, e.santa_ceia, c.nome AS comunidade_nome
     FROM eventos e
     LEFT JOIN comunidades c ON c.id = e.comunidade_id
     WHERE e.status = 'publicado' AND e.data_inicio >= NOW()
     ORDER BY e.data_inicio ASC
     LIMIT 6"
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-secondary mb-0">Visão geral do portal.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('admin/eventos/form.php')) ?>">Novo evento/culto</a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a>
        <a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Notícias publicadas', $stats['noticias']],
        ['Rascunhos', $stats['rascunhos']],
        ['Páginas', $stats['paginas']],
        ['Próximos na agenda', $stats['agenda']],
        ['Comunidades', $stats['comunidades']],
        ['Mídias', $stats['midias']],
    ] as [$label, $value]): ?>
        <div class="col-sm-6 col-lg-4 col-xl-2">
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
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Próximos eventos e cultos</span>
                <a class="small text-decoration-none" href="<?= e(url('admin/eventos/index.php')) ?>">Ver agenda</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$proximosEventos): ?><div class="list-group-item text-secondary">Nenhum item futuro publicado.</div><?php endif; ?>
                <?php foreach ($proximosEventos as $evento): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/eventos/form.php?id=' . (int)$evento['id'])) ?>">
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold"><?= e($evento['titulo']) ?></div>
                                <small class="text-secondary"><?= e($evento['comunidade_nome'] ?: 'Paroquial') ?><?php if ((int)$evento['santa_ceia'] === 1): ?> · Santa Ceia<?php endif; ?></small>
                            </div>
                            <div class="text-end text-nowrap">
                                <div class="small fw-semibold"><?= e(formatDateOnlyBr($evento['data_inicio'])) ?></div>
                                <div class="small text-secondary"><?= e(formatTimeBr($evento['data_inicio'])) ?></div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
