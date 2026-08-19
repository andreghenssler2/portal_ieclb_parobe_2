<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($id <= 0) throw new RuntimeException('Notícia inválida.');
            $stmt = $pdo->prepare('SELECT id, titulo, status, status_anterior FROM posts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $post = $stmt->fetch();
            if (!$post) throw new RuntimeException('Notícia não encontrada.');

            if ($action === 'trash') {
                if ((string)$post['status'] !== 'lixeira') {
                    $pdo->beginTransaction();
                    try {
                        RevisionService::create($pdo, 'post', $id, Auth::id());
                        $up = $pdo->prepare("UPDATE posts SET status_anterior = status, status = 'lixeira', lixeira_em = NOW() WHERE id = :id");
                        $up->execute(['id' => $id]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                    logAction($pdo, 'noticia.lixeira', 'posts', $id, (string)$post['titulo']);
                }
                Session::flash('success', 'Notícia movida para a Lixeira.');
            } elseif ($action === 'restore') {
                if ((string)$post['status'] !== 'lixeira') throw new RuntimeException('Esta notícia não está na Lixeira.');
                $allowed = ['rascunho', 'agendado', 'publicado', 'arquivado'];
                $restoreStatus = in_array((string)$post['status_anterior'], $allowed, true) ? (string)$post['status_anterior'] : 'rascunho';
                $up = $pdo->prepare('UPDATE posts SET status = :status, status_anterior = NULL, lixeira_em = NULL WHERE id = :id');
                $up->execute(['status' => $restoreStatus, 'id' => $id]);
                logAction($pdo, 'noticia.restaurar_lixeira', 'posts', $id, (string)$post['titulo']);
                Session::flash('success', 'Notícia restaurada da Lixeira.');
            } elseif ($action === 'delete') {
                if ((string)$post['status'] !== 'lixeira') throw new RuntimeException('Só é possível excluir permanentemente uma notícia que esteja na Lixeira.');
                $pdo->beginTransaction();
                try {
                    RevisionService::deleteForContent($pdo, 'post', $id);
                    $pdo->prepare('DELETE FROM posts WHERE id = :id')->execute(['id' => $id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                logAction($pdo, 'noticia.excluir_permanente', 'posts', $id, (string)$post['titulo']);
                Session::flash('success', 'Notícia excluída permanentemente.');
            } else {
                throw new RuntimeException('Ação inválida.');
            }
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
    $redirectStatus = (string)($_POST['return_status'] ?? '') === 'lixeira' ? '?status=lixeira' : '';
    header('Location: ' . url('admin/noticias/index.php' . $redirectStatus));
    exit;
}

$view = (string)($_GET['status'] ?? '') === 'lixeira' ? 'lixeira' : 'ativos';
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status <> 'lixeira'")->fetchColumn();
$trashCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'lixeira'")->fetchColumn();
$where = $view === 'lixeira' ? "p.status = 'lixeira'" : "p.status <> 'lixeira'";
$posts = $pdo->query(
    "SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome,
            (SELECT COUNT(*) FROM revisoes r WHERE r.tipo='post' AND r.conteudo_id=p.id) AS total_revisoes
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id
     LEFT JOIN categorias cat ON cat.id=p.categoria_id
     WHERE {$where}
     ORDER BY " . ($view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.id DESC')
)->fetchAll();

$pageTitle = $view === 'lixeira' ? 'Lixeira de Notícias' : 'Notícias';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1"><?= e($pageTitle) ?></h1><p class="text-secondary mb-0"><?= $view === 'lixeira' ? 'Itens removidos podem ser restaurados ou excluídos permanentemente.' : 'Conteúdo publicado no portal.' ?></p></div>
    <?php if ($view !== 'lixeira'): ?><a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a><?php endif; ?>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item"><a class="nav-link <?= $view === 'ativos' ? 'active' : '' ?>" href="<?= e(url('admin/noticias/index.php')) ?>">Todos <span class="badge <?= $view === 'ativos' ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= $activeCount ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'lixeira' ? 'active' : '' ?>" href="<?= e(url('admin/noticias/index.php?status=lixeira')) ?>">Lixeira <span class="badge <?= $view === 'lixeira' ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= $trashCount ?></span></a></li>
</ul>

<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Título</th><th>Comunidade</th><th>Categoria</th><th>Status</th><th><?= $view === 'lixeira' ? 'Excluída em' : 'Publicação' ?></th><th></th></tr></thead><tbody>
<?php if (!$posts): ?><tr><td colspan="6" class="text-secondary py-4"><?= $view === 'lixeira' ? 'A Lixeira está vazia.' : 'Nenhuma notícia cadastrada.' ?></td></tr><?php endif; ?>
<?php foreach ($posts as $post): ?><tr>
<td><div class="fw-semibold"><?= e($post['titulo']) ?></div><?php if ((int)$post['total_revisoes'] > 0): ?><div class="small text-secondary"><?= (int)$post['total_revisoes'] ?> revisões</div><?php endif; ?></td>
<td><?= e($post['comunidade_nome'] ?: 'Paroquial') ?></td><td><?= e($post['categoria_nome'] ?: '-') ?></td>
<td><span class="badge text-bg-secondary"><?= e($post['status']) ?></span><?php if ($view === 'lixeira' && !empty($post['status_anterior'])): ?><div class="small text-secondary mt-1">era: <?= e((string)$post['status_anterior']) ?></div><?php endif; ?></td>
<td><?= e(formatDateBr($view === 'lixeira' ? $post['lixeira_em'] : $post['publicado_em'])) ?></td>
<td class="text-end"><div class="d-flex flex-wrap gap-1 justify-content-end">
<?php if ($view === 'lixeira'): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_status" value="lixeira"><button class="btn btn-sm btn-outline-success">Restaurar</button></form>
    <form method="post" onsubmit="return confirm('Excluir esta notícia permanentemente? Esta ação não pode ser desfeita.');"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_status" value="lixeira"><button class="btn btn-sm btn-outline-danger">Excluir permanentemente</button></form>
<?php else: ?>
    <?php if (!empty($post['slug']) && $post['status'] === 'publicado'): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Ver</a><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/noticias/form.php?id='.(int)$post['id'])) ?>">Editar</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/revisoes/index.php?tipo=post&id='.(int)$post['id'])) ?>">Revisões</a>
    <form method="post" onsubmit="return confirm('Mover esta notícia para a Lixeira?');"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="action" value="trash"><button class="btn btn-sm btn-outline-danger">Lixeira</button></form>
<?php endif; ?>
</div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../_footer.php'; ?>
