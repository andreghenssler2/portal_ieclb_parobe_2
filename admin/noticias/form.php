<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');

$pdo = Database::connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$defaultCategory = (int)siteConfig($pdo, 'writing_default_category', '0');
$defaultStatus = siteConfig($pdo, 'writing_default_status', 'rascunho');
if (!in_array($defaultStatus, ['rascunho', 'publicado'], true)) {
    $defaultStatus = 'rascunho';
}
$defaultCommentsOpen = siteConfig($pdo, 'comments_default_open', '1') === '1' ? 1 : 0;

$post = [
    'titulo' => '',
    'slug' => '',
    'resumo' => '',
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
    'conteudo' => '',
    'comunidade_id' => '',
    'categoria_id' => $defaultCategory > 0 ? $defaultCategory : '',
    'status' => $defaultStatus,
    'destaque' => 0,
    'comentarios_ativos' => $defaultCommentsOpen,
    'publicado_em' => '',
    'imagem_capa_id' => '',
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Notícia não encontrada.');
    }
    if (($found['status'] ?? '') === 'lixeira') {
        Session::flash('error', 'Restaure a notícia da Lixeira antes de editá-la.');
        header('Location: ' . url('admin/noticias/index.php?status=lixeira'));
        exit;
    }
    $post = $found;
}

$comunidades = $pdo->query('SELECT id,nome FROM comunidades WHERE ativa=1 ORDER BY ordem,nome')->fetchAll();
$categorias = CategoryService::tree($pdo);
$tags = $pdo->query('SELECT id,nome,slug FROM tags ORDER BY nome')->fetchAll();

$selectedCategories = $id
    ? CategoryService::postCategoryIds($pdo, $id)
    : ($defaultCategory > 0 ? CategoryService::validIds($pdo, [$defaultCategory]) : []);

$selectedTags = [];
if ($id) {
    $st = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id=:id');
    $st->execute(['id' => $id]);
    $selectedTags = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$midias = $pdo->query("SELECT id,caminho,titulo,alt_text,nome_original,largura,altura FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC")->fetchAll();
$imagemCapaAtual = !empty($post['imagem_capa_id']) ? MediaService::find($pdo, (int)$post['imagem_capa_id']) : null;

$revisionCount = 0;
if ($id) {
    try {
        $revisionCount = RevisionService::count($pdo, 'post', $id);
    } catch (Throwable $ignored) {
        $revisionCount = 0;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['titulo', 'slug', 'resumo', 'seo_titulo', 'seo_descricao', 'conteudo', 'comunidade_id', 'status', 'publicado_em', 'imagem_capa_id'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $post[$field] = $_POST[$field];
        }
    }
    $post['destaque'] = isset($_POST['destaque']) ? 1 : 0;
    $post['comentarios_ativos'] = isset($_POST['comentarios_ativos']) ? 1 : 0;
    $post['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    $selectedCategories = CategoryService::validIds($pdo, (array)($_POST['categorias'] ?? []));
    $selectedTags = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['tags'] ?? [])),
        static fn($v) => $v > 0
    )));

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
                    logAction($pdo, 'midia.upload', 'midias', $imagemCapaId, 'Imagem destacada de notícia');
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

                $slugBase = trim((string)($_POST['slug'] ?? ''));
                if ($slugBase === '') {
                    $slugBase = $titulo;
                }
                $primaryCategoryId = $selectedCategories[0] ?? null;

                $data = [
                    'autor_id' => Auth::id(),
                    'comunidade_id' => ($_POST['comunidade_id'] ?? '') !== '' ? (int)$_POST['comunidade_id'] : null,
                    'categoria_id' => $primaryCategoryId,
                    'titulo' => $titulo,
                    'slug' => uniqueSlug($pdo, 'posts', $slugBase, $id),
                    'resumo' => trim((string)($_POST['resumo'] ?? '')) ?: null,
                    'conteudo' => $conteudo,
                    'imagem_capa_id' => $imagemCapaId,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status,
                    'destaque' => isset($_POST['destaque']) ? 1 : 0,
                    'comentarios_ativos' => isset($_POST['comentarios_ativos']) ? 1 : 0,
                    'publicado_em' => $publicadoEm,
                ];

                if ($id) {
                    $data['id'] = $id;
                    $stmt = $pdo->prepare(
                        'UPDATE posts SET autor_id=:autor_id,comunidade_id=:comunidade_id,categoria_id=:categoria_id,titulo=:titulo,slug=:slug,resumo=:resumo,conteudo=:conteudo,imagem_capa_id=:imagem_capa_id,seo_titulo=:seo_titulo,seo_descricao=:seo_descricao,seo_noindex=:seo_noindex,status=:status,destaque=:destaque,comentarios_ativos=:comentarios_ativos,publicado_em=:publicado_em WHERE id=:id'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO posts (autor_id,comunidade_id,categoria_id,titulo,slug,resumo,conteudo,imagem_capa_id,seo_titulo,seo_descricao,seo_noindex,status,destaque,comentarios_ativos,publicado_em) VALUES (:autor_id,:comunidade_id,:categoria_id,:titulo,:slug,:resumo,:conteudo,:imagem_capa_id,:seo_titulo,:seo_descricao,:seo_noindex,:status,:destaque,:comentarios_ativos,:publicado_em)'
                    );
                }

                $pdo->beginTransaction();
                try {
                    if ($id) {
                        RevisionService::create($pdo, 'post', $id, Auth::id());
                    }

                    $stmt->execute($data);
                    $savedId = $id ?: (int)$pdo->lastInsertId();

                    CategoryService::syncPostCategories($pdo, $savedId, $selectedCategories, $primaryCategoryId);

                    $pdo->prepare('DELETE FROM post_tags WHERE post_id=:post_id')->execute(['post_id' => $savedId]);
                    if ($selectedTags) {
                        $validStmt = $pdo->prepare('SELECT id FROM tags WHERE id IN (' . implode(',', array_fill(0, count($selectedTags), '?')) . ')');
                        $validStmt->execute($selectedTags);
                        $validIds = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));
                        $link = $pdo->prepare('INSERT INTO post_tags (post_id,tag_id) VALUES (:post_id,:tag_id)');
                        foreach ($validIds as $tagId) {
                            $link->execute(['post_id' => $savedId, 'tag_id' => $tagId]);
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $txe;
                }

                logAction($pdo, $id ? 'noticia.editar' : 'noticia.criar', 'posts', $savedId, $titulo);
                Session::flash('success', $id ? 'Notícia atualizada.' : 'Notícia criada.');
                header('Location: ' . url('admin/noticias/index.php'));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = $id ? 'Editar notícia' : 'Nova notícia';
require __DIR__ . '/../_header.php';
?>

<div class="post-editor-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="text-uppercase small text-secondary fw-semibold mb-1">Posts / Notícias</div>
            <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
        </div>
        <div class="d-flex gap-2">
            <?php if ($id): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('admin/revisoes/index.php?tipo=post&id=' . $id)) ?>">
                    <i class="bi bi-clock-history me-1"></i>Revisões (<?= (int)$revisionCount ?>)
                </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('admin/noticias/index.php')) ?>">Todos os Posts</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="postEditorForm">
        <?= Csrf::field() ?>

        <div class="post-editor-grid">
            <main class="post-editor-main">
                <section class="post-editor-canvas shadow-sm">
                    <div class="post-title-wrap">
                        <label class="visually-hidden" for="postTitulo">Título</label>
                        <input
                            id="postTitulo"
                            class="post-title-input"
                            name="titulo"
                            value="<?= e((string)$post['titulo']) ?>"
                            maxlength="220"
                            placeholder="Adicionar título"
                            autocomplete="off"
                            required
                        >
                        <?php if ($id && !empty($post['slug'])): ?>
                            <div class="post-permalink-preview">
                                <i class="bi bi-link-45deg"></i>
                                <a target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>"><?= e(contentUrl('noticia', (string)$post['slug'])) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="post-content-wrap">
                        <label class="visually-hidden" for="conteudo">Conteúdo</label>
                        <textarea id="conteudo" name="conteudo" rows="18"><?= e((string)$post['conteudo']) ?></textarea>
                    </div>
                </section>

                <section class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white fw-semibold py-3">Resumo</div>
                    <div class="card-body">
                        <textarea class="form-control" name="resumo" rows="4" placeholder="Escreva um resumo curto da notícia..."><?= e((string)($post['resumo'] ?? '')) ?></textarea>
                        <div class="form-text">Usado nos cards, resultados de busca e como descrição quando o SEO específico estiver vazio.</div>
                    </div>
                </section>

                <section class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white fw-semibold py-3">SEO do conteúdo</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Título SEO</label>
                            <input class="form-control" name="seo_titulo" maxlength="180" value="<?= e((string)($post['seo_titulo'] ?? '')) ?>" placeholder="Se vazio, usa o título da notícia">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Meta description</label>
                            <textarea class="form-control" name="seo_descricao" maxlength="320" rows="3" placeholder="Se vazia, usa o resumo"><?= e((string)($post['seo_descricao'] ?? '')) ?></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="seo_noindex" id="seoNoindex" <?= !empty($post['seo_noindex']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="seoNoindex">Não permitir que esta notícia seja indexada pelos buscadores</label>
                        </div>
                    </div>
                </section>
            </main>

            <aside class="post-editor-sidebar">
                <section class="card border-0 shadow-sm post-editor-panel">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <strong>Publicação</strong>
                        <?php if ($id && !empty($post['slug'])): ?>
                            <a class="small text-decoration-none" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Visualizar</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach (['rascunho' => 'Rascunho', 'agendado' => 'Agendado', 'publicado' => 'Publicado', 'arquivado' => 'Arquivado'] as $v => $l): ?>
                                    <option value="<?= e($v) ?>" <?= $post['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Publicar em</label>
                            <input type="datetime-local" class="form-control" name="publicado_em" value="<?= $post['publicado_em'] ? e((new DateTime((string)$post['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Slug</label>
                            <input class="form-control" name="slug" value="<?= e((string)($post['slug'] ?? '')) ?>" maxlength="240" placeholder="gerada-pelo-titulo">
                            <div class="form-text">Deixe vazio para gerar automaticamente.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Comunidade</label>
                            <select class="form-select" name="comunidade_id">
                                <option value="">Paroquial / Todas</option>
                                <?php foreach ($comunidades as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (string)$post['comunidade_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="destaque" id="destaque" <?= $post['destaque'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="destaque">Destacar na página inicial</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="comentarios_ativos" id="comentariosAtivos" <?= !empty($post['comentarios_ativos']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="comentariosAtivos">Permitir comentários</label>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-between gap-2">
                        <a class="btn btn-outline-secondary" href="<?= e(url('admin/noticias/index.php')) ?>">Cancelar</a>
                        <button class="btn btn-primary px-4" type="submit"><i class="bi bi-check2 me-1"></i>Salvar</button>
                    </div>
                </section>

                <section class="card border-0 shadow-sm post-editor-panel">
                    <div class="card-header bg-white py-3"><strong>Imagem destacada</strong></div>
                    <div class="card-body">
                        <input type="hidden" name="imagem_capa_id" id="imagemCapaId" value="<?= e((string)($post['imagem_capa_id'] ?? '')) ?>">
                        <div id="imagemCapaPreview" class="featured-picker-preview mb-3">
                            <?php if ($imagemCapaAtual && MediaService::isImage($imagemCapaAtual)): ?>
                                <div class="post-featured-current">
                                    <img src="<?= e(mediaUrl($imagemCapaAtual['caminho'])) ?>" alt="<?= e($imagemCapaAtual['alt_text'] ?: $imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?>">
                                    <div class="small fw-semibold mt-2 text-truncate"><?= e($imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?></div>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-media-featured-remove>Remover imagem</button>
                                </div>
                            <?php else: ?>
                                <div class="post-featured-empty"><i class="bi bi-image"></i><span>Nenhuma imagem destacada</span></div>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-outline-primary w-100 mb-2" type="button" data-media-featured-open>Escolher da biblioteca</button>
                        <label class="btn btn-outline-secondary w-100 mb-0">
                            Fazer upload
                            <input class="d-none" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif">
                        </label>
                        <div class="form-text mt-2">Máximo <?= e(formatBytes(mediaUploadMaxSize($pdo))) ?>.</div>
                    </div>
                </section>

                <section class="card border-0 shadow-sm post-editor-panel">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <strong>Categorias</strong>
                        <span class="badge text-bg-light" id="categorySelectedCount"><?= count($selectedCategories) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="input-group input-group-sm mb-3">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search" id="categorySearch" class="form-control" placeholder="Pesquisar categorias">
                        </div>
                        <div id="categoryChoices" class="post-category-choices">
                            <?php if (!$categorias): ?>
                                <div class="text-secondary small">Nenhuma categoria cadastrada.</div>
                            <?php endif; ?>
                            <?php foreach ($categorias as $categoria): ?>
                                <?php $depth = max(0, (int)($categoria['depth'] ?? 0)); ?>
                                <label class="post-category-choice" data-category-name="<?= e(mb_strtolower((string)$categoria['nome'])) ?>" style="--category-depth:<?= $depth ?>">
                                    <input class="form-check-input" type="checkbox" name="categorias[]" value="<?= (int)$categoria['id'] ?>" <?= in_array((int)$categoria['id'], $selectedCategories, true) ? 'checked' : '' ?>>
                                    <span><?= e($categoria['nome']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="form-text m-0">Você pode marcar mais de uma.</div>
                            <a class="small text-decoration-none" target="_blank" href="<?= e(url('admin/categorias/index.php')) ?>">Adicionar categoria</a>
                        </div>
                    </div>
                </section>

                <section class="card border-0 shadow-sm post-editor-panel">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <strong>Tags</strong>
                        <span class="badge text-bg-light" id="tagSelectedCount"><?= count($selectedTags) ?></span>
                    </div>
                    <div class="card-body">
                        <input type="search" id="tagSearch" class="form-control form-control-sm mb-3" placeholder="Pesquisar tags">
                        <div id="tagChoices" class="post-tag-choices">
                            <?php foreach ($tags as $tag): ?>
                                <label class="tag-choice" data-tag-name="<?= e(mb_strtolower((string)$tag['nome'])) ?>">
                                    <input class="form-check-input me-1" type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>" <?= in_array((int)$tag['id'], $selectedTags, true) ? 'checked' : '' ?>><?= e($tag['nome']) ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$tags): ?><span class="text-secondary small">Nenhuma tag cadastrada.</span><?php endif; ?>
                        </div>
                        <a class="small text-decoration-none d-inline-block mt-3" target="_blank" href="<?= e(url('admin/tags/index.php')) ?>">Gerenciar tags</a>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

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
    selector: '#conteudo',
    height: 620,
    menubar: false,
    placeholder: 'Comece a escrever a notícia...',
    plugins: 'link lists table code image media autoresize',
    toolbar: 'undo redo | blocks | bold italic | bullist numlist | link portalmedia table | alignleft aligncenter alignright | blockquote | code',
    content_style: 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:17px;line-height:1.75;padding:18px 24px;max-width:920px;margin:0 auto;} img{max-width:100%;height:auto;}',
    setup: function (editor) {
        editor.ui.registry.addButton('portalmedia', {
            icon: 'image',
            tooltip: 'Inserir imagens da Biblioteca de Mídia',
            onAction: function () { PortalMediaPicker.openForEditor(editor); }
        });
        editor.on('change keyup', function () { editor.save(); });
    }
});

document.getElementById('postEditorForm').addEventListener('submit', function () {
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});

function bindChoiceSearch(inputId, containerId, dataKey) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', function () {
        const q = this.value.trim().toLocaleLowerCase('pt-BR');
        document.querySelectorAll('#' + containerId + ' [data-' + dataKey + ']').forEach(function (el) {
            const value = el.getAttribute('data-' + dataKey) || '';
            el.classList.toggle('d-none', q !== '' && !value.includes(q));
        });
    });
}

bindChoiceSearch('categorySearch', 'categoryChoices', 'category-name');
bindChoiceSearch('tagSearch', 'tagChoices', 'tag-name');

function bindCount(containerId, countId) {
    const container = document.getElementById(containerId);
    const output = document.getElementById(countId);
    if (!container || !output) return;
    const update = function () {
        output.textContent = container.querySelectorAll('input[type="checkbox"]:checked').length;
    };
    container.addEventListener('change', update);
    update();
}

bindCount('categoryChoices', 'categorySelectedCount');
bindCount('tagChoices', 'tagSelectedCount');

PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('imagemCapaId'),
    preview: document.getElementById('imagemCapaPreview')
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
