<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();

if (!Auth::can('paginas.gerenciar') && !Auth::can('noticias.gerenciar')) {
    http_response_code(403);
    exit('Sem permissão para gerenciar padrões de conteúdo.');
}

$pdo = Database::connection();
ContentBlockService::ensureSchema($pdo);
ContentPatternService::ensureSchema($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editing = $id ? ContentPatternService::find($pdo, $id) : null;

if ($id && !$editing) {
    Session::flash('error', 'Padrão não encontrado.');
    header('Location: ' . url('admin/padroes-conteudo/index.php'));
    exit;
}

$form = [
    'nome' => (string)($editing['nome'] ?? ''),
    'descricao' => (string)($editing['descricao'] ?? ''),
    'escopo' => (string)($editing['escopo'] ?? 'geral'),
    'ativo' => $editing ? (int)$editing['ativo'] : 1,
];

$contentBlocks = [];
if ($editing) {
    $decoded = json_decode((string)$editing['blocos_json'], true);
    if (is_array($decoded)) {
        $contentBlocks = ContentBlockService::prepareForEditor($pdo, $decoded);
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            if ($action === 'delete') {
                $deleteId = (int)($_POST['id'] ?? 0);
                ContentPatternService::delete($pdo, $deleteId);
                logAction(
                    $pdo,
                    'padrao_conteudo.excluir',
                    'conteudo_padroes',
                    $deleteId
                );
                Session::flash('success', 'Padrão excluído.');
                header('Location: ' . url('admin/padroes-conteudo/index.php'));
                exit;
            }

            if ($action === 'duplicate') {
                $duplicateId = (int)($_POST['id'] ?? 0);
                $newId = ContentPatternService::duplicate(
                    $pdo,
                    $duplicateId,
                    (int)Auth::id()
                );
                logAction(
                    $pdo,
                    'padrao_conteudo.duplicar',
                    'conteudo_padroes',
                    $newId
                );
                Session::flash(
                    'success',
                    'Cópia criada como inativa. Revise e ative quando desejar.'
                );
                header(
                    'Location: '
                    . url('admin/padroes-conteudo/index.php?id=' . $newId)
                );
                exit;
            }

            $form['nome'] = trim((string)($_POST['nome'] ?? ''));
            $form['descricao'] = trim((string)($_POST['descricao'] ?? ''));
            $form['escopo'] = (string)($_POST['escopo'] ?? 'geral');
            $form['ativo'] = isset($_POST['ativo']) ? 1 : 0;

            $contentBlocks = ContentBlockService::prepareForEditor(
                $pdo,
                ContentBlockService::fromJson(
                    $pdo,
                    (string)($_POST['content_blocks_json'] ?? '[]')
                )
            );

            $savedId = ContentPatternService::save(
                $pdo,
                $id,
                $form['nome'],
                $form['descricao'],
                $form['escopo'],
                (bool)$form['ativo'],
                (int)Auth::id(),
                $contentBlocks
            );

            logAction(
                $pdo,
                $id ? 'padrao_conteudo.editar' : 'padrao_conteudo.criar',
                'conteudo_padroes',
                $savedId,
                $form['nome']
            );

            Session::flash(
                'success',
                $id ? 'Padrão atualizado.' : 'Padrão criado.'
            );

            header(
                'Location: '
                . url('admin/padroes-conteudo/index.php?id=' . $savedId)
            );
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$patterns = ContentPatternService::all($pdo);
$pageTitle = $editing ? 'Editar padrão de conteúdo' : 'Padrões de conteúdo';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Padrões de conteúdo</h1>
        <p class="text-secondary mb-0">
            Crie conjuntos de blocos reutilizáveis em Páginas e Posts/Notícias.
        </p>
    </div>
    <?php if ($editing): ?>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/padroes-conteudo/index.php')) ?>">
            Novo padrão
        </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <form method="post" id="contentPatternForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold py-3">
                    <?= $editing ? 'Editar padrão' : 'Novo padrão' ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nome</label>
                            <input
                                class="form-control"
                                name="nome"
                                maxlength="160"
                                value="<?= e($form['nome']) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Disponível em</label>
                            <select class="form-select" name="escopo">
                                <option value="geral" <?= $form['escopo']==='geral'?'selected':'' ?>>Páginas e Notícias</option>
                                <option value="pagina" <?= $form['escopo']==='pagina'?'selected':'' ?>>Somente Páginas</option>
                                <option value="post" <?= $form['escopo']==='post'?'selected':'' ?>>Somente Notícias</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea
                                class="form-control"
                                name="descricao"
                                rows="2"
                                maxlength="500"
                                placeholder="Ex.: chamada com imagem, texto e botão"
                            ><?= e($form['descricao']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="ativo"
                                    id="patternActive"
                                    <?= $form['ativo'] ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="patternActive">
                                    Disponível para inserção nos editores
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $contentBlocksTitle = 'Blocos do padrão';
            $contentPatterns = [];
            require __DIR__ . '/../_content_blocks_editor.php';
            ?>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-primary">
                    <?= $editing ? 'Atualizar padrão' : 'Criar padrão' ?>
                </button>
                <?php if ($editing): ?>
                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e(url('admin/padroes-conteudo/index.php')) ?>"
                    >Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                Padrões cadastrados
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$patterns): ?>
                    <div class="p-4 text-secondary">Nenhum padrão cadastrado.</div>
                <?php endif; ?>

                <?php foreach ($patterns as $pattern): ?>
                    <?php
                    $scopeLabel = match ((string)$pattern['escopo']) {
                        'pagina' => 'Páginas',
                        'post' => 'Notícias',
                        default => 'Páginas e Notícias',
                    };
                    ?>
                    <div class="list-group-item py-3">
                        <div class="d-flex justify-content-between gap-3">
                            <div class="min-w-0">
                                <div class="fw-semibold">
                                    <?= e((string)$pattern['nome']) ?>
                                </div>
                                <div class="small text-secondary">
                                    <?= e($scopeLabel) ?>
                                    ·
                                    <?= (int)$pattern['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                                </div>
                                <?php if (!empty($pattern['descricao'])): ?>
                                    <div class="small mt-1">
                                        <?= e((string)$pattern['descricao']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="dropdown flex-shrink-0">
                                <button
                                    class="btn btn-sm btn-light border dropdown-toggle"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                >Ações</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a
                                            class="dropdown-item"
                                            href="<?= e(url('admin/padroes-conteudo/index.php?id=' . (int)$pattern['id'])) ?>"
                                        >Editar</a>
                                    </li>
                                    <li>
                                        <form method="post">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="duplicate">
                                            <input type="hidden" name="id" value="<?= (int)$pattern['id'] ?>">
                                            <button class="dropdown-item" type="submit">Duplicar</button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form
                                            method="post"
                                            onsubmit="return confirm('Excluir este padrão?');"
                                        >
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$pattern['id'] ?>">
                                            <button class="dropdown-item text-danger" type="submit">
                                                Excluir
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script src="<?= e(url('public/js/content-block-editor.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.44.0'))) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});
ContentBlockEditor.init();
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
