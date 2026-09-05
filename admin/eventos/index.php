<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');
$pdo = Database::connection();

$tipo = trim((string)($_GET['tipo'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$categoriaId = (int)($_GET['categoria'] ?? 0);
$comunidadeId = (int)($_GET['comunidade'] ?? 0);
$busca = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];

if (in_array($tipo, ['culto', 'festa', 'atividade', 'reuniao'], true)) {
    $where[] = 'e.tipo = :tipo';
    $params['tipo'] = $tipo;
}
if (in_array($status, ['rascunho', 'publicado', 'cancelado', 'arquivado'], true)) {
    $where[] = 'e.status = :status';
    $params['status'] = $status;
}
if ($categoriaId > 0) {
    $where[] = 'e.categoria_evento_id = :categoria_id';
    $params['categoria_id'] = $categoriaId;
}
if ($comunidadeId > 0) {
    $where[] = 'e.comunidade_id = :comunidade_id';
    $params['comunidade_id'] = $comunidadeId;
}
if ($busca !== '') {
    $where[] = '(e.titulo LIKE :busca OR e.local LIKE :busca OR e.resumo LIKE :busca)';
    $params['busca'] = '%' . $busca . '%';
}

$sql = "SELECT e.*, c.nome AS comunidade_nome, ec.nome AS categoria_nome
        FROM eventos e
        LEFT JOIN comunidades c ON c.id = e.comunidade_id
        LEFT JOIN evento_categorias ec ON ec.id = e.categoria_evento_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY e.data_inicio DESC, e.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();
$categorias = $pdo->query('SELECT id, nome FROM evento_categorias ORDER BY ordem, nome')->fetchAll();
$comunidades = $pdo->query('SELECT id, nome FROM comunidades WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();

$pageTitle = 'Agenda';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Agenda</h1>
        <p class="text-secondary mb-0">Gerencie a agenda paroquial e das comunidades.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/eventos/categorias.php')) ?>">Categorias</a>
        <a class="btn btn-primary" href="<?= e(url('admin/eventos/form.php')) ?>">Adicionar novo</a>
    </div>
</div>

<form method="get" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label">Buscar</label>
                <input class="form-control" name="q" value="<?= e($busca) ?>" placeholder="Título, local ou resumo">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="tipo">
                    <option value="">Todos</option>
                    <option value="culto" <?= $tipo === 'culto' ? 'selected' : '' ?>>Cultos</option>
                    <option value="festa" <?= $tipo === 'festa' ? 'selected' : '' ?>>Festas</option>
                    <option value="atividade" <?= $tipo === 'atividade' ? 'selected' : '' ?>>Atividades</option>
                    <option value="reuniao" <?= $tipo === 'reuniao' ? 'selected' : '' ?>>Reuniões</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label">Categoria</label>
                <select class="form-select" name="categoria">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?= (int)$categoria['id'] ?>" <?= $categoriaId === (int)$categoria['id'] ? 'selected' : '' ?>><?= e($categoria['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label">Comunidade</label>
                <select class="form-select" name="comunidade">
                    <option value="">Todas</option>
                    <?php foreach ($comunidades as $comunidade): ?>
                        <option value="<?= (int)$comunidade['id'] ?>" <?= $comunidadeId === (int)$comunidade['id'] ? 'selected' : '' ?>><?= e($comunidade['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">Todos</option>
                    <?php foreach (['rascunho' => 'Rascunho', 'publicado' => 'Publicado', 'cancelado' => 'Cancelado', 'arquivado' => 'Arquivado'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-6 d-flex gap-2">
                <button class="btn btn-outline-primary" title="Filtrar"><i class="bi bi-funnel"></i></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('admin/eventos/index.php')) ?>" title="Limpar"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold">Agenda cadastrada</span>
        <span class="badge text-bg-light border"><?= count($eventos) ?> resultado<?= count($eventos) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th>Comunidade</th>
                    <th>Local</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$eventos): ?>
                <tr><td colspan="8" class="text-secondary">Nenhum item da agenda encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($eventos as $evento): ?>
                <tr>
                    <td class="text-nowrap"><?= e(formatDateBr($evento['data_inicio'])) ?></td>
                    <td><span class="badge <?= e(eventTypeBadgeClass((string)$evento['tipo'])) ?>"><?= e(eventTypeLabel($evento['tipo'])) ?></span></td>
                    <td class="fw-semibold"><?= e($evento['titulo']) ?><?php if ((int)$evento['santa_ceia'] === 1): ?><div class="small text-secondary">Com Santa Ceia</div><?php endif; ?></td>
                    <td><?= e($evento['categoria_nome'] ?: '-') ?></td>
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
