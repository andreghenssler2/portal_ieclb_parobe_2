<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('menus.gerenciar');
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_menu') {
                $nome = trim((string)($_POST['nome'] ?? ''));
                $localizacao = slugify((string)($_POST['localizacao'] ?? ''));
                if ($nome === '' || $localizacao === '') {
                    throw new RuntimeException('Informe o nome e a localização do menu.');
                }
                $slug = uniqueSlug($pdo, 'menus', $nome);
                $stmt = $pdo->prepare('INSERT INTO menus (nome, slug, localizacao, ativo) VALUES (:nome, :slug, :localizacao, 1)');
                $stmt->execute(compact('nome', 'slug', 'localizacao'));
                $menuId = (int)$pdo->lastInsertId();
                logAction($pdo, 'menu.criar', 'menus', $menuId, $nome);
                Session::flash('success', 'Menu criado com sucesso.');
                header('Location: ' . url('admin/menus/index.php?menu=' . $menuId));
                exit;
            }

            if ($action === 'delete_item') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Item de menu inválido.');
                }
                $stmt = $pdo->prepare('DELETE FROM menu_itens WHERE id = :id');
                $stmt->execute(['id' => $id]);
                logAction($pdo, 'menu_item.excluir', 'menu_itens', $id);
                Session::flash('success', 'Item removido do menu.');
                header('Location: ' . url('admin/menus/index.php?menu=' . (int)($_POST['menu_id'] ?? 0)));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$menus = $pdo->query('SELECT * FROM menus ORDER BY nome')->fetchAll();
$selectedMenuId = isset($_GET['menu']) ? (int)$_GET['menu'] : (int)($menus[0]['id'] ?? 0);
$selectedMenu = null;
foreach ($menus as $menu) {
    if ((int)$menu['id'] === $selectedMenuId) {
        $selectedMenu = $menu;
        break;
    }
}
if (!$selectedMenu && $menus) {
    $selectedMenu = $menus[0];
    $selectedMenuId = (int)$selectedMenu['id'];
}

$items = [];
if ($selectedMenuId > 0) {
    $stmt = $pdo->prepare(
        "SELECT mi.*, p.titulo AS pagina_titulo, p.slug AS pagina_slug, pai.titulo AS parent_titulo
         FROM menu_itens mi
         LEFT JOIN paginas p ON p.id = mi.pagina_id
         LEFT JOIN menu_itens pai ON pai.id = mi.parent_id
         WHERE mi.menu_id = :menu_id
         ORDER BY COALESCE(mi.parent_id, mi.id), mi.parent_id IS NOT NULL, mi.ordem, mi.id"
    );
    $stmt->execute(['menu_id' => $selectedMenuId]);
    $items = $stmt->fetchAll();
}

$pageTitle = 'Menus';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Menus</h1>
        <p class="text-secondary mb-0">Controle os links e páginas exibidos na navegação pública.</p>
    </div>
    <?php if ($selectedMenuId > 0): ?>
        <a class="btn btn-primary" href="<?= e(url('admin/menus/form.php?menu=' . $selectedMenuId)) ?>">Adicionar item</a>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-3">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Menus cadastrados</div>
            <div class="list-group list-group-flush">
                <?php if (!$menus): ?><div class="list-group-item text-secondary">Nenhum menu cadastrado.</div><?php endif; ?>
                <?php foreach ($menus as $menu): ?>
                    <a class="list-group-item list-group-item-action <?= (int)$menu['id'] === $selectedMenuId ? 'active' : '' ?>" href="<?= e(url('admin/menus/index.php?menu=' . (int)$menu['id'])) ?>">
                        <div class="fw-semibold"><?= e($menu['nome']) ?></div>
                        <small class="<?= (int)$menu['id'] === $selectedMenuId ? 'text-white-50' : 'text-secondary' ?>">Local: <?= e($menu['localizacao']) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Novo menu</div>
            <div class="card-body">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="create_menu">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input class="form-control" name="nome" placeholder="Ex.: Rodapé" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Localização</label>
                        <input class="form-control" name="localizacao" placeholder="Ex.: rodape" required>
                        <div class="form-text">O tema atual utiliza automaticamente a localização <strong>principal</strong>.</div>
                    </div>
                    <button class="btn btn-outline-primary w-100">Criar menu</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-semibold"><?= e($selectedMenu['nome'] ?? 'Itens do menu') ?></span>
                    <?php if ($selectedMenu): ?><span class="text-secondary small ms-2">(<?= e($selectedMenu['localizacao']) ?>)</span><?php endif; ?>
                </div>
                <?php if ($selectedMenuId > 0): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/menus/form.php?menu=' . $selectedMenuId)) ?>">Novo item</a><?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th style="width:80px">Ordem</th><th>Título</th><th>Destino</th><th>Estado</th><th style="width:180px"></th></tr></thead>
                    <tbody>
                    <?php if (!$items): ?><tr><td colspan="5" class="text-secondary p-4">Este menu ainda não possui itens.</td></tr><?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= (int)$item['ordem'] ?></td>
                            <td>
                                <?php if ($item['parent_id']): ?><span class="text-secondary me-1">↳</span><?php endif; ?>
                                <strong><?= e($item['titulo']) ?></strong>
                                <?php if ($item['parent_titulo']): ?><div class="small text-secondary">Subitem de <?= e($item['parent_titulo']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['tipo'] === 'pagina'): ?>
                                    <span class="badge text-bg-light border">Página</span> <?= e($item['pagina_titulo'] ?: 'Página removida') ?>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">Link</span> <span class="small text-secondary"><?= e($item['url'] ?: '/') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$item['ativo'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/menus/form.php?id=' . (int)$item['id'])) ?>">Editar</a>
                                    <form method="post" onsubmit="return confirm('Remover este item do menu?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="menu_id" value="<?= $selectedMenuId ?>">
                                        <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                </div>
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
