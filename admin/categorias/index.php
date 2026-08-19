<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
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

                    $stmt = $pdo->prepare('DELETE FROM categorias WHERE id = :id');
                    $stmt->execute(['id' => $id]);
                    logAction($pdo, 'categoria.excluir', 'categorias', $id, (string)$categoria['nome']);
                    Session::flash('success', 'Categoria excluída. As notícias vinculadas ficaram sem categoria.');
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

            if ($nome === '') {
                $error = 'Informe o nome da categoria.';
            } else {
                try {
                    $slugBase = $slugInformada !== '' ? $slugInformada : $nome;
                    $slug = uniqueSlug($pdo, 'categorias', $slugBase, $id > 0 ? $id : null);

                    if ($id > 0) {
                        $stmt = $pdo->prepare(
                            'UPDATE categorias
                             SET nome = :nome, slug = :slug, descricao = :descricao
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'nome' => $nome,
                            'slug' => $slug,
                            'descricao' => $descricao !== '' ? $descricao : null,
                            'id' => $id,
                        ]);
                        logAction($pdo, 'categoria.editar', 'categorias', $id, $nome);
                        Session::flash('success', 'Categoria atualizada com sucesso.');
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO categorias (nome, slug, descricao)
                             VALUES (:nome, :slug, :descricao)'
                        );
                        $stmt->execute([
                            'nome' => $nome,
                            'slug' => $slug,
                            'descricao' => $descricao !== '' ? $descricao : null,
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
    $stmt = $pdo->prepare('SELECT id, nome, slug, descricao FROM categorias WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit && $error === '') {
        $error = 'Categoria não encontrada.';
    }
}

$categorias = $pdo->query(
    "SELECT c.id, c.nome, c.slug, c.descricao, c.created_at, COUNT(p.id) AS total_posts
     FROM categorias c
     LEFT JOIN posts p ON p.categoria_id = c.id AND p.status <> 'lixeira'
     GROUP BY c.id, c.nome, c.slug, c.descricao, c.created_at
     ORDER BY c.nome ASC"
)->fetchAll();

$pageTitle = 'Categorias de Posts';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Categorias de Posts</h1>
        <p class="text-secondary mb-0">Organize as notícias publicadas no portal.</p>
    </div>
    <?php if ($edit): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/categorias/index.php')) ?>">Cancelar edição</a>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

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
                    <?php if (!$categorias): ?>
                        <tr><td colspan="4" class="text-secondary">Nenhuma categoria cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categorias as $categoria): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($categoria['nome']) ?></div>
                                <?php if ($categoria['descricao']): ?><div class="small text-secondary"><?= e($categoria['descricao']) ?></div><?php endif; ?>
                            </td>
                            <td><code><?= e($categoria['slug']) ?></code></td>
                            <td class="text-center"><?= (int)$categoria['total_posts'] ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/categorias/index.php?editar=' . (int)$categoria['id'])) ?>">Editar</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta categoria? As notícias vinculadas ficarão sem categoria.');">
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
<?php require __DIR__ . '/../_footer.php'; ?>
