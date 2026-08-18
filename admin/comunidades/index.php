<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();
$comunidades = $pdo->query('SELECT * FROM comunidades ORDER BY ordem, nome')->fetchAll();
$pageTitle = 'Comunidades';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="h3 mb-1">Comunidades</h1><p class="text-secondary mb-0">Gerencie as comunidades da paróquia.</p></div>
    <a class="btn btn-primary" href="<?= e(url('admin/comunidades/form.php')) ?>">Nova comunidade</a>
</div>
<div class="card border-0 shadow-sm">
<div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Nome</th><th>Cidade</th><th>Status</th><th>Ordem</th><th></th></tr></thead><tbody>
<?php foreach ($comunidades as $item): ?>
<tr>
<td class="fw-semibold"><?= e($item['nome']) ?></td>
<td><?= e(trim(($item['cidade'] ?? '') . '/' . ($item['uf'] ?? ''), '/')) ?></td>
<td><?= $item['ativa'] ? '<span class="badge text-bg-success">Ativa</span>' : '<span class="badge text-bg-secondary">Inativa</span>' ?></td>
<td><?= (int)$item['ordem'] ?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/comunidades/form.php?id=' . (int)$item['id'])) ?>">Editar</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../_footer.php'; ?>
