<?php
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();

$stats = [
    'noticias' => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'publicado'")->fetchColumn(),
    'rascunhos' => (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'rascunho'")->fetchColumn(),
    'comunidades' => (int) $pdo->query("SELECT COUNT(*) FROM comunidades WHERE ativa = 1")->fetchColumn(),
    'usuarios' => (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn(),
];

$ultimas = $pdo->query("SELECT titulo, status, publicado_em, created_at FROM posts ORDER BY id DESC LIMIT 5")->fetchAll();
$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="h3 mb-1">Dashboard</h1><p class="text-secondary mb-0">Visão geral do portal.</p></div>
    <a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a>
</div>
<div class="row g-3 mb-4">
    <?php foreach ([['Publicadas',$stats['noticias']],['Rascunhos',$stats['rascunhos']],['Comunidades',$stats['comunidades']],['Usuários',$stats['usuarios']]] as [$label,$value]): ?>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary small"><?= e($label) ?></div><div class="display-6 fw-semibold"><?= (int)$value ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Últimas publicações</div>
    <div class="table-responsive"><table class="table mb-0 align-middle"><thead><tr><th>Título</th><th>Status</th><th>Data</th></tr></thead><tbody>
    <?php if (!$ultimas): ?><tr><td colspan="3" class="text-secondary">Nenhuma notícia cadastrada.</td></tr><?php endif; ?>
    <?php foreach ($ultimas as $post): ?><tr><td><?= e($post['titulo']) ?></td><td><span class="badge text-bg-secondary"><?= e($post['status']) ?></span></td><td><?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
