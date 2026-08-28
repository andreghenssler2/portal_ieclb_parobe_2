<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('menus.gerenciar');
$pdo = Database::connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$menuId = isset($_GET['menu']) ? (int)$_GET['menu'] : 0;
$item = [
    'menu_id' => $menuId,
    'parent_id' => '',
    'pagina_id' => '',
    'tipo' => 'link',
    'titulo' => '',
    'url' => '',
    'nova_aba' => 0,
    'ativo' => 1,
    'ordem' => 0,
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM menu_itens WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Item de menu não encontrado.');
    }
    $item = $found;
    $menuId = (int)$item['menu_id'];
}

$menus = $pdo->query('SELECT id, nome, localizacao FROM menus WHERE ativo = 1 ORDER BY nome')->fetchAll();
if ($menuId <= 0 && $menus) {
    $menuId = (int)$menus[0]['id'];
    $item['menu_id'] = $menuId;
}

$paginas = $pdo->query("SELECT id, titulo, slug, status FROM paginas ORDER BY titulo")->fetchAll();
$stmt = $pdo->prepare('SELECT id, titulo FROM menu_itens WHERE menu_id = :menu_id AND parent_id IS NULL ORDER BY ordem, titulo');
$stmt->execute(['menu_id' => $menuId]);
$parents = $stmt->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['menu_id','parent_id','pagina_id','tipo','titulo','url','ordem'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $item[$field] = $_POST[$field];
        }
    }
    $item['nova_aba'] = isset($_POST['nova_aba']) ? 1 : 0;
    $item['ativo'] = isset($_POST['ativo']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $menuId = (int)($_POST['menu_id'] ?? 0);
            $tipo = (string)($_POST['tipo'] ?? 'link');
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $urlValue = trim((string)($_POST['url'] ?? ''));
            $paginaId = ($_POST['pagina_id'] ?? '') !== '' ? (int)$_POST['pagina_id'] : null;
            $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
            $ordem = (int)($_POST['ordem'] ?? 0);
            $novaAba = isset($_POST['nova_aba']) ? 1 : 0;
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if ($menuId <= 0 || $titulo === '' || !in_array($tipo, ['link','pagina'], true)) {
                throw new RuntimeException('Menu, título e tipo são obrigatórios.');
            }
            if ($tipo === 'pagina') {
                if (!$paginaId) {
                    throw new RuntimeException('Selecione a página de destino.');
                }
                $urlValue = '';
            } elseif ($urlValue === '') {
                $urlValue = '/';
                $paginaId = null;
            } else {
                $paginaId = null;
            }

            if ($id && $parentId === $id) {
                throw new RuntimeException('Um item não pode ser subitem dele mesmo.');
            }
            if ($parentId !== null) {
                $parentCheck = $pdo->prepare('SELECT COUNT(*) FROM menu_itens WHERE id = :id AND menu_id = :menu_id AND parent_id IS NULL');
                $parentCheck->execute(['id' => $parentId, 'menu_id' => $menuId]);
                if ((int)$parentCheck->fetchColumn() === 0) {
                    throw new RuntimeException('O item pai selecionado é inválido.');
                }
            }

            $data = [
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'pagina_id' => $paginaId,
                'tipo' => $tipo,
                'titulo' => $titulo,
                'url' => $urlValue !== '' ? $urlValue : null,
                'nova_aba' => $novaAba,
                'ativo' => $ativo,
                'ordem' => $ordem,
            ];

            if ($id) {
                $data['id'] = $id;
                $stmt = $pdo->prepare(
                    'UPDATE menu_itens SET menu_id=:menu_id,parent_id=:parent_id,pagina_id=:pagina_id,tipo=:tipo,titulo=:titulo,url=:url,nova_aba=:nova_aba,ativo=:ativo,ordem=:ordem WHERE id=:id'
                );
                $stmt->execute($data);
                logAction($pdo, 'menu_item.editar', 'menu_itens', $id, $titulo);
                Session::flash('success', 'Item de menu atualizado.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO menu_itens (menu_id,parent_id,pagina_id,tipo,titulo,url,nova_aba,ativo,ordem) VALUES (:menu_id,:parent_id,:pagina_id,:tipo,:titulo,:url,:nova_aba,:ativo,:ordem)'
                );
                $stmt->execute($data);
                $id = (int)$pdo->lastInsertId();
                logAction($pdo, 'menu_item.criar', 'menu_itens', $id, $titulo);
                Session::flash('success', 'Item adicionado ao menu.');
            }

            header('Location: ' . url('admin/menus/index.php?menu=' . $menuId));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = $id ? 'Editar item de menu' : 'Novo item de menu';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $id ? 'Editar item' : 'Novo item' ?></h1>
        <p class="text-secondary mb-0">Adicione páginas ou links à navegação pública.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/menus/index.php?menu=' . $menuId)) ?>">Voltar</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="post">
            <?= Csrf::field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Menu</label>
                    <select class="form-select" name="menu_id" id="menuId" required>
                        <?php foreach ($menus as $menu): ?>
                            <option value="<?= (int)$menu['id'] ?>" <?= $menuId === (int)$menu['id'] ? 'selected' : '' ?>><?= e($menu['nome']) ?> (<?= e($menu['localizacao']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="tipo" id="tipo" required>
                        <option value="link" <?= $item['tipo'] === 'link' ? 'selected' : '' ?>>Link</option>
                        <option value="pagina" <?= $item['tipo'] === 'pagina' ? 'selected' : '' ?>>Página do portal</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Título exibido</label>
                    <input class="form-control" name="titulo" value="<?= e((string)$item['titulo']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ordem</label>
                    <input class="form-control" type="number" name="ordem" value="<?= (int)$item['ordem'] ?>">
                </div>

                <div class="col-12" id="linkField">
                    <label class="form-label">URL</label>
                    <input class="form-control" name="url" value="<?= e((string)($item['url'] ?? '')) ?>" placeholder="agenda.php, / ou https://...">
                    <div class="form-text">Para links internos, informe o caminho relativo. Para sites externos, use a URL completa.</div>
                </div>

                <div class="col-12" id="pageField">
                    <label class="form-label">Página</label>
                    <select class="form-select" name="pagina_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($paginas as $pagina): ?>
                            <option value="<?= (int)$pagina['id'] ?>" <?= (string)($item['pagina_id'] ?? '') === (string)$pagina['id'] ? 'selected' : '' ?>><?= e($pagina['titulo']) ?> · <?= e($pagina['status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Subitem de</label>
                    <select class="form-select" name="parent_id">
                        <option value="">Item principal</option>
                        <?php foreach ($parents as $parent): ?>
                            <?php if (!$id || (int)$parent['id'] !== $id): ?>
                                <option value="<?= (int)$parent['id'] ?>" <?= (string)($item['parent_id'] ?? '') === (string)$parent['id'] ? 'selected' : '' ?>><?= e($parent['titulo']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">O tema atual aceita um nível de dropdown.</div>
                </div>

                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ativo" id="ativo" <?= (int)$item['ativo'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativo">Item ativo</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="nova_aba" id="novaAba" <?= (int)$item['nova_aba'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="novaAba">Abrir em nova aba</label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-primary px-4">Salvar item</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('admin/menus/index.php?menu=' . $menuId)) ?>">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<script>
function toggleDestinationFields() {
    const isPage = document.getElementById('tipo').value === 'pagina';
    document.getElementById('pageField').style.display = isPage ? '' : 'none';
    document.getElementById('linkField').style.display = isPage ? 'none' : '';
}
document.getElementById('tipo').addEventListener('change', toggleDestinationFields);
toggleDestinationFields();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
