<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('paginas.gerenciar');
$pdo = Database::connection();
PageHierarchyService::ensureSchema($pdo);
ContentBlockService::ensureSchema($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pagina = [
    'parent_id' => '',
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
    if (($found['status'] ?? '') === 'lixeira') {
        Session::flash('error', 'Restaure a página da Lixeira antes de editá-la.');
        header('Location: ' . url('admin/paginas/index.php?status=lixeira'));
        exit;
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
$revisionCount = 0;
if ($id) { try { $revisionCount = RevisionService::count($pdo, 'pagina', $id); } catch (Throwable $ignored) { $revisionCount = 0; } }

$pageOptions = PageHierarchyService::options($pdo, $id);
$contentBlocks = $id
    ? ContentBlockService::loadForEditor($pdo, 'pagina', $id)
    : [];
// v0.44.0 - padrões reutilizáveis: páginas
$contentPatterns = ContentPatternService::activeFor(
    $pdo,
    'pagina'
);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['titulo', 'slug', 'resumo', 'seo_titulo', 'seo_descricao', 'conteudo', 'imagem_capa_id', 'parent_id', 'status', 'ordem', 'publicado_em'] as $field) {
        if (array_key_exists($field, $_POST)) {
            $pagina[$field] = $_POST[$field];
        }
    }
    $pagina['exibir_menu'] = isset($_POST['exibir_menu']) ? 1 : 0;
    $pagina['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    $contentBlocks = ContentBlockService::prepareForEditor(
        $pdo,
        ContentBlockService::fromJson(
            $pdo,
            (string)($_POST['content_blocks_json'] ?? '[]')
        )
    );

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

                $parentId = PageHierarchyService::validateParent(
                    $pdo,
                    $id,
                    ($_POST['parent_id'] ?? '') !== ''
                        ? (int)$_POST['parent_id']
                        : null
                );

                $data = [
                    'autor_id' => Auth::id(),                    'parent_id' => $parentId,

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
                            parent_id = :parent_id,
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
                            (autor_id, parent_id, titulo, slug, resumo, conteudo, imagem_capa_id, seo_titulo, seo_descricao, seo_noindex, status, exibir_menu, ordem, publicado_em)
                         VALUES
                            (:autor_id, :parent_id, :titulo, :slug, :resumo, :conteudo, :imagem_capa_id, :seo_titulo, :seo_descricao, :seo_noindex, :status, :exibir_menu, :ordem, :publicado_em)'
                    );
                }

                $pdo->beginTransaction();
                try {
                    if ($id) {
                        RevisionService::create($pdo, 'pagina', $id, Auth::id());
                    }
                    $stmt->execute($data);
                    $savedId = $id ?: (int)$pdo->lastInsertId();

                    ContentBlockService::save(
                        $pdo,
                        'pagina',
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
                logAction($pdo, $id ? 'pagina.editar' : 'pagina.criar', 'paginas', $savedId, $titulo);
                /* v0.84.0 - autosave_limpo_apos_salvar_pagina */
                if (class_exists('ContentAutosaveService')) {
                    try {
                        ContentAutosaveService::delete(
                            $pdo,
                            (int)Auth::id(),
                            'pagina',
                            $id ? (int)$id : 0
                        );
                    } catch (Throwable $ignored) {
                    }
                }
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

$authUser =
    Auth::user()
    ?: [];

$authorName =
    (string)(
        $authUser['nome']
        ?? $authUser['email']
        ?? 'Usuário'
    );

$lastEdited = '';

if (
    $id
    && !empty(
        $pagina['updated_at']
    )
) {
    try {
        $lastEdited =
            (
                new DateTime(
                    (string)$pagina['updated_at']
                )
            )->format(
                'd/m/Y H:i'
            );
    } catch (Throwable $ignored) {
        $lastEdited = '';
    }
}

require __DIR__ . '/../_header.php';
?>

<div class="wp-post-editor-page">
    <div class="wp-editor-toolbar mb-3">
        <div>
            <div class="text-uppercase small text-secondary fw-semibold mb-1">
                Páginas
            </div>

            <h1 class="h4 mb-0">
                <?= e($pageTitle) ?>
            </h1>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($id): ?>
                <a
                    class="btn btn-outline-secondary"
                    href="<?= e(
                        url(
                            'admin/revisoes/index.php?tipo=pagina&id='
                            . $id
                        )
                    ) ?>"
                >
                    <i class="bi bi-clock-history me-1"></i>
                    Revisões
                    (<?= (int)$revisionCount ?>)
                </a>
            <?php endif; ?>

            <?php if (
                $id
                && ($pagina['status'] ?? '') === 'publicado'
                && !empty($pagina['slug'])
            ): ?>
                <a
                    class="btn btn-outline-primary"
                    target="_blank"
                    href="<?= e(
                        contentUrl(
                            'pagina',
                            (string)$pagina['slug']
                        )
                    ) ?>"
                >
                    <i class="bi bi-box-arrow-up-right me-1"></i>
                    Visualizar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form
        method="post"
        enctype="multipart/form-data"
        id="pageEditorForm"
    >
        <?= Csrf::field() ?>

        <div class="wp-post-editor-shell">
            <main class="wp-post-editor-main">
                <div class="wp-post-editor-canvas">
                    <div class="wp-post-title-wrap">
                        <label
                            class="visually-hidden"
                            for="pageTitulo"
                        >
                            Título
                        </label>

                        <input
                            id="pageTitulo"
                            class="wp-post-title-input"
                            name="titulo"
                            value="<?= e((string)$pagina['titulo']) ?>"
                            maxlength="220"
                            placeholder="Adicionar título"
                            autocomplete="off"
                            required
                        >

                        <?php if (
                            $id
                            && !empty(
                                $pagina['slug']
                            )
                        ): ?>
                            <div class="wp-post-permalink-preview">
                                <i class="bi bi-link-45deg"></i>

                                <a
                                    target="_blank"
                                    href="<?= e(
                                        contentUrl(
                                            'pagina',
                                            (string)$pagina['slug']
                                        )
                                    ) ?>"
                                >
                                    <?= e(
                                        contentUrl(
                                            'pagina',
                                            (string)$pagina['slug']
                                        )
                                    ) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="wp-post-content-wrap">
                        <label
                            class="visually-hidden"
                            for="conteudo"
                        >
                            Conteúdo
                        </label>

                        <textarea
                            id="conteudo"
                            name="conteudo"
                            rows="18"
                        ><?= e((string)$pagina['conteudo']) ?></textarea>
                    </div>
                </div>

                <?php
                $contentBlocksTitle =
                    'Blocos da página';

                require __DIR__
                    . '/../_content_blocks_editor.php';
                ?>

                <section class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white fw-semibold py-3">
                        SEO do conteúdo
                    </div>

                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">
                                    Título SEO
                                </label>

                                <input
                                    class="form-control"
                                    name="seo_titulo"
                                    maxlength="180"
                                    value="<?= e(
                                        (string)(
                                            $pagina['seo_titulo']
                                            ?? ''
                                        )
                                    ) ?>"
                                    placeholder="Se vazio, usa o título da página"
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label">
                                    Meta description
                                </label>

                                <textarea
                                    class="form-control"
                                    name="seo_descricao"
                                    maxlength="320"
                                    rows="3"
                                ><?= e(
                                    (string)(
                                        $pagina['seo_descricao']
                                        ?? ''
                                    )
                                ) ?></textarea>
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="seo_noindex"
                                        id="seoNoindex"
                                        <?= !empty(
                                            $pagina['seo_noindex']
                                        )
                                            ? 'checked'
                                            : '' ?>
                                    >

                                    <label
                                        class="form-check-label"
                                        for="seoNoindex"
                                    >
                                        Não indexar esta página
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside
                class="wp-post-settings"
                aria-label="Configurações da Página"
            >
                <div
                    class="wp-settings-tabs"
                    role="tablist"
                >
                    <button
                        class="wp-settings-tab active"
                        type="button"
                        aria-selected="true"
                    >
                        Página
                    </button>

                    <button
                        class="wp-settings-tab"
                        type="button"
                        aria-selected="false"
                        disabled
                    >
                        Bloco
                    </button>

                    <span
                        class="wp-settings-close"
                        title="Configurações da página"
                    >
                        <i class="bi bi-x-lg"></i>
                    </span>
                </div>

                <section class="wp-settings-section wp-post-overview">
                    <div class="wp-post-overview-title">
                        <i class="bi bi-file-earmark-text"></i>

                        <strong id="sidebarPageTitle">
                            <?= e(
                                trim(
                                    (string)$pagina['titulo']
                                ) !== ''
                                    ? (string)$pagina['titulo']
                                    : 'Sem título'
                            ) ?>
                        </strong>

                        <i class="bi bi-three-dots-vertical ms-auto"></i>
                    </div>

                    <div class="wp-featured-area">
                        <input
                            type="hidden"
                            name="imagem_capa_id"
                            id="imagemCapaId"
                            value="<?= e(
                                (string)(
                                    $pagina['imagem_capa_id']
                                    ?? ''
                                )
                            ) ?>"
                        >

                        <div
                            id="imagemCapaPreview"
                            class="wp-featured-preview"
                        >
                            <?php if (
                                $imagemCapaAtual
                                && MediaService::isImage(
                                    $imagemCapaAtual
                                )
                            ): ?>
                                <img
                                    src="<?= e(
                                        mediaUrl(
                                            $imagemCapaAtual['caminho']
                                        )
                                    ) ?>"
                                    alt="<?= e(
                                        $imagemCapaAtual['alt_text']
                                        ?: $imagemCapaAtual['titulo']
                                        ?: $imagemCapaAtual['nome_original']
                                    ) ?>"
                                >

                                <div class="wp-featured-actions">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-media-featured-open
                                    >
                                        Substituir imagem
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-link text-danger"
                                        data-media-featured-remove
                                    >
                                        Remover
                                    </button>
                                </div>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="wp-featured-button"
                                    data-media-featured-open
                                >
                                    Definir imagem destacada
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="wp-summary-toggle"
                        id="summaryToggle"
                    >
                        Adicionar um resumo...
                    </button>

                    <div
                        class="wp-summary-editor <?= trim(
                            (string)(
                                $pagina['resumo']
                                ?? ''
                            )
                        ) === ''
                            ? 'd-none'
                            : '' ?>"
                        id="summaryEditor"
                    >
                        <textarea
                            class="form-control form-control-sm"
                            name="resumo"
                            rows="4"
                            placeholder="Escreva um resumo curto..."
                        ><?= e(
                            (string)(
                                $pagina['resumo']
                                ?? ''
                            )
                        ) ?></textarea>

                        <div class="form-text">
                            Usado para SEO e contextos resumidos. Não aparece na leitura da página.
                        </div>
                    </div>

                    <div class="wp-last-edit">
                        <?php if ($lastEdited !== ''): ?>
                            Última edição em
                            <?= e($lastEdited) ?>
                        <?php else: ?>
                            Ainda não salvo
                        <?php endif; ?>
                    </div>

                    <div class="wp-property-list">
                        <label class="wp-property-row">
                            <span>Status</span>

                            <select
                                class="wp-property-control"
                                name="status"
                            >
                                <?php foreach (
                                    [
                                        'rascunho' => 'Rascunho',
                                        'agendado' => 'Agendado',
                                        'publicado' => 'Publicado',
                                        'arquivado' => 'Arquivado',
                                    ]
                                    as $value => $label
                                ): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= (
                                            $pagina['status']
                                            ?? ''
                                        ) === $value
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="wp-property-row">
                            <span>Publicar</span>

                            <input
                                type="datetime-local"
                                class="wp-property-control"
                                name="publicado_em"
                                value="<?= !empty(
                                    $pagina['publicado_em']
                                )
                                    ? e(
                                        (
                                            new DateTime(
                                                (string)$pagina['publicado_em']
                                            )
                                        )->format(
                                            'Y-m-d\TH:i'
                                        )
                                    )
                                    : '' ?>"
                            >
                        </label>

                        <label class="wp-property-row">
                            <span>Slug</span>

                            <input
                                class="wp-property-control"
                                name="slug"
                                value="<?= e(
                                    (string)(
                                        $pagina['slug']
                                        ?? ''
                                    )
                                ) ?>"
                                maxlength="240"
                                placeholder="automática"
                            >
                        </label>

                        <div class="wp-property-row">
                            <span>Autor</span>

                            <span class="wp-property-value">
                                <?= e($authorName) ?>
                            </span>
                        </div>

                        <div class="wp-property-row">
                            <span>Modelo</span>

                            <span class="wp-property-value">
                                Página padrão
                            </span>
                        </div>
                    </div>
                </section>

                <details
                    class="wp-settings-section wp-settings-details"
                    open
                >
                    <summary>
                        Hierarquia e menu
                    </summary>

                    <div class="wp-settings-section-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">
                                Página superior
                            </label>

                            <select
                                class="form-select form-select-sm"
                                name="parent_id"
                            >
                                <option value="">
                                    Nenhuma — página principal
                                </option>

                                <?php foreach ($pageOptions as $option): ?>
                                    <?php
                                    $depth =
                                        max(
                                            0,
                                            (int)(
                                                $option['depth']
                                                ?? 0
                                            )
                                        );
                                    ?>

                                    <option
                                        value="<?= (int)$option['id'] ?>"
                                        <?= (int)(
                                            $pagina['parent_id']
                                            ?? 0
                                        ) === (int)$option['id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e(
                                            str_repeat(
                                                '— ',
                                                $depth
                                            )
                                            . (string)$option['titulo']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="form-text">
                                A URL usa toda a hierarquia da página.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">
                                Ordem
                            </label>

                            <input
                                type="number"
                                class="form-control form-control-sm"
                                name="ordem"
                                value="<?= (int)(
                                    $pagina['ordem']
                                    ?? 0
                                ) ?>"
                                step="1"
                            >
                        </div>

                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="exibir_menu"
                                id="exibirMenu"
                                <?= !empty(
                                    $pagina['exibir_menu']
                                )
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                class="form-check-label"
                                for="exibirMenu"
                            >
                                Exibir no menu público
                            </label>
                        </div>
                    </div>
                </details>

                <div class="wp-settings-section px-3 py-3">
                    <div class="small text-secondary">
                        A imagem destacada é escolhida acima pela Biblioteca de Mídia.
                        A exibição da capa na página pública é definida globalmente em
                        Configurações → Mídia.
                    </div>
                </div>

                <div class="wp-sidebar-save">
                    <button
                        class="btn btn-primary w-100"
                        type="submit"
                    >
                        <i class="bi bi-check2 me-1"></i>
                        <?= $id
                            ? 'Atualizar'
                            : 'Salvar página' ?>
                    </button>

                    <?php if (
                        $id
                        && !empty(
                            $pagina['slug']
                        )
                    ): ?>
                        <a
                            class="btn btn-link w-100 text-decoration-none"
                            target="_blank"
                            href="<?= e(
                                contentUrl(
                                    'pagina',
                                    (string)$pagina['slug']
                                )
                            ) ?>"
                        >
                            Visualizar página
                        </a>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../_editor_media_picker.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script src="<?= e(
    url(
        'public/js/content-block-editor.js?v='
        . rawurlencode(
            defined('APP_VERSION')
                ? (string)APP_VERSION
                : '0.82.0'
        )
    )
) ?>"></script>

<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(
        url('admin/midias/upload-editor.php'),
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    ) ?>,
    csrfToken: <?= json_encode(
        Csrf::token(),
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    ) ?>
});

ContentBlockEditor.init();

tinymce.init({
    selector: '#conteudo',
    height: 520,
    menubar: false,
    plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount',
    toolbar: 'undo redo | blocks fontfamily fontsize lineheight | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link portalmedia media table charmap | blockquote removeformat | searchreplace visualblocks preview fullscreen code',
    setup: function(editor) {
        editor.ui.registry.addButton('portalmedia', {
            icon: 'image',
            tooltip: 'Inserir imagens da Biblioteca de Mídia',
            onAction: function() {
                PortalMediaPicker.openForEditor(editor);
            }
        });

        editor.on('change keyup', function() {
            editor.save();
        });
    }
});

const pageEditorForm =
    document.getElementById('pageEditorForm');

if (pageEditorForm) {
    pageEditorForm.addEventListener(
        'submit',
        function() {
            if (
                typeof tinymce !== 'undefined'
            ) {
                tinymce.triggerSave();
            }
        }
    );
}

PortalMediaPicker.bindFeatured({
    openButton:
        document.querySelector(
            '[data-media-featured-open]'
        ),
    removeButtonSelector:
        '[data-media-featured-remove]',
    input:
        document.getElementById(
            'imagemCapaId'
        ),
    preview:
        document.getElementById(
            'imagemCapaPreview'
        )
});

const pageTitleInput =
    document.getElementById(
        'pageTitulo'
    );

const sidebarPageTitle =
    document.getElementById(
        'sidebarPageTitle'
    );

if (
    pageTitleInput
    && sidebarPageTitle
) {
    pageTitleInput.addEventListener(
        'input',
        function() {
            sidebarPageTitle.textContent =
                pageTitleInput.value.trim()
                || 'Sem título';
        }
    );
}

const summaryToggle =
    document.getElementById(
        'summaryToggle'
    );

const summaryEditor =
    document.getElementById(
        'summaryEditor'
    );

if (
    summaryToggle
    && summaryEditor
) {
    summaryToggle.addEventListener(
        'click',
        function() {
            summaryEditor.classList.toggle(
                'd-none'
            );

            if (
                !summaryEditor.classList.contains(
                    'd-none'
                )
            ) {
                const textarea =
                    summaryEditor.querySelector(
                        'textarea'
                    );

                if (textarea) {
                    textarea.focus();
                }
            }
        }
    );
}
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
