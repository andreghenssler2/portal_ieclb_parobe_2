<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../_pagination.php';
require_once __DIR__ . '/../_search.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
ContentBlockService::ensureSchema($pdo);

// v0.46.0 - filtros avançados e ações em massa de Notícias.
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
                'post',
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
  1 => 'categoria',
  2 => 'comunidade',
  3 => 'q',
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
        . url('admin/noticias/index.php' . ($query !== '' ? '?' . $query : ''))
    );
    exit;
}
// v0.45.1 - filtros editoriais de Notícias.
$allowedViews = ['todos', 'publicados', 'agenda', 'rascunhos', 'lixeira'];
$view = strtolower(trim((string)($_GET['status'] ?? 'todos')));
if (!in_array($view, $allowedViews, true)) {
    $view = 'todos';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');

        try {
            if ($id <= 0) {
                throw new RuntimeException('Notícia inválida.');
            }

            $stmt = $pdo->prepare(
                'SELECT id,titulo,status,status_anterior
                 FROM posts
                 WHERE id=:id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $postAction = $stmt->fetch();

            if (!$postAction) {
                throw new RuntimeException('Notícia não encontrada.');
            }

            if ($action === 'trash') {
                if ((string)$postAction['status'] !== 'lixeira') {
                    $pdo->beginTransaction();

                    try {
                        RevisionService::create(
                            $pdo,
                            'post',
                            $id,
                            Auth::id()
                        );

                        $pdo->prepare(
                            "UPDATE posts
                             SET status_anterior=status,
                                 status='lixeira',
                                 lixeira_em=NOW()
                             WHERE id=:id"
                        )->execute(['id' => $id]);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }

                    logAction(
                        $pdo,
                        'post.lixeira',
                        'posts',
                        $id,
                        (string)$postAction['titulo']
                    );
                }

                Session::flash(
                    'success',
                    'Notícia movida para a Lixeira.'
                );
            } elseif ($action === 'restore') {
                if ((string)$postAction['status'] !== 'lixeira') {
                    throw new RuntimeException(
                        'Esta notícia não está na Lixeira.'
                    );
                }

                $allowed = [
                    'rascunho',
                    'agendado',
                    'publicado',
                    'arquivado',
                ];

                $restoreStatus = in_array(
                    (string)$postAction['status_anterior'],
                    $allowed,
                    true
                )
                    ? (string)$postAction['status_anterior']
                    : 'rascunho';

                $pdo->prepare(
                    'UPDATE posts
                     SET status=:status,
                         status_anterior=NULL,
                         lixeira_em=NULL
                     WHERE id=:id'
                )->execute([
                    'status' => $restoreStatus,
                    'id' => $id,
                ]);

                logAction(
                    $pdo,
                    'post.restaurar_lixeira',
                    'posts',
                    $id,
                    (string)$postAction['titulo']
                );

                Session::flash(
                    'success',
                    'Notícia restaurada da Lixeira.'
                );
            } elseif ($action === 'delete') {
                if ((string)$postAction['status'] !== 'lixeira') {
                    throw new RuntimeException(
                        'Só é possível excluir permanentemente uma notícia que esteja na Lixeira.'
                    );
                }

                $pdo->beginTransaction();

                try {
                    RevisionService::deleteForContent(
                        $pdo,
                        'post',
                        $id
                    );
                    ContentBlockService::deleteForContent(
                        $pdo,
                        'post',
                        $id
                    );

                    foreach ([
                        'post_categorias',
                        'post_tags',
                        'comentarios',
                    ] as $relatedTable) {
                        try {
                            $pdo->prepare(
                                'DELETE FROM ' . $relatedTable
                                . ' WHERE post_id=:id'
                            )->execute(['id' => $id]);
                        } catch (Throwable $ignored) {
                        }
                    }

                    $pdo->prepare(
                        'DELETE FROM posts WHERE id=:id'
                    )->execute(['id' => $id]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }

                logAction(
                    $pdo,
                    'post.excluir_permanente',
                    'posts',
                    $id,
                    (string)$postAction['titulo']
                );

                Session::flash(
                    'success',
                    'Notícia excluída permanentemente.'
                );
            } else {
                throw new RuntimeException('Ação inválida.');
            }
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }

    $returnStatus = strtolower(
        trim((string)($_POST['return_status'] ?? 'todos'))
    );

    if (!in_array($returnStatus, $allowedViews, true)) {
        $returnStatus = 'todos';
    }

    $redirect = 'admin/noticias/index.php';
    if ($returnStatus !== 'todos') {
        $redirect .= '?status=' . rawurlencode($returnStatus);
    }

    header('Location: ' . url($redirect));
    exit;
}

$postCounts = [
    'todos' => (int)$pdo->query(
        "SELECT COUNT(*) FROM posts WHERE status <> 'lixeira'"
    )->fetchColumn(),
    'publicados' => (int)$pdo->query(
        "SELECT COUNT(*) FROM posts WHERE status = 'publicado'"
    )->fetchColumn(),
    'agenda' => (int)$pdo->query(
        "SELECT COUNT(*) FROM posts WHERE status = 'agendado'"
    )->fetchColumn(),
    'rascunhos' => (int)$pdo->query(
        "SELECT COUNT(*) FROM posts WHERE status = 'rascunho'"
    )->fetchColumn(),
    'lixeira' => (int)$pdo->query(
        "SELECT COUNT(*) FROM posts WHERE status = 'lixeira'"
    )->fetchColumn(),
];

$statusSql = match ($view) {
    'publicados' => "p.status = 'publicado'",
    'agenda' => "p.status = 'agendado'",
    'rascunhos' => "p.status = 'rascunho'",
    'lixeira' => "p.status = 'lixeira'",
    default => "p.status <> 'lixeira'",
};

$filterCategoryId = max(0, (int)($_GET['categoria'] ?? 0));
$filterCommunityId = max(0, (int)($_GET['comunidade'] ?? 0));

$filterSql = '';
$filterParams = [];

if ($filterCategoryId > 0) {
    $filterSql .= "
        AND EXISTS (
            SELECT 1
            FROM post_categorias pcf
            WHERE pcf.post_id=p.id
              AND pcf.categoria_id=:filter_category
        )";
    $filterParams['filter_category'] = $filterCategoryId;
}

if ($filterCommunityId > 0) {
    $filterSql .= "
        AND p.comunidade_id=:filter_community";
    $filterParams['filter_community'] = $filterCommunityId;
}

$filterCategories = CategoryService::tree($pdo);
$filterCommunities = $pdo->query(
    "SELECT id,nome
     FROM comunidades
     WHERE ativa=1
     ORDER BY ordem ASC,nome ASC"
)->fetchAll() ?: [];

// v0.33.1: pesquisa de posts + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = $filterParams;
if ($search !== '') {
    $searchSql = " AND (
        p.titulo LIKE :post_q1
        OR p.slug LIKE :post_q2
        OR COALESCE(p.resumo,'') LIKE :post_q3
        OR COALESCE(p.conteudo,'') LIKE :post_q4
        OR COALESCE(c.nome,'') LIKE :post_q5
        OR EXISTS (
            SELECT 1
            FROM post_categorias pcq
            INNER JOIN categorias cq ON cq.id=pcq.categoria_id
            WHERE pcq.post_id=p.id
              AND (cq.nome LIKE :post_q6 OR cq.slug LIKE :post_q7)
        )
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 7; $i++) $searchParams['post_q' . $i] = $like;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id
     WHERE {$statusSql}{$filterSql}{$searchSql}"
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);

$listSql = "SELECT p.*, c.nome AS comunidade_nome,
        (SELECT GROUP_CONCAT(cat.nome ORDER BY pc.principal DESC, cat.nome SEPARATOR '||')
         FROM post_categorias pc
         INNER JOIN categorias cat ON cat.id=pc.categoria_id
         WHERE pc.post_id=p.id) AS categorias_nomes
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id
     WHERE {$statusSql}"
     . $filterSql
     . $searchSql
     . " ORDER BY "
     . ($view === 'lixeira'
        ? 'p.lixeira_em DESC,p.id DESC'
        : ($view === 'agenda'
            ? 'p.publicado_em ASC,p.id DESC'
            : 'COALESCE(p.publicado_em, p.created_at) DESC, p.id DESC'))
     . " LIMIT " . (int)$pagination['limit']
     . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$posts = $listStmt->fetchAll();
$pageTitle = 'Notícias';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Notícias</h1>
        <p class="text-secondary mb-0">Conteúdo publicado no portal.</p>
    </div>
    <?php if ($view !== 'lixeira'): ?>
        <a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a>
    <?php endif; ?>
</div>

<ul class="nav nav-pills gap-2 mb-4 flex-wrap">
    <?php
    $postTabs = [
        'todos' => 'Todos',
        'publicados' => 'Publicados',
        'agenda' => 'Agenda',
        'rascunhos' => 'Rascunhos',
        'lixeira' => 'Lixeira',
    ];
    ?>
    <?php foreach ($postTabs as $tabKey => $tabLabel): ?>
        <?php
        $tabUrl = $tabKey === 'todos'
            ? url('admin/noticias/index.php')
            : url('admin/noticias/index.php?status=' . rawurlencode($tabKey));
        $tabActive = $view === $tabKey;
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $tabActive ? 'active' : '' ?>" href="<?= e($tabUrl) ?>">
                <?= e($tabLabel) ?>
                <span class="badge <?= $tabActive ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1">
                    <?= (int)($postCounts[$tabKey] ?? 0) ?>
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

            <div class="col-md-5">
                <label class="form-label small">Categoria</label>
                <select class="form-select" name="categoria">
                    <option value="">Todas as categorias</option>
                    <?php foreach ($filterCategories as $category): ?>
                        <option
                            value="<?= (int)$category['id'] ?>"
                            <?= $filterCategoryId === (int)$category['id'] ? 'selected' : '' ?>
                        >
                            <?= e(str_repeat('— ', max(0, (int)($category['depth'] ?? 0))) . (string)$category['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label small">Comunidade</label>
                <select class="form-select" name="comunidade">
                    <option value="">Todas as comunidades</option>
                    <?php foreach ($filterCommunities as $community): ?>
                        <option
                            value="<?= (int)$community['id'] ?>"
                            <?= $filterCommunityId === (int)$community['id'] ? 'selected' : '' ?>
                        ><?= e((string)$community['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<?php /* v0.33.1-search-form-posts */ ?>
<?= adminSearchHtml(
    'admin/noticias/index.php',
    $search,
    [
        'status' => $view !== 'todos' ? $view : null,
        'categoria' => $filterCategoryId ?: null,
        'comunidade' => $filterCommunityId ?: null,
    ],
    'Pesquisar notícias…',
    $totalItems
) ?>
<form
    id="bulkPostsForm"
    method="post"
    class="card border-0 shadow-sm mb-3"
    onsubmit="return confirmBulkEditorialAction(this);"
>
    <?= Csrf::field() ?>
    <input type="hidden" name="status" value="<?= e($view !== 'todos' ? $view : '') ?>">
    <input type="hidden" name="categoria" value="<?= $filterCategoryId ?: '' ?>">
    <input type="hidden" name="comunidade" value="<?= $filterCommunityId ?: '' ?>">
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
            aria-label="Selecionar todas as notícias desta página"
        >
    </th>
    <th>Título</th><th>Comunidade</th><th>Categoria</th><th>Status</th><th>Publicação</th><th>Visualizações</th><th></th>
</tr></thead><tbody>
<?php if (!$posts): ?><tr><td colspan="8" class="text-secondary">Nenhuma notícia cadastrada.</td></tr><?php endif; ?>
<?php foreach ($posts as $post): ?><tr>
<td>
    <input
        class="form-check-input"
        type="checkbox"
        name="ids[]"
        value="<?= (int)$post['id'] ?>"
        form="bulkPostsForm"
        data-bulk-item
        aria-label="Selecionar <?= e((string)$post['titulo']) ?>"
    >
</td>
<td class="fw-semibold"><?= e($post['titulo']) ?></td>
<td><?= e($post['comunidade_nome'] ?: 'Paroquial') ?></td>
<td>
    <?php $cats = array_filter(explode('||', (string)($post['categorias_nomes'] ?? ''))); ?>
    <?php if ($cats): ?>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach ($cats as $cat): ?>
                <span class="badge text-bg-light border"><?= e($cat) ?></span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>-<?php endif; ?>
</td>
<td>
    <span class="badge text-bg-secondary"><?= e($post['status']) ?></span>
    <?php if ($view === 'lixeira' && !empty($post['status_anterior'])): ?>
        <div class="small text-secondary mt-1">era: <?= e((string)$post['status_anterior']) ?></div>
    <?php endif; ?>
</td>
<td><?= e(formatDateBr($view === 'lixeira' ? ($post['lixeira_em'] ?? null) : $post['publicado_em'])) ?></td>
<td><?= number_format((int)($post['visualizacoes'] ?? 0), 0, ',', '.') ?></td>
<td class="text-end">
    <div class="d-flex flex-wrap gap-1 justify-content-end">
        <?php if ($view === 'lixeira'): ?>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="return_status" value="lixeira">
                <button class="btn btn-sm btn-outline-success">Restaurar</button>
            </form>
            <form
                method="post"
                onsubmit="return confirm('Excluir esta notícia permanentemente? Esta ação não pode ser desfeita.');"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="return_status" value="lixeira">
                <button class="btn btn-sm btn-outline-danger">Excluir permanentemente</button>
            </form>
        <?php else: ?>
            <?php if ($post['status'] === 'publicado' && !empty($post['slug'])): ?>
                <a
                    class="btn btn-sm btn-outline-primary"
                    target="_blank"
                    href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>"
                >Ver</a>
            <?php endif; ?>

            <a
                class="btn btn-sm btn-outline-secondary"
                href="<?= e(url('admin/noticias/form.php?id=' . (int)$post['id'])) ?>"
            >Editar</a>

            <form
                method="post"
                onsubmit="return confirm('Mover esta notícia para a Lixeira?');"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="action" value="trash">
                <input type="hidden" name="return_status" value="<?= e($view) ?>">
                <button class="btn btn-sm btn-outline-danger">Lixeira</button>
            </form>
        <?php endif; ?>
    </div>
</td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php /* v0.33.0-pagination-render */ ?>
<?= adminPaginationHtml(
    'admin/noticias/index.php',
    $pagination,
    [
        'status' => $view !== 'todos' ? $view : null,
        'categoria' => $filterCategoryId ?: null,
        'comunidade' => $filterCommunityId ?: null,
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
