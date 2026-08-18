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
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC
     LIMIT 100"
)->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['titulo', 'slug', 'resumo', 'conteudo', 'imagem_capa_id', 'status', 'ordem', 'publicado_em'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $pagina[$field] = $_POST[$field];
        }
    }
    $pagina['exibir_menu'] = isset($_POST['exibir_menu']) ? 1 : 0;

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
                            status = :status,
                            exibir_menu = :exibir_menu,
                            ordem = :ordem,
                            publicado_em = :publicado_em
                         WHERE id = :id'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO paginas
                            (autor_id, titulo, slug, resumo, conteudo, imagem_capa_id, status, exibir_menu, ordem, publicado_em)
                         VALUES
                            (:autor_id, :titulo, :slug, :resumo, :conteudo, :imagem_capa_id, :status, :exibir_menu, :ordem, :publicado_em)'
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
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <label class="form-label fw-semibold mb-0">Imagem destacada</label>
                            <div class="form-text mt-0">Envie uma nova imagem ou escolha uma já existente na biblioteca.</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url('admin/midias/index.php?tipo=imagens')) ?>">Abrir biblioteca</a>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-5">
                            <input class="form-control" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="form-text">JPG, PNG, WEBP ou GIF. Máximo <?= e(formatBytes(UPLOAD_MAX_SIZE)) ?>.</div>
                        </div>
                        <div class="col-lg-7">
                            <select class="form-select" name="imagem_capa_id" id="imagemCapaSelect">
                                <option value="">Sem imagem destacada</option>
                                <?php foreach ($midias as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" data-url="<?= e(mediaUrl($m['caminho'])) ?>" <?= (string)($pagina['imagem_capa_id'] ?? '') === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['titulo'] ?: $m['nome_original']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="imagemCapaPreview" class="mt-3"></div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">Conteúdo</label>
                <textarea id="conteudo" class="form-control" name="conteudo" rows="16"><?= e((string)$pagina['conteudo']) ?></textarea>
            </div>

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

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script>
tinymce.init({
    selector:'#conteudo',
    height:520,
    menubar:false,
    plugins:'link lists table code image media',
    toolbar:'undo redo | blocks | bold italic | bullist numlist | link image table | alignleft aligncenter alignright | code',
    setup:function(editor){
        editor.on('change keyup', function(){ editor.save(); });
    }
});
document.querySelector('form').addEventListener('submit', function(){
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});
const select=document.getElementById('imagemCapaSelect');
const preview=document.getElementById('imagemCapaPreview');
function updatePreview(){
    const option=select.options[select.selectedIndex];
    const src=option?.dataset?.url || '';
    preview.innerHTML=src ? `<img src="${src}" alt="Prévia" class="img-thumbnail featured-preview">` : '';
}
select.addEventListener('change',updatePreview);
updatePreview();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
