<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');
$pdo = Database::connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$evento = [
    'tipo' => 'culto',
    'titulo' => '',
    'slug' => '',
    'resumo' => '',
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
    'descricao' => '',
    'comunidade_id' => '',
    'categoria_evento_id' => '',
    'local' => '',
    'endereco' => '',
    'data_inicio' => '',
    'data_fim' => '',
    'santa_ceia' => 0,
    'imagem_capa_id' => '',
    'status' => 'rascunho',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM eventos WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Evento ou culto não encontrado.');
    }
    $evento = $found;
}

$comunidades = $pdo->query('SELECT id, nome FROM comunidades WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();
$categoriasEvento = $pdo->query('SELECT id, nome FROM evento_categorias WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();
$midias = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC
     LIMIT 100"
)->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['tipo', 'titulo', 'slug', 'resumo', 'seo_titulo', 'seo_descricao', 'descricao', 'comunidade_id', 'categoria_evento_id', 'local', 'endereco', 'data_inicio', 'data_fim', 'imagem_capa_id', 'status'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $evento[$field] = $_POST[$field];
        }
    }
    $evento['santa_ceia'] = isset($_POST['santa_ceia']) ? 1 : 0;
    $evento['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $tipo = (string)($_POST['tipo'] ?? 'culto');
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $dataInicioInput = trim((string)($_POST['data_inicio'] ?? ''));

        if (!in_array($tipo, ['culto', 'evento'], true)) {
            $tipo = 'evento';
        }

        if ($titulo === '' || $dataInicioInput === '') {
            $error = 'Título, tipo e data de início são obrigatórios.';
        } else {
            try {
                $dataInicio = (new DateTime($dataInicioInput))->format('Y-m-d H:i:s');
                $dataFimInput = trim((string)($_POST['data_fim'] ?? ''));
                $dataFim = $dataFimInput !== '' ? (new DateTime($dataFimInput))->format('Y-m-d H:i:s') : null;

                if ($dataFim !== null && strtotime($dataFim) < strtotime($dataInicio)) {
                    throw new RuntimeException('A data final não pode ser anterior à data inicial.');
                }

                $imagemCapaId = ($_POST['imagem_capa_id'] ?? '') !== '' ? (int)$_POST['imagem_capa_id'] : null;

                if (isset($_FILES['imagem_capa_upload']) && (int)($_FILES['imagem_capa_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newMedia = MediaService::upload($pdo, $_FILES['imagem_capa_upload'], (int)Auth::id(), $titulo, $titulo);
                    if (!MediaService::isImage($newMedia)) {
                        MediaService::delete($pdo, (int)$newMedia['id']);
                        throw new RuntimeException('A imagem destacada precisa ser um arquivo de imagem.');
                    }
                    $imagemCapaId = (int)$newMedia['id'];
                    logAction($pdo, 'midia.upload', 'midias', $imagemCapaId, 'Imagem destacada de evento/culto');
                }

                if ($imagemCapaId !== null) {
                    $selectedMedia = MediaService::find($pdo, $imagemCapaId);
                    if (!$selectedMedia || !MediaService::isImage($selectedMedia)) {
                        throw new RuntimeException('A imagem destacada selecionada é inválida.');
                    }
                }

                $status = (string)($_POST['status'] ?? 'rascunho');
                if (!in_array($status, ['rascunho', 'publicado', 'cancelado', 'arquivado'], true)) {
                    $status = 'rascunho';
                }

                $slugInput = trim((string)($_POST['slug'] ?? ''));
                $slugBase = $slugInput !== '' ? $slugInput : $titulo . '-' . (new DateTime($dataInicio))->format('Y-m-d');

                $data = [
                    'autor_id' => Auth::id(),
                    'comunidade_id' => ($_POST['comunidade_id'] ?? '') !== '' ? (int)$_POST['comunidade_id'] : null,
                    'categoria_evento_id' => ($_POST['categoria_evento_id'] ?? '') !== '' ? (int)$_POST['categoria_evento_id'] : null,
                    'tipo' => $tipo,
                    'titulo' => $titulo,
                    'slug' => uniqueSlug($pdo, 'eventos', $slugBase, $id),
                    'resumo' => trim((string)($_POST['resumo'] ?? '')) ?: null,
                    'descricao' => trim((string)($_POST['descricao'] ?? '')) ?: null,
                    'local' => trim((string)($_POST['local'] ?? '')) ?: null,
                    'endereco' => trim((string)($_POST['endereco'] ?? '')) ?: null,
                    'data_inicio' => $dataInicio,
                    'data_fim' => $dataFim,
                    'santa_ceia' => $tipo === 'culto' && isset($_POST['santa_ceia']) ? 1 : 0,
                    'imagem_capa_id' => $imagemCapaId,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status,
                ];

                if ($id) {
                    $data['id'] = $id;
                    $stmt = $pdo->prepare(
                        'UPDATE eventos SET
                            autor_id = :autor_id,
                            comunidade_id = :comunidade_id,
                            categoria_evento_id = :categoria_evento_id,
                            tipo = :tipo,
                            titulo = :titulo,
                            slug = :slug,
                            resumo = :resumo,
                            descricao = :descricao,
                            local = :local,
                            endereco = :endereco,
                            data_inicio = :data_inicio,
                            data_fim = :data_fim,
                            santa_ceia = :santa_ceia,
                            imagem_capa_id = :imagem_capa_id,
                            seo_titulo = :seo_titulo,
                            seo_descricao = :seo_descricao,
                            seo_noindex = :seo_noindex,
                            status = :status
                         WHERE id = :id'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO eventos
                            (autor_id, comunidade_id, categoria_evento_id, tipo, titulo, slug, resumo, descricao, local, endereco, data_inicio, data_fim, santa_ceia, imagem_capa_id, seo_titulo, seo_descricao, seo_noindex, status)
                         VALUES
                            (:autor_id, :comunidade_id, :categoria_evento_id, :tipo, :titulo, :slug, :resumo, :descricao, :local, :endereco, :data_inicio, :data_fim, :santa_ceia, :imagem_capa_id, :seo_titulo, :seo_descricao, :seo_noindex, :status)'
                    );
                }

                $stmt->execute($data);
                $savedId = $id ?: (int)$pdo->lastInsertId();
                logAction($pdo, $id ? 'agenda.editar' : 'agenda.criar', 'eventos', $savedId, $tipo . ': ' . $titulo);
                Session::flash('success', $id ? 'Item da agenda atualizado.' : 'Item da agenda criado.');
                header('Location: ' . url('admin/eventos/index.php'));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = $id ? 'Editar evento/culto' : 'Novo evento/culto';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-secondary mb-0">Cadastre cultos e eventos da paróquia ou de uma comunidade.</p>
    </div>
    <?php if ($id && $evento['status'] === 'publicado'): ?>
        <a class="btn btn-outline-primary" target="_blank" href="<?= e(contentUrl('evento', (string)$evento['slug'])) ?>">Visualizar</a>
    <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="tipo" id="tipoEvento" required>
                    <option value="culto" <?= $evento['tipo'] === 'culto' ? 'selected' : '' ?>>Culto</option>
                    <option value="evento" <?= $evento['tipo'] === 'evento' ? 'selected' : '' ?>>Evento</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Título</label>
                <input class="form-control form-control-lg" name="titulo" value="<?= e((string)$evento['titulo']) ?>" required placeholder="Ex.: Culto com Santa Ceia">
            </div>

            <div class="col-md-4">
                <label class="form-label">Comunidade</label>
                <select class="form-select" name="comunidade_id">
                    <option value="">Paroquial / Todas</option>
                    <?php foreach ($comunidades as $comunidade): ?>
                        <option value="<?= (int)$comunidade['id'] ?>" <?= (string)$evento['comunidade_id'] === (string)$comunidade['id'] ? 'selected' : '' ?>><?= e($comunidade['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Categoria</label>
                <select class="form-select" name="categoria_evento_id">
                    <option value="">Sem categoria</option>
                    <?php foreach ($categoriasEvento as $categoria): ?>
                        <option value="<?= (int)$categoria['id'] ?>" <?= (string)($evento['categoria_evento_id'] ?? '') === (string)$categoria['id'] ? 'selected' : '' ?>><?= e($categoria['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><a href="<?= e(url('admin/eventos/categorias.php')) ?>">Gerenciar categorias</a></div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Slug / URL</label>
                <input class="form-control" name="slug" value="<?= e((string)$evento['slug']) ?>" placeholder="gerado-automaticamente">
            </div>

            <div class="col-md-6">
                <label class="form-label">Data e hora de início</label>
                <input class="form-control" type="datetime-local" name="data_inicio" value="<?= $evento['data_inicio'] ? e((new DateTime((string)$evento['data_inicio']))->format('Y-m-d\TH:i')) : '' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Data e hora de término <span class="text-secondary fw-normal">(opcional)</span></label>
                <input class="form-control" type="datetime-local" name="data_fim" value="<?= $evento['data_fim'] ? e((new DateTime((string)$evento['data_fim']))->format('Y-m-d\TH:i')) : '' ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Local</label>
                <input class="form-control" name="local" value="<?= e((string)($evento['local'] ?? '')) ?>" placeholder="Ex.: Igreja de Parobé">
            </div>
            <div class="col-md-6">
                <label class="form-label">Endereço</label>
                <input class="form-control" name="endereco" value="<?= e((string)($evento['endereco'] ?? '')) ?>" placeholder="Endereço completo ou referência">
            </div>

            <div class="col-12">
                <label class="form-label">Resumo</label>
                <textarea class="form-control" name="resumo" rows="3" placeholder="Texto curto para a agenda e página inicial"><?= e((string)($evento['resumo'] ?? '')) ?></textarea>
            </div>

            <div class="col-12">
                <div class="border rounded-3 p-3 bg-light-subtle">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <label class="form-label fw-semibold mb-0">Imagem destacada</label>
                            <div class="form-text mt-0">Envie uma nova imagem ou escolha uma da Biblioteca de Mídia.</div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url('admin/midias/index.php?tipo=imagens')) ?>">Abrir biblioteca</a>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-5">
                            <input class="form-control" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="form-text">JPG, PNG, WEBP ou GIF. Máximo <?= e(formatBytes(mediaUploadMaxSize($pdo))) ?>.</div>
                        </div>
                        <div class="col-lg-7">
                            <select class="form-select" name="imagem_capa_id" id="imagemCapaSelect">
                                <option value="">Sem imagem destacada</option>
                                <?php foreach ($midias as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" data-url="<?= e(mediaUrl($m['caminho'])) ?>" <?= (string)($evento['imagem_capa_id'] ?? '') === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['titulo'] ?: $m['nome_original']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="imagemCapaPreview" class="mt-3"></div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">Descrição</label>
                <textarea id="descricao" class="form-control" name="descricao" rows="12"><?= e((string)($evento['descricao'] ?? '')) ?></textarea>
            </div>

            <div class="col-12"><div class="border rounded-3 p-3"><div class="fw-semibold mb-3">SEO do conteúdo</div><div class="row g-3"><div class="col-12"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="180" value="<?= e((string)($evento['seo_titulo'] ?? '')) ?>" placeholder="Se vazio, usa o título do evento"></div><div class="col-12"><label class="form-label">Meta description</label><textarea class="form-control" name="seo_descricao" maxlength="320" rows="2"><?= e((string)($evento['seo_descricao'] ?? '')) ?></textarea></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="seoNoindex" <?= !empty($evento['seo_noindex']) ? 'checked' : '' ?>><label class="form-check-label" for="seoNoindex">Não indexar este evento/culto</label></div></div></div></div></div>

            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['rascunho' => 'Rascunho', 'publicado' => 'Publicado', 'cancelado' => 'Cancelado', 'arquivado' => 'Arquivado'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $evento['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end" id="santaCeiaWrap">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="santa_ceia" id="santaCeia" <?= (int)$evento['santa_ceia'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="santaCeia">Culto com Santa Ceia</label>
                </div>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Salvar</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/eventos/index.php')) ?>">Cancelar</a>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script>
tinymce.init({selector:'#descricao',height:380,menubar:false,plugins:'link lists table code image media',toolbar:'undo redo | blocks | bold italic | bullist numlist | link image table | alignleft aligncenter alignright | code'});

const select = document.getElementById('imagemCapaSelect');
const preview = document.getElementById('imagemCapaPreview');
function updatePreview() {
    const option = select.options[select.selectedIndex];
    const src = option?.dataset?.url || '';
    preview.innerHTML = src ? `<img src="${src}" alt="Prévia" class="img-thumbnail featured-preview">` : '';
}
select.addEventListener('change', updatePreview);
updatePreview();

const tipoEvento = document.getElementById('tipoEvento');
const santaCeiaWrap = document.getElementById('santaCeiaWrap');
const santaCeia = document.getElementById('santaCeia');
function updateSantaCeia() {
    const isCulto = tipoEvento.value === 'culto';
    santaCeiaWrap.classList.toggle('d-none', !isCulto);
    if (!isCulto) santaCeia.checked = false;
}
tipoEvento.addEventListener('change', updateSantaCeia);
updateSantaCeia();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
