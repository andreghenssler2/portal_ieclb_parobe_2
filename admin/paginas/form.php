<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('paginas.gerenciar');
$pdo = Database::connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pagina = [
    'titulo' => '',
    'slug' => '',
    'resumo' => '',
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
    'conteudo' => '',
    'imagem_capa_id' => '',
    'status' => 'rascunho',
    'exibir_menu' => 0,
    'ordem' => 0,
    'publicado_em' => '',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM paginas WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Página não encontrada.');
    }
    $pagina = $found;
}

$midias = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original, largura, altura
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC"
)->fetchAll();
$imagemCapaAtual = !empty($pagina['imagem_capa_id']) ? MediaService::find($pdo, (int)$pagina['imagem_capa_id']) : null;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['titulo', 'slug', 'resumo', 'seo_titulo', 'seo_descricao', 'conteudo', 'imagem_capa_id', 'status', 'ordem', 'publicado_em'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $pagina[$field] = $_POST[$field];
        }
    }
    $pagina['exibir_menu'] = isset($_POST['exibir_menu']) ? 1 : 0;
    $pagina['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $conteudo = trim((string)($_POST['conteudo'] ?? ''));
        $conteudoTexto = html_entity_decode(strip_tags($conteudo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $conteudoTexto = trim(str_replace("\u{00A0}", ' ', $conteudoTexto));

        if ($titulo === '' || $conteudoTexto === '') {
            $error = 'Título e conteúdo são obrigatórios.';
        } else {
            try {
                $imagemCapaId = ($_POST['imagem_capa_id'] ?? '') !== '' ? (int)$_POST['imagem_capa_id'] : null;

                if (isset($_FILES['imagem_capa_upload']) && (int)($_FILES['imagem_capa_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newMedia = MediaService::upload($pdo, $_FILES['imagem_capa_upload'], (int)Auth::id(), $titulo, $titulo);
                    if (!MediaService::isImage($newMedia)) {
                        MediaService::delete($pdo, (int)$newMedia['id']);
                        throw new RuntimeException('A imagem destacada precisa ser um arquivo de imagem.');
                    }
                    $imagemCapaId = (int)$newMedia['id'];
                    logAction($pdo, 'midia.upload', 'midias', $imagemCapaId, 'Imagem destacada de página');
                }

                if ($imagemCapaId !== null) {
                    $selectedMedia = MediaService::find($pdo, $imagemCapaId);
                    if (!$selectedMedia || !MediaService::isImage($selectedMedia)) {
                        throw new RuntimeException('A imagem destacada selecionada é inválida.');
                    }
                }

                $status = (string)($_POST['status'] ?? 'rascunho');
                if (!in_array($status, ['rascunho', 'agendado', 'publicado', 'arquivado'], true)) {
                    $status = 'rascunho';
                }

                $publicadoEm = trim((string)($_POST['publicado_em'] ?? ''));
                if ($publicadoEm !== '') {
                    $publicadoEm = (new DateTime($publicadoEm))->format('Y-m-d H:i:s');
                } elseif ($status === 'publicado') {
                    $publicadoEm = date('Y-m-d H:i:s');
                } else {
                    $publicadoEm = null;
                }

                $slugInput = trim((string)($_POST['slug'] ?? ''));
                $slugBase = $slugInput !== '' ? $slugInput : $titulo;

                $data = [
                    'autor_id' => Auth::id(),
                    'titulo' => $titulo,
                    'slug' => uniqueSlug($pdo, 'paginas', $slugBase, $id),
                    'resumo' => trim((string)($_POST['resumo'] ?? '')) ?: null,
                    'conteudo' => $conteudo,
                    'imagem_capa_id' => $imagemCapaId,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status,
                    'exibir_menu' => isset($_POST['exibir_menu']) ? 1 : 0,
                    'ordem' => (int)($_POST['ordem'] ?? 0),
                    'publicado_em' => $publicadoEm,
                ];

                if ($id) {
                    $data['id'] = $id;
                    $stmt = $pdo->prepare(
                        'UPDATE paginas SET
                            autor_id = :autor_id,
                            titulo = :titulo,
                            slug = :slug,
                            resumo = :resumo,
                            conteudo = :conteudo,
                            imagem_capa_id = :imagem_capa_id,
                            seo_titulo = :seo_titulo,
                            seo_descricao = :seo_descricao,
                            seo_noindex = :seo_noindex,
                            status = :status,
                            exibir_menu = :exibir_menu,
                            ordem = :ordem,
                            publicado_em = :publicado_em
                         WHERE id = :id'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO paginas
                            (autor_id, titulo, slug, resumo, conteudo, imagem_capa_id, seo_titulo, seo_descricao, seo_noindex, status, exibir_menu, ordem, publicado_em)
                         VALUES
                            (:autor_id, :titulo, :slug, :resumo, :conteudo, :imagem_capa_id, :seo_titulo, :seo_descricao, :seo_noindex, :status, :exibir_menu, :ordem, :publicado_em)'
                    );
                }

                $stmt->execute($data);
                $savedId = $id ?: (int)$pdo->lastInsertId();
                logAction($pdo, $id ? 'pagina.editar' : 'pagina.criar', 'paginas', $savedId, $titulo);
                Session::flash('success', $id ? 'Página atualizada.' : 'Página criada.');
                header('Location: ' . url('admin/paginas/index.php'));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = $id ? 'Editar página' : 'Nova página';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-secondary mb-0">Crie páginas institucionais com URL própria.</p>
    </div>
    <?php if ($id && $pagina['status'] === 'publicado'): ?>
        <a class="btn btn-outline-primary" target="_blank" href="<?= e(contentUrl('pagina', (string)$pagina['slug'])) ?>">Visualizar</a>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>
        <div class="row g-3">
            <div class="col-lg-8">
                <label class="form-label">Título</label>
                <input class="form-control form-control-lg" name="titulo" value="<?= e((string)$pagina['titulo']) ?>" required>
            </div>
            <div class="col-lg-4">
                <label class="form-label">Slug / URL</label>
                <input class="form-control" name="slug" value="<?= e((string)$pagina['slug']) ?>" placeholder="gerado-pelo-titulo">
                <div class="form-text">Ex.: quem-somos. Se vazio, será gerado automaticamente.</div>
            </div>

            <div class="col-12">
                <label class="form-label">Resumo</label>
                <textarea class="form-control" name="resumo" rows="3" placeholder="Descrição curta da página"><?= e((string)($pagina['resumo'] ?? '')) ?></textarea>
            </div>

            <div class="col-12">
                <div class="border rounded-3 p-3 bg-light-subtle">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <label class="form-label fw-semibold mb-0">Imagem destacada</label>
                            <div class="form-text mt-0">Faça upload ou escolha visualmente uma imagem da Biblioteca de Mídia.</div>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-media-featured-open>Escolher da biblioteca</button>
                    </div>
                    <input type="hidden" name="imagem_capa_id" id="imagemCapaId" value="<?= e((string)($pagina['imagem_capa_id'] ?? '')) ?>">
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-5">
                            <input class="form-control" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="form-text">JPG, PNG, WEBP ou GIF. Máximo <?= e(formatBytes(mediaUploadMaxSize($pdo))) ?>.</div>
                        </div>
                        <div class="col-lg-7">
                            <div id="imagemCapaPreview" class="featured-picker-preview">
                                <?php if ($imagemCapaAtual && MediaService::isImage($imagemCapaAtual)): ?>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="<?= e(mediaUrl($imagemCapaAtual['caminho'])) ?>" alt="<?= e($imagemCapaAtual['alt_text'] ?: $imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?>" class="img-thumbnail featured-preview">
                                        <div><div class="fw-semibold"><?= e($imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?></div><button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-media-featured-remove>Remover imagem</button></div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-secondary small">Nenhuma imagem selecionada.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">Conteúdo</label>
                <textarea id="conteudo" class="form-control" name="conteudo" rows="16"><?= e((string)$pagina['conteudo']) ?></textarea>
            </div>

            <div class="col-12"><div class="border rounded-3 p-3"><div class="fw-semibold mb-3">SEO do conteúdo</div><div class="row g-3"><div class="col-12"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="180" value="<?= e((string)($pagina['seo_titulo'] ?? '')) ?>" placeholder="Se vazio, usa o título da página"></div><div class="col-12"><label class="form-label">Meta description</label><textarea class="form-control" name="seo_descricao" maxlength="320" rows="2"><?= e((string)($pagina['seo_descricao'] ?? '')) ?></textarea></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="seoNoindex" <?= !empty($pagina['seo_noindex']) ? 'checked' : '' ?>><label class="form-check-label" for="seoNoindex">Não indexar esta página</label></div></div></div></div></div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['rascunho' => 'Rascunho', 'agendado' => 'Agendado', 'publicado' => 'Publicado', 'arquivado' => 'Arquivado'] as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $pagina['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Publicar em</label>
                <input type="datetime-local" class="form-control" name="publicado_em" value="<?= $pagina['publicado_em'] ? e((new DateTime((string)$pagina['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Ordem no menu</label>
                <input type="number" class="form-control" name="ordem" value="<?= (int)$pagina['ordem'] ?>" step="1">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="exibir_menu" id="exibirMenu" <?= $pagina['exibir_menu'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="exibirMenu">Exibir no menu público</label>
                </div>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Salvar página</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/paginas/index.php')) ?>">Cancelar</a>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

tinymce.init({
    selector:'#conteudo',
    height:520,
    menubar:false,
    plugins:'link lists table code image media',
    toolbar:'undo redo | blocks | bold italic | bullist numlist | link portalmedia table | alignleft aligncenter alignright | code',
    setup:function(editor){
        editor.ui.registry.addButton('portalmedia', {
            icon: 'image',
            tooltip: 'Inserir imagens da Biblioteca de Mídia',
            onAction: function () { PortalMediaPicker.openForEditor(editor); }
        });
        editor.on('change keyup', function(){ editor.save(); });
    }
});

document.querySelector('form').addEventListener('submit', function(){
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});

PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('imagemCapaId'),
    preview: document.getElementById('imagemCapaPreview')
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
