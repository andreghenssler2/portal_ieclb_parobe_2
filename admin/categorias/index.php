<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../_pagination.php';
require_once __DIR__ . '/../_search.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';

Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
$error = '';
$editId = (int)($_GET['editar'] ?? 0);
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Categoria inválida.';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT nome FROM categorias WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $id]);
                    $categoria = $stmt->fetch();
                    if (!$categoria) {
                        throw new RuntimeException('Categoria não encontrada.');
                    }

                    $affectedStmt = $pdo->prepare('SELECT id FROM posts WHERE categoria_id = :id');
                    $affectedStmt->execute(['id' => $id]);
                    $affectedPostIds = array_map('intval', $affectedStmt->fetchAll(PDO::FETCH_COLUMN));

                    $stmt = $pdo->prepare('DELETE FROM categorias WHERE id = :id');
                    $stmt->execute(['id' => $id]);

                    if ($affectedPostIds) {
                        $nextCategory = $pdo->prepare('SELECT categoria_id FROM post_categorias WHERE post_id = :post_id ORDER BY categoria_id LIMIT 1');
                        $updatePost = $pdo->prepare('UPDATE posts SET categoria_id = :categoria_id WHERE id = :post_id');
                        $clearPrimary = $pdo->prepare('UPDATE post_categorias SET principal = 0 WHERE post_id = :post_id');
                        $setPrimary = $pdo->prepare('UPDATE post_categorias SET principal = 1 WHERE post_id = :post_id AND categoria_id = :categoria_id');
                        foreach ($affectedPostIds as $postId) {
                            $nextCategory->execute(['post_id' => $postId]);
                            $nextId = (int)($nextCategory->fetchColumn() ?: 0);
                            $updatePost->bindValue(':categoria_id', $nextId > 0 ? $nextId : null, $nextId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
                            $updatePost->bindValue(':post_id', $postId, PDO::PARAM_INT);
                            $updatePost->execute();
                            if ($nextId > 0) {
                                $clearPrimary->execute(['post_id' => $postId]);
                                $setPrimary->execute(['post_id' => $postId, 'categoria_id' => $nextId]);
                            }
                        }
                    }

                    logAction($pdo, 'categoria.excluir', 'categorias', $id, (string)$categoria['nome']);
                    Session::flash(
                        'success',
                        'Categoria excluída. As notícias continuam vinculadas às demais categorias selecionadas; as subcategorias passaram para o nível principal.'
                    );
                    header('Location: ' . url('admin/categorias/index.php'));
                    exit;
                } catch (Throwable $e) {
                    $error = 'Não foi possível excluir a categoria: ' . $e->getMessage();
                }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $slugInformada = trim((string)($_POST['slug'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $parentRaw = trim((string)($_POST['parent_id'] ?? ''));
            $parentId = $parentRaw !== '' ? (int)$parentRaw : null;

            if ($nome === '') {
                $error = 'Informe o nome da categoria.';
            } else {
                try {
                    $parentId = CategoryService::validateParent($pdo, $parentId, $id > 0 ? $id : null);
                    $slugBase = $slugInformada !== '' ? $slugInformada : $nome;
                    $slug = uniqueSlug($pdo, 'categorias', $slugBase, $id > 0 ? $id : null);

                    if ($id > 0) {
                        $stmt = $pdo->prepare(
                            'UPDATE categorias
                             SET nome = :nome,
                                 slug = :slug,
                                 descricao = :descricao,
                                 parent_id = :parent_id
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'nome' => $nome,
                            'slug' => $slug,
                            'descricao' => $descricao !== '' ? $descricao : null,
                            'parent_id' => $parentId,
                            'id' => $id,
                        ]);
                        logAction($pdo, 'categoria.editar', 'categorias', $id, $nome);
                        Session::flash('success', 'Categoria atualizada com sucesso.');
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO categorias (nome, slug, descricao, parent_id)
                             VALUES (:nome, :slug, :descricao, :parent_id)'
                        );
                        $stmt->execute([
                            'nome' => $nome,
                            'slug' => $slug,
                            'descricao' => $descricao !== '' ? $descricao : null,
                            'parent_id' => $parentId,
                        ]);
                        $id = (int)$pdo->lastInsertId();
                        logAction($pdo, 'categoria.criar', 'categorias', $id, $nome);
                        Session::flash('success', 'Categoria criada com sucesso.');
                    }

                    header('Location: ' . url('admin/categorias/index.php'));
                    exit;
                } catch (Throwable $e) {
                    $error = 'Não foi possível salvar a categoria: ' . $e->getMessage();
                }
            }
        }
    }
}

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT id, nome, slug, descricao, parent_id FROM categorias WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit && $error === '') {
        $error = 'Categoria não encontrada.';
    }
}

$categorias = CategoryService::tree($pdo);
// v0.33.1: pesquisa de categorias + paginação de 50 itens na tabela.
$search = adminSearchTerm();
$categoriasFiltradas = $search === ''
    ? $categorias
    : array_values(array_filter($categorias, static function (array $categoria) use ($search): bool {
        return adminSearchMatches(
            $search,
            $categoria['nome'] ?? '',
            $categoria['slug'] ?? '',
            $categoria['descricao'] ?? '',
            CategoryService::optionLabel($categoria)
        );
    }));
$pagination = adminPaginationState(count($categoriasFiltradas), 50);
$categoriasPagina = array_slice($categoriasFiltradas, $pagination['offset'], $pagination['limit']);
$countRows = $pdo->query(
    "SELECT c.id, COUNT(DISTINCT p.id) AS total_posts
     FROM categorias c
     LEFT JOIN post_categorias pc ON pc.categoria_id = c.id
     LEFT JOIN posts p ON p.id = pc.post_id AND p.status <> 'lixeira'
     GROUP BY c.id"
)->fetchAll();
$totalPosts = [];
foreach ($countRows as $row) {
    $totalPosts[(int)$row['id']] = (int)$row['total_posts'];
}

$blockedParentIds = [];
if ($editId > 0) {
    $blockedParentIds = CategoryService::descendantIds($pdo, $editId);
    $blockedParentIds[] = $editId;
}

$pageTitle = 'Categorias de Posts';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Categorias de Posts</h1>
        <p class="text-secondary mb-0">Organize as notícias em categorias e subcategorias.</p>
    </div>
    <?php if ($edit): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/categorias/index.php')) ?>">Cancelar edição</a>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php /* v0.33.1-search-form-categories */ ?>
<?= adminSearchHtml('admin/categorias/index.php', $search, [], 'Pesquisar categorias…', (int)$pagination['total']) ?>
<div class="row g-4">
    <div class="col-xl-4">
        <form method="post" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold py-3"><?= $edit ? 'Editar categoria' : 'Adicionar categoria' ?></div>
            <div class="card-body p-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                <div class="mb-3">
                    <label class="form-label">Nome</label>
                    <input class="form-control" name="nome" value="<?= e($edit['nome'] ?? '') ?>" maxlength="100" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Slug</label>
                    <input class="form-control" name="slug" value="<?= e($edit['slug'] ?? '') ?>" maxlength="120" placeholder="gerada-automaticamente">
                    <div class="form-text">Se deixar vazio, será gerada a partir do nome.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Categoria ascendente</label>
                    <select class="form-select" name="parent_id">
                        <option value="">Nenhuma</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <?php if (in_array((int)$categoria['id'], $blockedParentIds, true)) continue; ?>
                            <option value="<?= (int)$categoria['id'] ?>" <?= (string)($edit['parent_id'] ?? '') === (string)$categoria['id'] ? 'selected' : '' ?>>
                                <?= e(CategoryService::optionLabel($categoria)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Escolha outra categoria para criar uma subcategoria. Ex.: Eventos → Eventos 1.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" name="descricao" rows="4" maxlength="255"><?= e($edit['descricao'] ?? '') ?></textarea>
                </div>

                <button class="btn btn-primary w-100"><?= $edit ? 'Salvar alterações' : 'Adicionar categoria' ?></button>
            </div>
        </form>
    </div>

    <div class="col-xl-8">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Slug</th>
                        <th class="text-center">Posts</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$categoriasPagina): ?>
                        <tr><td colspan="4" class="text-secondary">Nenhuma categoria cadastrada.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($categoriasPagina as $categoria): ?>
                        <?php $depth = max(0, (int)($categoria['depth'] ?? 0)); ?>
                        <tr>
                            <td>
                                <div class="fw-semibold" style="padding-left: <?= $depth * 22 ?>px">
                                    <?php if ($depth > 0): ?><span class="text-secondary me-1">↳</span><?php endif; ?>
                                    <?= e($categoria['nome']) ?>
                                </div>
                                <?php if ($categoria['descricao']): ?>
                                    <div class="small text-secondary" style="padding-left: <?= $depth * 22 ?>px"><?= e($categoria['descricao']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($categoria['slug']) ?></code></td>
                            <td class="text-center"><?= (int)($totalPosts[(int)$categoria['id']] ?? 0) ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/categorias/index.php?editar=' . (int)$categoria['id'])) ?>">Editar</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta categoria? Ela será removida das notícias; as demais categorias continuarão vinculadas. As subcategorias passarão ao nível principal.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$categoria['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php /* v0.33.0-pagination-render */ ?>
<?= adminPaginationHtml('admin/categorias/index.php', $pagination, ['q' => $search]) ?>
<?php require __DIR__ . '/../_footer.php'; ?>
