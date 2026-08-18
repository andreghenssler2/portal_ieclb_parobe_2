<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'nova', 'lida', 'arquivada'], true)) {
    $status = '';
}

$form = null;
$params = [];
$where = [];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM formularios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $form = $stmt->fetch();
    if (!$form) {
        http_response_code(404);
        exit('Formulário não encontrado.');
    }
    $where[] = 'r.formulario_id = :formulario_id';
    $params['formulario_id'] = $id;
}

if ($status !== '') {
    $where[] = 'r.status = :status';
    $params['status'] = $status;
}

$sql = "SELECT r.*, f.titulo AS formulario_titulo, f.id AS formulario_ref
        FROM formulario_respostas r
        INNER JOIN formularios f ON f.id = r.formulario_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY r.id DESC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = $form ? 'Respostas - ' . $form['titulo'] : 'Respostas de Formulários';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $form ? 'Respostas · ' . e($form['titulo']) : 'Respostas de Formulários' ?></h1>
        <p class="text-secondary mb-0"><?= count($rows) ?> resposta(s) exibida(s)<?= $form ? ' neste formulário' : ' em todos os formulários' ?>.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($form): ?>
            <a class="btn btn-outline-success" href="<?= e(url('admin/formularios/exportar.php?id=' . (int)$form['id'])) ?>">Exportar CSV</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/formularios/index.php')) ?>">Formulários</a>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php
    $base = 'admin/formularios/respostas.php' . ($id > 0 ? '?id=' . $id : '');
    $sep = $id > 0 ? '&' : '?';
    ?>
    <a class="btn btn-sm <?= $status === '' ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url($base)) ?>">Todas</a>
    <?php foreach (['nova' => 'Novas', 'lida' => 'Lidas', 'arquivada' => 'Arquivadas'] as $key => $label): ?>
        <a class="btn btn-sm <?= $status === $key ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url($base . $sep . 'status=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($form): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/formularios/respostas.php')) ?>">Ver todos os formulários</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
            <tr>
                <th>#</th>
                <?php if (!$form): ?><th>Formulário</th><?php endif; ?>
                <th>Status</th>
                <th>Recebida em</th>
                <th>Origem</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $form ? 5 : 6 ?>" class="text-secondary p-4">Nenhuma resposta encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <?php if (!$form): ?>
                        <td>
                            <a class="text-decoration-none fw-semibold" href="<?= e(url('admin/formularios/respostas.php?id=' . (int)$row['formulario_ref'])) ?>">
                                <?= e($row['formulario_titulo']) ?>
                            </a>
                        </td>
                    <?php endif; ?>
                    <td><span class="badge text-bg-<?= $row['status'] === 'nova' ? 'danger' : ($row['status'] === 'lida' ? 'success' : 'secondary') ?>"><?= e($row['status']) ?></span></td>
                    <td><?= e(formatDateBr($row['created_at'])) ?></td>
                    <td class="small text-secondary"><?= e($row['ip'] ?: '—') ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/formularios/resposta.php?id=' . (int)$row['id'])) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
