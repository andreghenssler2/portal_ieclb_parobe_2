<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('home.gerenciar');
$pdo = Database::connection();
$user = Auth::user();
$userId = (int)($user['id'] ?? 0);
$service = new HomeService($pdo);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');
            if ($action === 'save') {
                $id = $service->saveSection($_POST, $userId);
                if (function_exists('logAction')) {
                    logAction($pdo, 'home.secao.salvar', 'home_secoes', $id, 'Seção: ' . trim((string)($_POST['titulo'] ?? '')));
                }
                header('Location: ' . url('admin/aparencia/home.php?ok=saved'));
                exit;
            }
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $service->deleteSection($id);
                if (function_exists('logAction')) logAction($pdo, 'home.secao.excluir', 'home_secoes', $id, 'Seção removida da página inicial.');
                header('Location: ' . url('admin/aparencia/home.php?ok=deleted'));
                exit;
            }
            if ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $service->toggleSection($id);
                if (function_exists('logAction')) logAction($pdo, 'home.secao.ativar', 'home_secoes', $id, 'Status da seção alterado.');
                header('Location: ' . url('admin/aparencia/home.php?ok=toggled'));
                exit;
            }
            if ($action === 'reorder') {
                $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ordem_ids'] ?? ''))));
                $service->reorder($ids);
                if (function_exists('logAction')) logAction($pdo, 'home.secao.ordenar', 'home_secoes', null, 'Ordem das seções atualizada.');
                header('Location: ' . url('admin/aparencia/home.php?ok=reordered'));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$messages = [
    'saved' => 'Seção salva com sucesso.',
    'deleted' => 'Seção removida.',
    'toggled' => 'Visibilidade da seção atualizada.',
    'reordered' => 'Ordem da página inicial atualizada.',
];
$ok = (string)($_GET['ok'] ?? '');
if (isset($messages[$ok])) $success = $messages[$ok];

$editId = max(0, (int)($_GET['editar'] ?? 0));
$editing = $editId > 0 ? $service->section($editId) : null;
$editConfig = $editing ? $service->config($editing) : [];
$sections = $service->sections(false);
$categories = $service->categories();
$activeSections = array_values(array_filter($sections, static fn($section) => (int)($section['ativo'] ?? 0) === 1));

$form = [
    'id' => (int)($editing['id'] ?? 0),
    'titulo' => (string)($editing['titulo'] ?? ''),
    'tipo' => (string)($editing['tipo'] ?? 'carousel'),
    'origem' => (string)($editing['origem'] ?? 'posts'),
    'categoria_id' => (int)($editing['categoria_id'] ?? 0),
    'link_texto' => (string)($editing['link_texto'] ?? ''),
    'link_url' => (string)($editing['link_url'] ?? ''),
    'limite' => (int)($editing['limite'] ?? 8),
    'ativo' => $editing ? (bool)$editing['ativo'] : true,
    'show_date' => $editing ? (bool)($editConfig['show_date'] ?? false) : true,
    'show_excerpt' => $editing ? (bool)($editConfig['show_excerpt'] ?? false) : false,
    'autoplay' => $editing ? (bool)($editConfig['autoplay'] ?? false) : false,
    'date_position' => (string)($editConfig['date_position'] ?? 'after'),
    'background' => (string)($editConfig['background'] ?? 'white'),
];

$pageTitle = 'Página Inicial';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Página Inicial</h1>
        <p class="text-secondary mb-0">Monte a home por blocos. Ative, remova, edite e altere a ordem sem mexer no código.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url()) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver página inicial</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= e($success) ?></div><?php endif; ?>
<?php if ($sections && !$activeSections): ?><div class="alert alert-warning"><i class="bi bi-eye-slash me-2"></i>Nenhuma seção está ativa. Clique em <strong>Ativar</strong> em pelo menos uma seção para que ela apareça no portal.</div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">Seções da home</div>
                    <div class="text-secondary small">Arraste pelo ícone para alterar a ordem e clique em “Salvar ordem”.</div>
                </div>
                <span class="badge text-bg-light border"><?= count($sections) ?> seção(ões)</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$sections): ?>
                    <div class="text-center text-secondary p-5">Nenhuma seção cadastrada. Crie a primeira ao lado.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush" id="homeSectionList">
                    <?php foreach ($sections as $section): ?>
                        <?php
                        $cfg = $service->config($section);
                        $sectionItemsFound = count($service->itemsForSection($section));
                        $sectionHasNoItems = (int)$section['ativo'] === 1 && $sectionItemsFound === 0;
                        ?>
                        <div class="list-group-item py-3 home-section-row" data-id="<?= (int)$section['id'] ?>">
                            <div class="d-flex align-items-start gap-3">
                                <button type="button" class="btn btn-sm btn-light border home-drag-handle" title="Arrastar"><i class="bi bi-grip-vertical"></i></button>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <strong><?= e((string)$section['titulo']) ?></strong>
                                        <span class="badge text-bg-<?= (int)$section['ativo'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$section['ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span>
                                        <span class="badge text-bg-light border"><?= e(match ((string)$section['tipo']) { 'featured' => 'Destaque + 2', 'grid' => 'Grade', default => 'Carrossel' }) ?></span>
                                    </div>
                                    <div class="small text-secondary">
                                        Fonte: <?= e(match ((string)$section['origem']) { 'eventos' => 'Eventos', 'paginas' => 'Páginas', default => 'Posts / Notícias' }) ?>
                                        · limite <?= (int)$section['limite'] ?>
                                        <?php if (!empty($section['categoria_id'])): ?> · categoria #<?= (int)$section['categoria_id'] ?><?php endif; ?>
                                        · <span class="<?= $sectionHasNoItems ? 'text-danger fw-semibold' : 'text-success' ?>"><?= $sectionItemsFound ?> encontrado(s)</span>
                                    </div>
                                    <?php if ($sectionHasNoItems): ?>
                                        <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>A seção está ativa, mas nenhum conteúdo corresponde ao filtro.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/aparencia/home.php?editar=' . (int)$section['id'])) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$section['id'] ?>"><button class="btn btn-sm <?= (int)$section['ativo'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>" title="<?= (int)$section['ativo'] === 1 ? 'Desativar seção' : 'Ativar seção' ?>"><i class="bi bi-<?= (int)$section['ativo'] === 1 ? 'eye-slash' : 'eye' ?> me-1"></i><?= (int)$section['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button></form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remover esta seção da página inicial?');"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$section['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <form method="post" class="p-3 border-top" id="reorderForm">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="ordem_ids" id="orderIds" value="<?= e(implode(',', array_map(static fn($s) => (int)$s['id'], $sections))) ?>">
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-arrow-down-up me-1"></i>Salvar ordem</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm sticky-xl-top" style="top:84px">
            <div class="card-header bg-white fw-semibold"><?= $editing ? 'Editar seção' : 'Adicionar seção' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input class="form-control" name="titulo" value="<?= e($form['titulo']) ?>" placeholder="Ex.: Últimas Notícias" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Layout</label>
                            <select class="form-select" name="tipo">
                                <option value="featured" <?= $form['tipo']==='featured'?'selected':'' ?>>Destaque + 2</option>
                                <option value="carousel" <?= $form['tipo']==='carousel'?'selected':'' ?>>Carrossel</option>
                                <option value="grid" <?= $form['tipo']==='grid'?'selected':'' ?>>Grade</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Origem</label>
                            <select class="form-select" name="origem" id="homeSource">
                                <option value="posts" <?= $form['origem']==='posts'?'selected':'' ?>>Posts / Notícias</option>
                                <option value="eventos" <?= $form['origem']==='eventos'?'selected':'' ?>>Eventos</option>
                                <option value="paginas" <?= $form['origem']==='paginas'?'selected':'' ?>>Páginas</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8" id="categoryField">
                            <label class="form-label">Categoria <span class="text-secondary fw-normal">(opcional)</span></label>
                            <select class="form-select" name="categoria_id">
                                <option value="0">Todas as categorias</option>
                                <?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= (int)$form['categoria_id']===(int)$cat['id']?'selected':'' ?>><?= e((string)$cat['nome']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantidade</label>
                            <input class="form-control" type="number" min="1" max="20" name="limite" value="<?= (int)$form['limite'] ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Texto do link</label>
                            <input class="form-control" name="link_texto" value="<?= e($form['link_texto']) ?>" placeholder="Veja mais">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Destino do link</label>
                            <input class="form-control" name="link_url" value="<?= e($form['link_url']) ?>" placeholder="/noticias.php">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Posição da data</label>
                            <select class="form-select" name="date_position">
                                <option value="after" <?= $form['date_position']==='after'?'selected':'' ?>>Abaixo do título</option>
                                <option value="before" <?= $form['date_position']==='before'?'selected':'' ?>>Acima do título</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fundo da seção</label>
                            <select class="form-select" name="background">
                                <option value="white" <?= $form['background']==='white'?'selected':'' ?>>Branco</option>
                                <option value="soft" <?= $form['background']==='soft'?'selected':'' ?>>Cinza claro</option>
                            </select>
                        </div>
                    </div>
                    <div class="border rounded p-3 mb-3 bg-body-tertiary">
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="ativo" id="homeActive" <?= $form['ativo']?'checked':'' ?>><label class="form-check-label" for="homeActive">Exibir esta seção</label></div>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_date" id="showDate" <?= $form['show_date']?'checked':'' ?>><label class="form-check-label" for="showDate">Exibir data</label></div>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_excerpt" id="showExcerpt" <?= $form['show_excerpt']?'checked':'' ?>><label class="form-check-label" for="showExcerpt">Exibir resumo</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="autoplay" id="autoplay" <?= $form['autoplay']?'checked':'' ?>><label class="form-check-label" for="autoplay">Avançar carrossel automaticamente</label></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i><?= $editing ? 'Salvar alterações' : 'Adicionar seção' ?></button>
                        <?php if ($editing): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/aparencia/home.php')) ?>">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.home-drag-handle{cursor:grab}.home-drag-handle:active{cursor:grabbing}.home-section-row.dragging{opacity:.5}.home-section-row.drag-over{border-top:3px solid var(--bs-primary)}
</style>
<script>
(() => {
    const list = document.getElementById('homeSectionList');
    const order = document.getElementById('orderIds');
    if (!list || !order) return;
    let dragged = null;
    function sync(){ order.value = [...list.querySelectorAll('.home-section-row')].map(x => x.dataset.id).join(','); }
    list.querySelectorAll('.home-section-row').forEach(row => {
        row.draggable = true;
        row.addEventListener('dragstart', () => { dragged = row; row.classList.add('dragging'); });
        row.addEventListener('dragend', () => { row.classList.remove('dragging'); list.querySelectorAll('.drag-over').forEach(x=>x.classList.remove('drag-over')); dragged=null; sync(); });
        row.addEventListener('dragover', e => { e.preventDefault(); if (dragged && dragged !== row) row.classList.add('drag-over'); });
        row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
        row.addEventListener('drop', e => { e.preventDefault(); row.classList.remove('drag-over'); if (!dragged || dragged === row) return; const rect=row.getBoundingClientRect(); if (e.clientY < rect.top + rect.height/2) list.insertBefore(dragged,row); else list.insertBefore(dragged,row.nextSibling); sync(); });
    });
    const source = document.getElementById('homeSource');
    const category = document.getElementById('categoryField');
    const toggleCategory = () => { if (source && category) category.style.opacity = source.value === 'posts' ? '1' : '.45'; };
    source?.addEventListener('change', toggleCategory); toggleCategory();
})();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
