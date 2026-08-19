<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('paginas.gerenciar');
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($id <= 0) throw new RuntimeException('Página inválida.');
            $stmt = $pdo->prepare('SELECT id, titulo, status, status_anterior FROM paginas WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $pagina = $stmt->fetch();
            if (!$pagina) throw new RuntimeException('Página não encontrada.');

            if ($action === 'trash') {
                if ((string)$pagina['status'] !== 'lixeira') {
                    $pdo->beginTransaction();
                    try {
                        RevisionService::create($pdo, 'pagina', $id, Auth::id());
                        $pdo->prepare("UPDATE paginas SET status_anterior = status, status = 'lixeira', lixeira_em = NOW() WHERE id = :id")->execute(['id' => $id]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                    logAction($pdo, 'pagina.lixeira', 'paginas', $id, (string)$pagina['titulo']);
                }
                Session::flash('success', 'Página movida para a Lixeira.');
            } elseif ($action === 'restore') {
                if ((string)$pagina['status'] !== 'lixeira') throw new RuntimeException('Esta página não está na Lixeira.');
                $allowed = ['rascunho', 'agendado', 'publicado', 'arquivado'];
                $restoreStatus = in_array((string)$pagina['status_anterior'], $allowed, true) ? (string)$pagina['status_anterior'] : 'rascunho';
                $pdo->prepare('UPDATE paginas SET status = :status, status_anterior = NULL, lixeira_em = NULL WHERE id = :id')->execute(['status' => $restoreStatus, 'id' => $id]);
                logAction($pdo, 'pagina.restaurar_lixeira', 'paginas', $id, (string)$pagina['titulo']);
                Session::flash('success', 'Página restaurada da Lixeira.');
            } elseif ($action === 'delete') {
                if ((string)$pagina['status'] !== 'lixeira') throw new RuntimeException('Só é possível excluir permanentemente uma página que esteja na Lixeira.');
                $pdo->beginTransaction();
                try {
                    RevisionService::deleteForContent($pdo, 'pagina', $id);
                    try { $pdo->prepare('DELETE FROM menu_itens WHERE pagina_id = :id')->execute(['id' => $id]); } catch (Throwable $ignored) {}
                    $pdo->prepare('DELETE FROM paginas WHERE id = :id')->execute(['id' => $id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                logAction($pdo, 'pagina.excluir_permanente', 'paginas', $id, (string)$pagina['titulo']);
                Session::flash('success', 'Página excluída permanentemente.');
            } else {
                throw new RuntimeException('Ação inválida.');
            }
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
    $redirectStatus = (string)($_POST['return_status'] ?? '') === 'lixeira' ? '?status=lixeira' : '';
    header('Location: ' . url('admin/paginas/index.php' . $redirectStatus));
    exit;
}

$view = (string)($_GET['status'] ?? '') === 'lixeira' ? 'lixeira' : 'ativos';
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status <> 'lixeira'")->fetchColumn();
$trashCount = (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'lixeira'")->fetchColumn();
$where = $view === 'lixeira' ? "p.status = 'lixeira'" : "p.status <> 'lixeira'";
$paginas = $pdo->query(
    "SELECT p.*, u.nome AS autor_nome,
            (SELECT COUNT(*) FROM revisoes r WHERE r.tipo='pagina' AND r.conteudo_id=p.id) AS total_revisoes
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     WHERE {$where}
     ORDER BY " . ($view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.ordem ASC, p.id DESC')
)->fetchAll();

$pageTitle = $view === 'lixeira' ? 'Lixeira de Páginas' : 'Páginas';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1"><?= e($pageTitle) ?></h1><p class="text-secondary mb-0"><?= $view === 'lixeira' ? 'Itens removidos podem ser restaurados ou excluídos permanentemente.' : 'Conteúdo institucional e permanente do portal.' ?></p></div>
    <?php if ($view !== 'lixeira'): ?><a class="btn btn-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a><?php endif; ?>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item"><a class="nav-link <?= $view === 'ativos' ? 'active' : '' ?>" href="<?= e(url('admin/paginas/index.php')) ?>">Todas <span class="badge <?= $view === 'ativos' ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= $activeCount ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'lixeira' ? 'active' : '' ?>" href="<?= e(url('admin/paginas/index.php?status=lixeira')) ?>">Lixeira <span class="badge <?= $view === 'lixeira' ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= $trashCount ?></span></a></li>
</ul>

<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Título</th><th>Slug</th><th>Status</th><th>Menu</th><th><?= $view === 'lixeira' ? 'Excluída em' : 'Publicação' ?></th><th></th></tr></thead><tbody>
<?php if (!$paginas): ?><tr><td colspan="6" class="text-secondary py-4"><?= $view === 'lixeira' ? 'A Lixeira está vazia.' : 'Nenhuma página cadastrada.' ?></td></tr><?php endif; ?>
<?php foreach ($paginas as $pagina): ?><tr>
<td><div class="fw-semibold"><?= e($pagina['titulo']) ?></div><div class="small text-secondary"><?= e($pagina['autor_nome'] ?: '') ?><?php if ((int)$pagina['total_revisoes'] > 0): ?> · <?= (int)$pagina['total_revisoes'] ?> revisões<?php endif; ?></div></td>
<td><code><?= e($pagina['slug']) ?></code></td>
<td><span class="badge text-bg-secondary"><?= e($pagina['status']) ?></span><?php if ($view === 'lixeira' && !empty($pagina['status_anterior'])): ?><div class="small text-secondary mt-1">era: <?= e((string)$pagina['status_anterior']) ?></div><?php endif; ?></td>
<td><?= $pagina['exibir_menu'] ? '<span class="badge text-bg-success">Sim</span>' : '<span class="text-secondary">Não</span>' ?></td>
<td><?= e(formatDateBr($view === 'lixeira' ? $pagina['lixeira_em'] : $pagina['publicado_em'])) ?></td>
<td class="text-end"><div class="d-flex flex-wrap gap-1 justify-content-end">
<?php if ($view === 'lixeira'): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$pagina['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_status" value="lixeira"><button class="btn btn-sm btn-outline-success">Restaurar</button></form>
    <form method="post" onsubmit="return confirm('Excluir esta página permanentemente? Esta ação não pode ser desfeita.');"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$pagina['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_status" value="lixeira"><button class="btn btn-sm btn-outline-danger">Excluir permanentemente</button></form>
<?php else: ?>
    <?php if ($pagina['status'] === 'publicado'): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(contentUrl('pagina', (string)$pagina['slug'])) ?>">Ver</a><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/paginas/form.php?id=' . (int)$pagina['id'])) ?>">Editar</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/revisoes/index.php?tipo=pagina&id=' . (int)$pagina['id'])) ?>">Revisões</a>
    <form method="post" onsubmit="return confirm('Mover esta página para a Lixeira?');"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$pagina['id'] ?>"><input type="hidden" name="action" value="trash"><button class="btn btn-sm btn-outline-danger">Lixeira</button></form>
<?php endif; ?>
</div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../_footer.php'; ?>
