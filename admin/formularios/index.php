<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $acao = (string)($_POST['acao'] ?? '');
        if ($id > 0 && $acao === 'excluir') {
            $stmt = $pdo->prepare('SELECT titulo FROM formularios WHERE id=:id LIMIT 1');
            $stmt->execute(['id'=>$id]);
            $titulo = $stmt->fetchColumn();
            if ($titulo !== false) {
                $pdo->prepare('DELETE FROM formularios WHERE id=:id')->execute(['id'=>$id]);
                logAction($pdo, 'formulario.excluir', 'formularios', $id, (string)$titulo);
                Session::flash('success', 'Formulário excluído com sucesso.');
            }
        }
    }
    header('Location: ' . url('admin/formularios/index.php'));
    exit;
}

$forms = $pdo->query(
    "SELECT f.*,
            (SELECT COUNT(*) FROM formulario_campos c WHERE c.formulario_id=f.id) AS total_campos,
            (SELECT COUNT(*) FROM formulario_respostas r WHERE r.formulario_id=f.id) AS total_respostas,
            (SELECT COUNT(*) FROM formulario_respostas r WHERE r.formulario_id=f.id AND r.status='nova') AS novas
     FROM formularios f
     ORDER BY f.id DESC"
)->fetchAll();

$pageTitle = 'Formulários';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Formulários</h1><p class="text-secondary mb-0">Crie formulários públicos e acompanhe as respostas recebidas.</p></div>
    <a class="btn btn-primary" href="<?= e(url('admin/formularios/form.php')) ?>">Novo formulário</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Título</th><th>Status</th><th>Campos</th><th>Respostas</th><th>URL</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$forms): ?><tr><td colspan="6" class="text-secondary p-4">Nenhum formulário cadastrado.</td></tr><?php endif; ?>
            <?php foreach ($forms as $form): ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($form['titulo']) ?></div><?php if ((int)$form['ativo'] !== 1): ?><span class="badge text-bg-light border">Inativo</span><?php endif; ?></td>
                    <td><span class="badge text-bg-<?= $form['status']==='publicado' ? 'success' : 'secondary' ?>"><?= e($form['status']) ?></span></td>
                    <td><?= (int)$form['total_campos'] ?></td>
                    <td><?= (int)$form['total_respostas'] ?><?php if ((int)$form['novas'] > 0): ?> <span class="badge text-bg-danger"><?= (int)$form['novas'] ?> nova(s)</span><?php endif; ?></td>
                    <td><a class="small text-decoration-none" target="_blank" href="<?= e(contentUrl('formulario',(string)$form['slug'])) ?>">/formulario/<?= e($form['slug']) ?></a></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/formularios/respostas.php?id='.(int)$form['id'])) ?>">Respostas</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/formularios/form.php?id='.(int)$form['id'])) ?>">Editar</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este formulário e todas as respostas?');">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><input type="hidden" name="acao" value="excluir">
                            <button class="btn btn-sm btn-outline-danger">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
