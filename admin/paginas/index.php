<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../_pagination.php';
require_once __DIR__ . '/../_search.php';
Auth::requireLogin();
Auth::requirePermission('paginas.gerenciar');
$pdo = Database::connection();
PageHierarchyService::ensureSchema($pdo);
ContentBlockService::ensureSchema($pdo);

// v0.46.0 - filtros avançados e ações em massa de Páginas.
// v0.46.0 - ações editoriais em massa.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['bulk_action'])
) {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        try {
            $result = EditorialBulkService::apply(
                $pdo,
                'pagina',
                (array)($_POST['ids'] ?? []),
                trim((string)($_POST['bulk_action'] ?? '')),
                (int)Auth::id()
            );

            $message = $result['processed']
                . ' conteúdo(s) atualizado(s).';

            if ($result['skipped'] > 0) {
                $message .= ' '
                    . $result['skipped']
                    . ' item(ns) não precisaram de alteração.';
            }

            Session::flash('success', $message);
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }

    $return = [];
    foreach (array (
  0 => 'status',
  1 => 'superior',
  2 => 'q',
) as $filterName) {
        $value = trim((string)($_POST[$filterName] ?? ''));
        if ($value !== '') {
            $return[$filterName] = $value;
        }
    }

    $query = http_build_query(
        $return,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    header(
        'Location: '
        . url('admin/paginas/index.php' . ($query !== '' ? '?' . $query : ''))
    );
    exit;
}
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
                    ContentBlockService::deleteForContent($pdo, 'pagina', $id);
                    try { $pdo->prepare('DELETE FROM menu_itens WHERE pagina_id = :id')->execute(['id' => $id]); } catch (Throwable $ignored) {}
                    $pdo->prepare('UPDATE paginas SET parent_id=NULL WHERE parent_id=:id')->execute(['id' => $id]);
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

// v0.45.1 - filtros editoriais de Páginas.
$allowedViews = ['todos', 'publicados', 'agenda', 'rascunhos', 'lixeira'];
$view = strtolower(trim((string)($_GET['status'] ?? 'todos')));
if (!in_array($view, $allowedViews, true)) {
    $view = 'todos';
}

$pageCounts = [
    'todos' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status <> 'lixeira'")->fetchColumn(),
    'publicados' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'publicado'")->fetchColumn(),
    'agenda' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'agendado'")->fetchColumn(),
    'rascunhos' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'rascunho'")->fetchColumn(),
    'lixeira' => (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'lixeira'")->fetchColumn(),
];

$where = match ($view) {
    'publicados' => "p.status = 'publicado'",
    'agenda' => "p.status = 'agendado'",
    'rascunhos' => "p.status = 'rascunho'",
    'lixeira' => "p.status = 'lixeira'",
    default => "p.status <> 'lixeira'",
};

$filterParentRaw = trim((string)($_GET['superior'] ?? ''));
$filterParentId = ctype_digit($filterParentRaw)
    ? (int)$filterParentRaw
    : 0;

$filterSql = '';
$filterParams = [];

if ($filterParentRaw === 'raiz') {
    $filterSql = ' AND p.parent_id IS NULL';
} elseif ($filterParentId > 0) {
    $filterSql = ' AND p.parent_id=:filter_parent';
    $filterParams['filter_parent'] = $filterParentId;
}

$filterPageOptions = PageHierarchyService::options($pdo);

// v0.33.1: pesquisa de páginas + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = $filterParams;
if ($search !== '') {
    $searchSql = " AND (
        p.titulo LIKE :page_q1
        OR p.slug LIKE :page_q2
        OR COALESCE(p.resumo,'') LIKE :page_q3
        OR COALESCE(p.conteudo,'') LIKE :page_q4
        OR COALESCE(u.nome,'') LIKE :page_q5
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 5; $i++) $searchParams['page_q' . $i] = $like;
}
$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM paginas p
     LEFT JOIN usuarios u ON u.id=p.autor_id
     WHERE {$where}{$filterSql}{$searchSql}"
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$listSql = "SELECT p.*, u.nome AS autor_nome,
            parent.titulo AS parent_titulo,
            (SELECT COUNT(*) FROM revisoes r WHERE r.tipo='pagina' AND r.conteudo_id=p.id) AS total_revisoes
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     LEFT JOIN paginas parent ON parent.id=p.parent_id
     WHERE {$where}{$filterSql}{$searchSql}
     ORDER BY " . ($view === 'lixeira'
        ? 'p.lixeira_em DESC, p.id DESC'
        : ($view === 'agenda'
            ? 'p.publicado_em ASC, p.id DESC'
            : 'p.ordem ASC, p.id DESC'))
     . " LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$paginas = $listStmt->fetchAll();
$pageTitle = match ($view) {
    'publicados' => 'Páginas Publicadas',
    'agenda' => 'Agenda de Páginas',
    'rascunhos' => 'Rascunhos de Páginas',
    'lixeira' => 'Lixeira de Páginas',
    default => 'Páginas',
};
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1"><?= e($pageTitle) ?></h1><p class="text-secondary mb-0"><?= $view === 'lixeira' ? 'Itens removidos podem ser restaurados ou excluídos permanentemente.' : 'Conteúdo institucional e permanente do portal.' ?></p></div>
    <?php if ($view !== 'lixeira'): ?><a class="btn btn-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a><?php endif; ?>
</div>

<ul class="nav nav-pills gap-2 mb-4 flex-wrap">
    <?php
    $pageTabs = [
        'todos' => 'Todos',
        'publicados' => 'Publicados',
        'agenda' => 'Agenda',
        'rascunhos' => 'Rascunhos',
        'lixeira' => 'Lixeira',
    ];
    ?>
    <?php foreach ($pageTabs as $tabKey => $tabLabel): ?>
        <?php
        $tabUrl = $tabKey === 'todos'
            ? url('admin/paginas/index.php')
            : url('admin/paginas/index.php?status=' . rawurlencode($tabKey));
        $tabActive = $view === $tabKey;
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $tabActive ? 'active' : '' ?>" href="<?= e($tabUrl) ?>">
                <?= e($tabLabel) ?>
                <span class="badge <?= $tabActive ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1">
                    <?= (int)($pageCounts[$tabKey] ?? 0) ?>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <?php if ($view !== 'todos'): ?>
                <input type="hidden" name="status" value="<?= e($view) ?>">
            <?php endif; ?>
            <?php if ($search !== ''): ?>
                <input type="hidden" name="q" value="<?= e($search) ?>">
            <?php endif; ?>

            <div class="col-md-10">
                <label class="form-label small">Página superior</label>
                <select class="form-select" name="superior">
                    <option value="">Todas as páginas</option>
                    <option value="raiz" <?= $filterParentRaw === 'raiz' ? 'selected' : '' ?>>
                        Somente páginas principais
                    </option>
                    <?php foreach ($filterPageOptions as $option): ?>
                        <option
                            value="<?= (int)$option['id'] ?>"
                            <?= $filterParentId === (int)$option['id'] ? 'selected' : '' ?>
                        >
                            <?= e(str_repeat('— ', max(0, (int)($option['depth'] ?? 0))) . (string)$option['titulo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<?php /* v0.33.1-search-form-pages */ ?>
<?= adminSearchHtml(
    'admin/paginas/index.php',
    $search,
    [
        'status' => $view !== 'todos' ? $view : null,
        'superior' => $filterParentRaw !== '' ? $filterParentRaw : null,
    ],
    'Pesquisar páginas…',
    $totalItems
) ?>
<form
    id="bulkPagesForm"
    method="post"
    class="card border-0 shadow-sm mb-3"
    onsubmit="return confirmBulkEditorialAction(this);"
>
    <?= Csrf::field() ?>
    <input type="hidden" name="status" value="<?= e($view !== 'todos' ? $view : '') ?>">
    <input type="hidden" name="superior" value="<?= e($filterParentRaw) ?>">
    <input type="hidden" name="q" value="<?= e($search) ?>">

    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <strong class="small me-1">Ações em massa</strong>
            <select class="form-select form-select-sm" name="bulk_action" style="max-width:240px" required>
                <option value="">Selecione uma ação…</option>
                <?php if ($view === 'lixeira'): ?>
                    <option value="restore">Restaurar</option>
                    <option value="delete">Excluir permanentemente</option>
                <?php else: ?>
                    <option value="publish">Publicar</option>
                    <option value="draft">Mover para rascunho</option>
                    <option value="archive">Arquivar</option>
                    <option value="trash">Mover para Lixeira</option>
                <?php endif; ?>
            </select>
            <button class="btn btn-sm btn-primary">Aplicar</button>
            <span class="small text-secondary ms-auto" data-bulk-selection-count>0 selecionado(s)</span>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr>
    <th style="width:42px">
        <input
            class="form-check-input"
            type="checkbox"
            data-bulk-check-all
            aria-label="Selecionar todas as páginas desta página"
        >
    </th>
    <th>Título</th><th>Slug</th><th>Status</th><th>Menu</th><th><?= $view === 'lixeira' ? 'Excluída em' : 'Publicação' ?></th><th></th>
</tr></thead><tbody>
<?php if (!$paginas): ?><tr><td colspan="7" class="text-secondary py-4"><?= $view === 'lixeira' ? 'A Lixeira está vazia.' : 'Nenhuma página cadastrada.' ?></td></tr><?php endif; ?>
<?php foreach ($paginas as $pagina): ?><tr>
<td>
    <input
        class="form-check-input"
        type="checkbox"
        name="ids[]"
        value="<?= (int)$pagina['id'] ?>"
        form="bulkPagesForm"
        data-bulk-item
        aria-label="Selecionar <?= e((string)$pagina['titulo']) ?>"
    >
</td>
<td>
    <div class="fw-semibold"><?= e($pagina['titulo']) ?></div>
    <div class="small text-secondary">
        <?= e($pagina['autor_nome'] ?: '') ?>
        <?php if ((int)$pagina['total_revisoes'] > 0): ?> · <?= (int)$pagina['total_revisoes'] ?> revisões<?php endif; ?>
        <?php if (!empty($pagina['parent_titulo'])): ?> · Subpágina de <?= e((string)$pagina['parent_titulo']) ?><?php endif; ?>
    </div>
</td>
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
<?php /* v0.33.0-pagination-render */ ?>
<?= adminPaginationHtml(
    'admin/paginas/index.php',
    $pagination,
    [
        'status' => $view !== 'todos' ? $view : null,
        'superior' => $filterParentRaw !== '' ? $filterParentRaw : null,
        'q' => $search,
    ]
) ?>

<script>
function updateBulkEditorialSelection() {
    const items = Array.from(document.querySelectorAll('[data-bulk-item]'));
    const selected = items.filter(item => item.checked);
    const count = document.querySelector('[data-bulk-selection-count]');
    const all = document.querySelector('[data-bulk-check-all]');

    if (count) count.textContent = selected.length + ' selecionado(s)';
    if (all) {
        all.checked = items.length > 0 && selected.length === items.length;
        all.indeterminate = selected.length > 0 && selected.length < items.length;
    }
}

document.querySelector('[data-bulk-check-all]')?.addEventListener('change', event => {
    document.querySelectorAll('[data-bulk-item]').forEach(item => {
        item.checked = event.currentTarget.checked;
    });
    updateBulkEditorialSelection();
});

document.querySelectorAll('[data-bulk-item]').forEach(item => {
    item.addEventListener('change', updateBulkEditorialSelection);
});

function confirmBulkEditorialAction(form) {
    const selected = document.querySelectorAll('[data-bulk-item]:checked').length;
    if (selected < 1) {
        alert('Selecione pelo menos um conteúdo.');
        return false;
    }

    const action = form.querySelector('[name="bulk_action"]')?.value || '';
    if (action === 'delete') {
        return confirm(
            'Excluir permanentemente ' + selected
            + ' conteúdo(s)? Esta ação não pode ser desfeita.'
        );
    }

    return true;
}

updateBulkEditorialSelection();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
