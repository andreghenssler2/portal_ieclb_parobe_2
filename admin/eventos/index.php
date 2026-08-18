<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');
$pdo = Database::connection();

$tipo = trim((string)($_GET['tipo'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$where = [];
$params = [];

if (in_array($tipo, ['culto', 'evento'], true)) {
    $where[] = 'e.tipo = :tipo';
    $params['tipo'] = $tipo;
}
if (in_array($status, ['rascunho', 'publicado', 'cancelado', 'arquivado'], true)) {
    $where[] = 'e.status = :status';
    $params['status'] = $status;
}

$sql = "SELECT e.*, c.nome AS comunidade_nome
        FROM eventos e
        LEFT JOIN comunidades c ON c.id = e.comunidade_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY e.data_inicio DESC, e.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();

$pageTitle = 'Eventos e Cultos';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Eventos e Cultos</h1>
        <p class="text-secondary mb-0">Gerencie a agenda paroquial e das comunidades.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/eventos/form.php')) ?>">Novo item</a>
</div>

<form method="get" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="tipo">
                    <option value="">Todos</option>
                    <option value="culto" <?= $tipo === 'culto' ? 'selected' : '' ?>>Cultos</option>
                    <option value="evento" <?= $tipo === 'evento' ? 'selected' : '' ?>>Eventos</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">Todos</option>
                    <?php foreach (['rascunho' => 'Rascunho', 'publicado' => 'Publicado', 'cancelado' => 'Cancelado', 'arquivado' => 'Arquivado'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-outline-primary">Filtrar</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('admin/eventos/index.php')) ?>">Limpar</a>
            </div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Título</th>
                    <th>Comunidade</th>
                    <th>Local</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$eventos): ?>
                <tr><td colspan="7" class="text-secondary">Nenhum evento ou culto cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($eventos as $evento): ?>
                <tr>
                    <td class="text-nowrap"><?= e(formatDateBr($evento['data_inicio'])) ?></td>
                    <td><span class="badge <?= $evento['tipo'] === 'culto' ? 'text-bg-primary' : 'text-bg-info' ?>"><?= e(eventTypeLabel($evento['tipo'])) ?></span></td>
                    <td class="fw-semibold"><?= e($evento['titulo']) ?><?php if ((int)$evento['santa_ceia'] === 1): ?><div class="small text-secondary">Com Santa Ceia</div><?php endif; ?></td>
                    <td><?= e($evento['comunidade_nome'] ?: 'Paroquial') ?></td>
                    <td><?= e($evento['local'] ?: '-') ?></td>
                    <td><span class="badge text-bg-secondary"><?= e($evento['status']) ?></span></td>
                    <td class="text-end text-nowrap">
                        <?php if ($evento['status'] === 'publicado'): ?>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(contentUrl('evento', (string)$evento['slug'])) ?>">Ver</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/eventos/form.php?id=' . (int)$evento['id'])) ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
