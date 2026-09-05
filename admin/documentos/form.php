<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('documentos.gerenciar');

$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$document = $id > 0 ? DocumentService::find($pdo, $id) : null;
$error = '';

if ($id > 0 && !$document) {
    http_response_code(404);
    $error = 'Documento não encontrado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $savedId = DocumentService::save($pdo, [
                'id' => $id,
                'titulo' => $_POST['titulo'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'descricao' => $_POST['descricao'] ?? '',
                'categoria_id' => $_POST['categoria_id'] ?? 0,
                'midia_id' => $_POST['midia_id'] ?? 0,
                'status' => $_POST['status'] ?? 'rascunho',
                'ordem' => $_POST['ordem'] ?? 0,
                'publicado_em' => $_POST['publicado_em'] ?? '',
                'seo_titulo' => $_POST['seo_titulo'] ?? '',
                'seo_descricao' => $_POST['seo_descricao'] ?? '',
                'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
            ], (int)Auth::id());

            logAction($pdo, $id > 0 ? 'documento.editar' : 'documento.criar', 'documentos', $savedId, trim((string)($_POST['titulo'] ?? '')));
            Session::flash('success', $id > 0 ? 'Documento atualizado.' : 'Documento criado.');
            header('Location: ' . url('admin/documentos/form.php?id=' . $savedId));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $document = array_merge($document ?: [], $_POST);
        }
    }
}

$categories = DocumentService::categories($pdo);
$media = DocumentService::mediaChoices($pdo);
$mediaById = [];
foreach ($media as $mediaItem) {
    $mediaById[(int)$mediaItem['id']] = $mediaItem;
}

$selectedDocumentMediaId =
    (int)($document['midia_id'] ?? 0);

$selectedDocumentMedia =
    $selectedDocumentMediaId > 0
        ? ($mediaById[$selectedDocumentMediaId] ?? null)
        : null;

$value = static function(string $key, mixed $default='') use ($document) {
    return $document[$key] ?? $default;
};

$publishedValue = (string)$value('publicado_em', '');
if ($publishedValue !== '') {
    try { $publishedValue = (new DateTimeImmutable($publishedValue))->format('Y-m-d\TH:i'); } catch (Throwable $e) {}
}

$pageTitle = $id > 0 ? 'Editar documento' : 'Novo documento';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?=e($pageTitle)?></h1>
        <p class="text-secondary mb-0">O arquivo continua armazenado e reutilizável na Biblioteca de Mídia.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?=e(url('admin/documentos/index.php'))?>">Voltar</a>
        <?php if($id>0 && ($document['status']??'')==='publicado'): ?><a class="btn btn-outline-primary" target="_blank" href="<?=e(contentUrl('documento',(string)$document['slug']))?>">Ver publicado</a><?php endif; ?>
    </div>
</div>

<?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>

<form method="post">
    <?=Csrf::field()?>
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input class="form-control form-control-lg" name="titulo" maxlength="220" required value="<?=e((string)$value('titulo'))?>" placeholder="Ex.: Boletim informativo de setembro">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input class="form-control" name="slug" maxlength="240" value="<?=e((string)$value('slug'))?>" placeholder="gerada-automaticamente">
                    </div>
                    <div>
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" name="descricao" rows="10" placeholder="Explique o conteúdo deste documento."><?=e((string)$value('descricao'))?></textarea>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">SEO</div>
                <div class="card-body p-4">
                    <div class="mb-3"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="220" value="<?=e((string)$value('seo_titulo'))?>"></div>
                    <div class="mb-3"><label class="form-label">Descrição SEO</label><textarea class="form-control" name="seo_descricao" rows="3" maxlength="320"><?=e((string)$value('seo_descricao'))?></textarea></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="noindex" <?=!empty($document['seo_noindex'])?'checked':''?>><label class="form-check-label" for="noindex">Não indexar este documento nos mecanismos de busca</label></div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Publicação</div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach(['rascunho'=>'Rascunho','publicado'=>'Publicado','arquivado'=>'Arquivado'] as $s=>$label): ?>
                                <option value="<?=e($s)?>" <?=(string)$value('status','rascunho')===$s?'selected':''?>><?=e($label)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Publicar em</label><input class="form-control" type="datetime-local" name="publicado_em" value="<?=e($publishedValue)?>"><div class="form-text">Se publicar e deixar vazio, usa a data atual.</div></div>
                    <div class="mb-3"><label class="form-label">Ordem</label><input class="form-control" type="number" name="ordem" value="<?=e((string)$value('ordem','0'))?>"></div>
                    <button class="btn btn-primary w-100"><?= $id>0 ? 'Salvar alterações' : 'Criar documento' ?></button>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Categoria</div>
                <div class="card-body p-4">
                    <select class="form-select" name="categoria_id">
                        <option value="0">Sem categoria</option>
                        <?php foreach($categories as $category): ?>
                            <option value="<?=(int)$category['id']?>" <?=(int)$value('categoria_id',0)===(int)$category['id']?'selected':''?>><?=e($category['nome'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <a class="small d-inline-block mt-2" href="<?=e(url('admin/documentos/categorias.php'))?>">Gerenciar categorias</a>
                </div>
            </div>

                        <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    Arquivo
                </div>

                <div class="card-body p-4">
                    <input
                        type="hidden"
                        name="midia_id"
                        id="documentMediaId"
                        value="<?= e((string)$value('midia_id', '')) ?>"
                        required
                    >

                    <div
                        id="documentMediaPreview"
                        class="border rounded-3 p-3 bg-body-tertiary"
                    >
                        <?php if ($selectedDocumentMedia): ?>
                            <div class="d-flex align-items-start gap-3">
                                <div
                                    class="rounded-3 bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:56px;height:56px"
                                >
                                    <i class="bi bi-file-earmark-text fs-3"></i>
                                </div>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-truncate">
                                        <?= e(
                                            (string)(
                                                $selectedDocumentMedia['titulo']
                                                ?: $selectedDocumentMedia['nome_original']
                                            )
                                        ) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= e(
                                            strtoupper(
                                                (string)$selectedDocumentMedia['extensao']
                                            )
                                        ) ?>
                                        ·
                                        <?= e(
                                            formatBytes(
                                                (int)$selectedDocumentMedia['tamanho']
                                            )
                                        ) ?>
                                        ·
                                        <?= e(
                                            (string)$selectedDocumentMedia['nome_original']
                                        ) ?>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-link text-danger p-0 mt-1"
                                        data-document-media-remove
                                    >
                                        Remover arquivo
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small">
                                Nenhum arquivo selecionado.
                            </div>
                        <?php endif; ?>
                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-primary w-100 mt-3"
                        id="documentMediaOpen"
                    >
                        <i class="bi bi-folder2-open me-1"></i>
                        Escolher na Biblioteca de Mídia
                    </button>

                    <div class="form-text mt-2">
                        PDF, Word, Excel, PowerPoint, TXT e outros arquivos permitidos.
                        O upload pode ser feito dentro do modal.
                    </div>
                </div>
            </div></div>
    </div>
</form>

<?php
$documentMediaSelectedId =
    (int)$value('midia_id', 0);

require __DIR__ . '/../_document_media_picker.php';
?>

<script src="<?= e(url('public/js/document-media-picker-v89-r6.js?v=0.89.0-r6')) ?>"></script>
<script>
PortalDocumentMediaPicker.init({
    modalId: 'portalDocumentMediaPickerModal',
    inputId: 'documentMediaId',
    previewId: 'documentMediaPreview',
    openButtonId: 'documentMediaOpen',
    uploadUrl: <?= json_encode(
        url('admin/documentos/upload-modal.php'),
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    ) ?>,
    csrfToken: <?= json_encode(
        Csrf::token(),
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    ) ?>
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
