<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('paginas.gerenciar');
$pdo = Database::connection();

$paginas = $pdo->query(
    "SELECT p.*, u.nome AS autor_nome
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     ORDER BY p.ordem ASC, p.id DESC"
)->fetchAll();

$pageTitle = 'Páginas';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Páginas</h1>
        <p class="text-secondary mb-0">Conteúdo institucional e permanente do portal.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Menu</th>
                    <th>Ordem</th>
                    <th>Publicação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$paginas): ?>
                <tr><td colspan="7" class="text-secondary">Nenhuma página cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($paginas as $pagina): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($pagina['titulo']) ?></div>
                        <div class="small text-secondary"><?= e($pagina['autor_nome'] ?: '') ?></div>
                    </td>
                    <td><code><?= e($pagina['slug']) ?></code></td>
                    <td><span class="badge text-bg-secondary"><?= e($pagina['status']) ?></span></td>
                    <td><?= $pagina['exibir_menu'] ? '<span class="badge text-bg-success">Sim</span>' : '<span class="text-secondary">Não</span>' ?></td>
                    <td><?= (int)$pagina['ordem'] ?></td>
                    <td><?= e(formatDateBr($pagina['publicado_em'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($pagina['status'] === 'publicado'): ?>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(url('pagina.php?slug=' . urlencode($pagina['slug']))) ?>">Ver</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/paginas/form.php?id=' . (int)$pagina['id'])) ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
