<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');

$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
ContentBlockService::ensureSchema($pdo);
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
$tags = $pdo->query(
    "SELECT t.id,t.nome,t.slug,COUNT(pt.post_id) AS usage_count
     FROM tags t
     LEFT JOIN post_tags pt ON pt.tag_id=t.id
     GROUP BY t.id,t.nome,t.slug
     ORDER BY usage_count DESC,t.nome ASC"
)->fetchAll();

$selectedCategories = $id
    ? CategoryService::postCategoryIds($pdo, $id)
    : ($defaultCategory > 0 ? CategoryService::validIds($pdo, [$defaultCategory]) : []);

$selectedTags = [];
if ($id) {
    $st = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id=:id');
    $st->execute(['id' => $id]);
    $selectedTags = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$tagNamesById = [];
foreach ($tags as $tag) {
    $tagNamesById[(int)$tag['id']] = (string)$tag['nome'];
}
$tagNames = [];
foreach ($selectedTags as $tagId) {
    if (isset($tagNamesById[$tagId])) {
        $tagNames[] = $tagNamesById[$tagId];
    }
}
$tagInputValue = implode(', ', $tagNames);
$popularTags = array_slice($tags, 0, 14);

$midias = $pdo->query("SELECT id,caminho,titulo,alt_text,nome_original,largura,altura FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC")->fetchAll();
$imagemCapaAtual = !empty($post['imagem_capa_id']) ? MediaService::find($pdo, (int)$post['imagem_capa_id']) : null;

$contentBlocks = $id
    ? ContentBlockService::loadForEditor($pdo, 'post', $id)
    : [];
// v0.44.0 - padrões reutilizáveis: notícias
$contentPatterns = ContentPatternService::activeFor(
    $pdo,
    'post'
);

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

    $contentBlocks = ContentBlockService::prepareForEditor(
        $pdo,
        ContentBlockService::fromJson(
            $pdo,
            (string)($_POST['content_blocks_json'] ?? '[]')
        )
    );

    $selectedCategories = CategoryService::validIds($pdo, (array)($_POST['categorias'] ?? []));
    $tagInputRaw = trim((string)($_POST['tags_input'] ?? ''));
    $tagNames = [];
    if ($tagInputRaw !== '') {
        foreach (preg_split('/[,;\n]+/u', $tagInputRaw) ?: [] as $tagName) {
            $tagName = trim((string)$tagName);
            if ($tagName === '') {
                continue;
            }
            $tagName = mb_substr($tagName, 0, 100);
            $key = mb_strtolower($tagName, 'UTF-8');
            $tagNames[$key] = $tagName;
            if (count($tagNames) >= 30) {
                break;
            }
        }
        $tagNames = array_values($tagNames);
    }
    $tagInputValue = implode(', ', $tagNames);

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $conteudo = trim((string)($_POST['conteudo'] ?? ''));
        $conteudoTexto = html_entity_decode(strip_tags($conteudo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $conteudoTexto = trim(str_replace("\u{00A0}", ' ', $conteudoTexto));

        if (
            $titulo === ''
            || (
                $conteudoTexto === ''
                && !ContentBlockService::hasContent($contentBlocks)
            )
        ) {
            $error = 'Informe o título e pelo menos um conteúdo ou bloco.';
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

                
                // v0.61.0 - publicação de novos conteúdos passa pelo workflow editorial.
                EditorialWorkflowService::assertStatusTransitionAllowed(
                    $pdo,
                    $id,
                    $status,
                    (string)($post['status'] ?? 'rascunho')
                );
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
                    $resolvedTagIds = [];
                    foreach ($tagNames as $tagName) {
                        $findTag = $pdo->prepare('SELECT id FROM tags WHERE LOWER(nome)=LOWER(:nome) LIMIT 1');
                        $findTag->execute(['nome' => $tagName]);
                        $tagId = (int)($findTag->fetchColumn() ?: 0);
                        if ($tagId <= 0) {
                            $tagSlug = uniqueSlug($pdo, 'tags', $tagName);
                            $createTag = $pdo->prepare('INSERT INTO tags (nome,slug,descricao) VALUES (:nome,:slug,NULL)');
                            $createTag->execute(['nome' => $tagName, 'slug' => $tagSlug]);
                            $tagId = (int)$pdo->lastInsertId();
                            logAction($pdo, 'tag.criar', 'tags', $tagId, $tagName);
                        }
                        $resolvedTagIds[$tagId] = true;
                    }
                    if ($resolvedTagIds) {
                        $link = $pdo->prepare('INSERT INTO post_tags (post_id,tag_id) VALUES (:post_id,:tag_id)');
                        foreach (array_keys($resolvedTagIds) as $tagId) {
                            $link->execute(['post_id' => $savedId, 'tag_id' => (int)$tagId]);
                        }
                    }

                    ContentBlockService::save(
                        $pdo,
                        'post',
                        $savedId,
                        $contentBlocks
                    );

                    $pdo->commit();
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $txe;
                }

                                EditorialWorkflowService::syncAfterSave(
                    $pdo,
                    $savedId,
                    $status
                );
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

$workflowStatus = $id
    ? EditorialWorkflowService::status($pdo, $id)
    : EditorialWorkflowService::DRAFT;

$workflowLabel =
    EditorialWorkflowService::label($workflowStatus);

$workflowBadge =
    EditorialWorkflowService::badgeClass($workflowStatus);

$workflowCanSubmit =
    $id
    && EditorialWorkflowService::canSubmit($pdo, $id)
    && in_array(
        $workflowStatus,
        [
            EditorialWorkflowService::DRAFT,
            EditorialWorkflowService::CHANGES,
            EditorialWorkflowService::APPROVED,
        ],
        true
    );
$pageTitle = $id ? 'Editar notícia' : 'Nova notícia';
$authUser = Auth::user() ?? [];
$authorName = (string)($authUser['nome'] ?? 'Usuário');
$lastEdited = '';
if ($id && !empty($post['updated_at'])) {
    try {
        $lastEdited = (new DateTime((string)$post['updated_at']))->format('d/m/Y H:i');
    } catch (Throwable $ignored) {
        $lastEdited = '';
    }
}
require __DIR__ . '/../_header.php';
?>

<div class="wp-post-editor-page">
    <div class="wp-editor-toolbar mb-3">
        <div>
            <div class="text-uppercase small text-secondary fw-semibold mb-1">Posts / Notícias</div>
            <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($id): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('admin/revisoes/index.php?tipo=post&id=' . $id)) ?>">
                    <i class="bi bi-clock-history me-1"></i>Revisões (<?= (int)$revisionCount ?>)
                </a>
            <?php endif; ?>
                        <?php if ($id): ?>
                <span
                    class="badge text-bg-<?= e($workflowBadge) ?> align-self-center"
                >
                    <?= e($workflowLabel) ?>
                </span>

                <?php if ($workflowCanSubmit): ?>
                    <button
                        class="btn btn-outline-warning btn-sm"
                        type="submit"
                        form="workflowSubmitForm"
                    >
                        <i class="bi bi-send me-1"></i>
                        Enviar para revisão
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <a
                class="btn btn-outline-secondary btn-sm"
                href="<?= e(url('admin/noticias/revisao.php')) ?>"
            >
                <i class="bi bi-clipboard-check me-1"></i>
                Fila de revisão
            </a>
<a class="btn btn-outline-secondary btn-sm" href="<?= e(url('admin/noticias/index.php')) ?>">Todos os Posts</a>
            <button class="btn btn-primary btn-sm px-3" type="submit" form="postEditorForm">
                <i class="bi bi-check2 me-1"></i><?= $id ? 'Atualizar' : 'Salvar' ?>
            </button>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="postEditorForm">
        <?= Csrf::field() ?>

        <div class="wp-post-editor-shell">
            <main class="wp-post-editor-main">
                <div class="wp-post-editor-canvas">
                    <div class="wp-post-title-wrap">
                        <label class="visually-hidden" for="postTitulo">Título</label>
                        <input
                            id="postTitulo"
                            class="wp-post-title-input"
                            name="titulo"
                            value="<?= e((string)$post['titulo']) ?>"
                            maxlength="220"
                            placeholder="Adicionar título"
                            autocomplete="off"
                            required
                        >
                        <?php if ($id && !empty($post['slug'])): ?>
                            <div class="wp-post-permalink-preview">
                                <i class="bi bi-link-45deg"></i>
                                <a target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>"><?= e(contentUrl('noticia', (string)$post['slug'])) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="wp-post-content-wrap">
                        <label class="visually-hidden" for="conteudo">Conteúdo</label>
                        <textarea id="conteudo" name="conteudo" rows="18"><?= e((string)$post['conteudo']) ?></textarea>
                    </div>
                </div>

                <?php
                $contentBlocksTitle = 'Blocos da notícia';
                require __DIR__ . '/../_content_blocks_editor.php';
                ?>
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

            <aside class="wp-post-settings" aria-label="Configurações do Post">
                <div class="wp-settings-tabs" role="tablist">
                    <button class="wp-settings-tab active" type="button" aria-selected="true">Post</button>
                    <button class="wp-settings-tab" type="button" aria-selected="false" disabled>Bloco</button>
                    <span class="wp-settings-close" title="Configurações do post"><i class="bi bi-x-lg"></i></span>
                </div>

                <section class="wp-settings-section wp-post-overview">
                    <div class="wp-post-overview-title">
                        <i class="bi bi-feather"></i>
                        <strong id="sidebarPostTitle"><?= e(trim((string)$post['titulo']) !== '' ? (string)$post['titulo'] : 'Sem título') ?></strong>
                        <i class="bi bi-three-dots-vertical ms-auto"></i>
                    </div>

                    <div class="wp-featured-area">
                        <input type="hidden" name="imagem_capa_id" id="imagemCapaId" value="<?= e((string)($post['imagem_capa_id'] ?? '')) ?>">
                        <div id="imagemCapaPreview" class="wp-featured-preview">
                            <?php if ($imagemCapaAtual && MediaService::isImage($imagemCapaAtual)): ?>
                                <img src="<?= e(mediaUrl($imagemCapaAtual['caminho'])) ?>" alt="<?= e($imagemCapaAtual['alt_text'] ?: $imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?>">
                                <div class="wp-featured-actions">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-media-featured-open>Substituir imagem</button>
                                    <button type="button" class="btn btn-sm btn-link text-danger" data-media-featured-remove>Remover</button>
                                </div>
                            <?php else: ?>
                                <button type="button" class="wp-featured-button" data-media-featured-open>Definir imagem destacada</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="button" class="wp-summary-toggle" id="summaryToggle">Adicionar um resumo...</button>
                    <div class="wp-summary-editor <?= trim((string)($post['resumo'] ?? '')) === '' ? 'd-none' : '' ?>" id="summaryEditor">
                        <textarea class="form-control form-control-sm" name="resumo" rows="4" placeholder="Escreva um resumo curto..."><?= e((string)($post['resumo'] ?? '')) ?></textarea>
                        <div class="form-text">Usado nos cards, busca e SEO quando a descrição específica estiver vazia.</div>
                    </div>

                    <div class="wp-last-edit">
                        <?php if ($lastEdited !== ''): ?>Última edição em <?= e($lastEdited) ?><?php else: ?>Ainda não salvo<?php endif; ?>
                    </div>

                    <div class="wp-property-list">
                        <label class="wp-property-row">
                            <span>Status</span>
                            <select class="wp-property-control" name="status">
                                <?php foreach (['rascunho' => 'Rascunho', 'agendado' => 'Agendado', 'publicado' => 'Publicado', 'arquivado' => 'Arquivado'] as $v => $l): ?>
                                    <option value="<?= e($v) ?>" <?= $post['status'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="wp-property-row">
                            <span>Publicar</span>
                            <input type="datetime-local" class="wp-property-control" name="publicado_em" value="<?= $post['publicado_em'] ? e((new DateTime((string)$post['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>">
                        </label>

                        <label class="wp-property-row">
                            <span>Slug</span>
                            <input class="wp-property-control" name="slug" value="<?= e((string)($post['slug'] ?? '')) ?>" maxlength="240" placeholder="automática">
                        </label>

                        <div class="wp-property-row">
                            <span>Autor</span>
                            <span class="wp-property-value"><?= e($authorName) ?></span>
                        </div>

                        <div class="wp-property-row">
                            <span>Modelo</span>
                            <span class="wp-property-value">Modelo padrão</span>
                        </div>

                        <div class="wp-property-row">
                            <span>Discussão</span>
                            <label class="wp-property-check">
                                <input type="checkbox" name="comentarios_ativos" <?= !empty($post['comentarios_ativos']) ? 'checked' : '' ?>>
                                <span><?= !empty($post['comentarios_ativos']) ? 'Comentários' : 'Fechada' ?></span>
                            </label>
                        </div>

                        <div class="wp-property-row">
                            <span>Formato</span>
                            <span class="wp-property-value">Padrão</span>
                        </div>
                    </div>

                    <?php if ($id): ?>
                        <button class="btn btn-outline-primary w-100 mt-3" type="submit" form="duplicatePostForm">
                            Copie para um novo rascunho
                        </button>
                    <?php endif; ?>
                </section>

                <details class="wp-settings-section wp-settings-details" open>
                    <summary>Opções da notícia</summary>
                    <div class="wp-settings-section-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Comunidade</label>
                            <select class="form-select form-select-sm" name="comunidade_id">
                                <option value="">Paroquial / Todas</option>
                                <?php foreach ($comunidades as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (string)$post['comunidade_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="destaque" id="destaque" <?= $post['destaque'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="destaque">Destacar na página inicial</label>
                        </div>
                    </div>
                </details>

                <details class="wp-settings-section wp-settings-details" open>
                    <summary>Categorias <span class="wp-settings-count" id="categorySelectedCount"><?= count($selectedCategories) ?></span></summary>
                    <div class="wp-settings-section-body">
                        <div class="wp-category-search mb-3">
                            <i class="bi bi-search"></i>
                            <input type="search" id="categorySearch" placeholder="Pesquisar categorias" aria-label="Pesquisar categorias">
                        </div>
                        <div id="categoryChoices" class="wp-category-choices">
                            <?php if (!$categorias): ?>
                                <div class="text-secondary small p-2">Nenhuma categoria cadastrada.</div>
                            <?php endif; ?>
                            <?php foreach ($categorias as $categoria): ?>
                                <?php $depth = max(0, (int)($categoria['depth'] ?? 0)); ?>
                                <label class="wp-category-choice" data-category-name="<?= e(mb_strtolower((string)$categoria['nome'])) ?>" style="--category-depth:<?= $depth ?>">
                                    <input type="checkbox" name="categorias[]" value="<?= (int)$categoria['id'] ?>" <?= in_array((int)$categoria['id'], $selectedCategories, true) ? 'checked' : '' ?>>
                                    <span><?= e($categoria['nome']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <a class="wp-add-taxonomy" target="_blank" href="<?= e(url('admin/categorias/index.php')) ?>">Adicionar categoria</a>
                    </div>
                </details>

                <details class="wp-settings-section wp-settings-details" open>
                    <summary>Tags</summary>
                    <div class="wp-settings-section-body">
                        <label class="wp-tag-label" for="tagInput">ADICIONAR TAG</label>
                        <input type="text" id="tagInput" class="form-control form-control-sm" autocomplete="off" placeholder="Digite uma tag e pressione Enter">
                        <input type="hidden" id="tagsInputHidden" name="tags_input" value="<?= e($tagInputValue) ?>">
                        <div class="form-text">Separe com vírgulas ou use a tecla Enter.</div>

                        <div id="selectedTagChips" class="wp-selected-tags mt-3"></div>

                        <div class="wp-popular-title mt-3">MAIS USADAS</div>
                        <div class="wp-popular-tags">
                            <?php foreach ($popularTags as $tag): ?>
                                <button type="button" class="wp-popular-tag" data-tag-name="<?= e((string)$tag['nome']) ?>"><?= e((string)$tag['nome']) ?></button>
                            <?php endforeach; ?>
                            <?php if (!$popularTags): ?><span class="text-secondary small">Nenhuma tag cadastrada.</span><?php endif; ?>
                        </div>
                    </div>
                </details>

                <div class="wp-sidebar-save">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-check2 me-1"></i><?= $id ? 'Atualizar' : 'Salvar notícia' ?>
                    </button>
                    <?php if ($id && !empty($post['slug'])): ?>
                        <a class="btn btn-link w-100 text-decoration-none" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Visualizar notícia</a>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </form>

    <?php if ($id): ?>
        <form id="duplicatePostForm" method="post" action="<?= e(url('admin/noticias/duplicar.php')) ?>" class="d-none">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
        </form>
    <?php endif; ?>
</div>


<?php if ($id): ?>
    <form
        id="workflowSubmitForm"
        method="post"
        action="<?= e(url('admin/noticias/workflow.php')) ?>"
        class="d-none"
    >
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="return" value="editor">
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script src="<?= e(url('public/js/content-block-editor.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.43.0'))) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

ContentBlockEditor.init();

tinymce.init({
    selector: '#conteudo',
    height: 650,
    menubar: false,
    placeholder: 'Digite / para começar a escrever…',
    plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount autoresize',
    toolbar: 'undo redo | blocks fontfamily fontsize lineheight | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link portalmedia media table charmap | blockquote removeformat | searchreplace visualblocks preview fullscreen code',
    // v0.53.0 - editor avançado TinyMCE
    toolbar_mode: 'sliding',
    browser_spellcheck: true,
    contextmenu: false,
    font_family_formats:
        'Sistema=system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
        + 'Arial=Arial,Helvetica,sans-serif;'
        + 'Verdana=Verdana,Geneva,sans-serif;'
        + 'Tahoma=Tahoma,Arial,sans-serif;'
        + 'Trebuchet MS="Trebuchet MS",Arial,sans-serif;'
        + 'Georgia=Georgia,serif;'
        + 'Times New Roman="Times New Roman",Times,serif;'
        + 'Courier New="Courier New",Courier,monospace;',
    font_size_formats:
        '10px 12px 14px 16px 18px 20px 22px 24px 28px 32px 36px 42px 48px 56px 64px',
    line_height_formats:
        '1 1.15 1.25 1.5 1.75 2 2.5 3',
    // v0.52.1 - cores personalizadas do TinyMCE
    color_cols: 8,
    custom_colors: true,
    color_map: [
        '000000', 'Preto',
        '333333', 'Cinza escuro',
        '666666', 'Cinza',
        '999999', 'Cinza claro',
        'FFFFFF', 'Branco',
        'B91C1C', 'Vermelho',
        'DC2626', 'Vermelho vivo',
        'EA580C', 'Laranja',
        'F59E0B', 'Âmbar',
        'FACC15', 'Amarelo',
        '15803D', 'Verde',
        '16A34A', 'Verde vivo',
        '0F766E', 'Verde petróleo',
        '0369A1', 'Azul',
        '2563EB', 'Azul vivo',
        '4F46E5', 'Índigo',
        '7E22CE', 'Roxo',
        'C026D3', 'Magenta',
        'BE185D', 'Rosa',
        '7C2D12', 'Marrom'
    ],
    content_style: 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:17px;line-height:1.75;padding:24px 34px;max-width:920px;margin:0 auto;} img{max-width:100%;height:auto;}',
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

const titleInput = document.getElementById('postTitulo');
const sidebarTitle = document.getElementById('sidebarPostTitle');
if (titleInput && sidebarTitle) {
    titleInput.addEventListener('input', function () {
        sidebarTitle.textContent = this.value.trim() || 'Sem título';
    });
}

const summaryToggle = document.getElementById('summaryToggle');
const summaryEditor = document.getElementById('summaryEditor');
if (summaryToggle && summaryEditor) {
    summaryToggle.addEventListener('click', function () {
        summaryEditor.classList.toggle('d-none');
        if (!summaryEditor.classList.contains('d-none')) {
            summaryEditor.querySelector('textarea')?.focus();
        }
    });
}

const categorySearch = document.getElementById('categorySearch');
if (categorySearch) {
    categorySearch.addEventListener('input', function () {
        const q = this.value.trim().toLocaleLowerCase('pt-BR');
        document.querySelectorAll('#categoryChoices [data-category-name]').forEach(function (el) {
            const value = el.getAttribute('data-category-name') || '';
            el.classList.toggle('d-none', q !== '' && !value.includes(q));
        });
    });
}

const categoryChoices = document.getElementById('categoryChoices');
const categorySelectedCount = document.getElementById('categorySelectedCount');
function updateCategoryCount() {
    if (categoryChoices && categorySelectedCount) {
        categorySelectedCount.textContent = categoryChoices.querySelectorAll('input[type="checkbox"]:checked').length;
    }
}
categoryChoices?.addEventListener('change', updateCategoryCount);
updateCategoryCount();

const tagInput = document.getElementById('tagInput');
const tagsHidden = document.getElementById('tagsInputHidden');
const selectedTagChips = document.getElementById('selectedTagChips');
let selectedTagNames = [];

function normalizeTagList(value) {
    const seen = new Map();
    String(value || '').split(/[,;\n]+/).forEach(function (item) {
        const name = item.trim();
        if (!name) return;
        const key = name.toLocaleLowerCase('pt-BR');
        if (!seen.has(key)) seen.set(key, name.substring(0, 100));
    });
    return Array.from(seen.values()).slice(0, 30);
}

function syncTags() {
    selectedTagNames = normalizeTagList(selectedTagNames.join(','));
    tagsHidden.value = selectedTagNames.join(', ');
    selectedTagChips.innerHTML = '';
    selectedTagNames.forEach(function (name) {
        const chip = document.createElement('span');
        chip.className = 'wp-tag-chip';
        chip.append(document.createTextNode(name));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remover ' + name);
        remove.innerHTML = '&times;';
        remove.addEventListener('click', function () {
            selectedTagNames = selectedTagNames.filter(function (v) { return v.toLocaleLowerCase('pt-BR') !== name.toLocaleLowerCase('pt-BR'); });
            syncTags();
        });
        chip.appendChild(remove);
        selectedTagChips.appendChild(chip);
    });
}

function addTags(value) {
    selectedTagNames = selectedTagNames.concat(normalizeTagList(value));
    syncTags();
}

selectedTagNames = normalizeTagList(tagsHidden.value);
syncTags();

if (tagInput) {
    tagInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            addTags(this.value);
            this.value = '';
        }
    });
    tagInput.addEventListener('blur', function () {
        if (this.value.trim() !== '') {
            addTags(this.value);
            this.value = '';
        }
    });
}

document.querySelectorAll('[data-tag-name]').forEach(function (button) {
    button.addEventListener('click', function () { addTags(this.dataset.tagName || ''); });
});

PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('imagemCapaId'),
    preview: document.getElementById('imagemCapaPreview')
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
