<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('galerias.gerenciar');
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$galeria = [
    'titulo' => '',
    'slug' => '',
    'descricao' => '',
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
    'imagem_capa_id' => '',
    'status' => 'rascunho',
    'publicado_em' => '',
];
$error = '';

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM galerias WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Galeria não encontrada.');
    }
    $galeria = $found;
}

$images = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC"
)->fetchAll();
$midias = $images;
$galleryImagesById = [];
foreach ($images as $galleryImage) {
    $galleryImagesById[(int)$galleryImage['id']] = $galleryImage;
}
$currentGalleryCover =
    !empty($galeria['imagem_capa_id'])
        ? MediaService::find($pdo, (int)$galeria['imagem_capa_id'])
        : null;

$selected = [];
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT midia_id, legenda, ordem FROM galeria_midias WHERE galeria_id = :id ORDER BY ordem, id');
    $stmt->execute(['id' => $id]);
    foreach ($stmt->fetchAll() as $item) {
        $selected[(int)$item['midia_id']] = [
            'legenda' => (string)($item['legenda'] ?? ''),
            'ordem' => (int)$item['ordem'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $galeria = array_merge($galeria, $_POST);
    $galeria['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            if ($titulo === '') {
                throw new RuntimeException('Informe o título da galeria.');
            }

            // O status fica no fim do formulário. Em instalações com muitas
            // imagens, max_input_vars pode truncar o POST antes de chegar aqui.
            // Não transforme silenciosamente esse caso em "rascunho".
            if (!array_key_exists('status', $_POST)) {
                throw new RuntimeException(
                    'O formulário foi recebido incompleto. Verifique o limite max_input_vars do PHP e tente novamente.'
                );
            }

            $status = (string)$_POST['status'];
            if (!in_array($status, ['rascunho', 'publicado', 'arquivado'], true)) {
                throw new RuntimeException('Status inválido.');
            }

            $slugSource = trim((string)($_POST['slug'] ?? '')) ?: $titulo;
            $slug = uniqueSlug($pdo, 'galerias', $slugSource, $id > 0 ? $id : null);
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $capaId = (int)($_POST['imagem_capa_id'] ?? 0);

            $publicadoEmInput = trim((string)($_POST['publicado_em'] ?? ''));
            $publicadoEm = null;
            if ($publicadoEmInput !== '') {
                $timestamp = strtotime($publicadoEmInput);
                if ($timestamp === false) {
                    throw new RuntimeException('Data de publicação inválida.');
                }
                $publicadoEm = date('Y-m-d H:i:s', $timestamp);
            }

            if ($status === 'publicado' && !$publicadoEm) {
                $publicadoEm = date('Y-m-d H:i:s');
            }

            $validImageIds = array_fill_keys(
                array_map(static fn($img) => (int)$img['id'], $images),
                true
            );

            if ($capaId > 0 && !isset($validImageIds[$capaId])) {
                throw new RuntimeException('A imagem de capa selecionada não é válida.');
            }

            $midias = array_values(array_unique(array_map('intval', $_POST['midias'] ?? [])));
            foreach ($midias as $midiaId) {
                if ($midiaId <= 0 || !isset($validImageIds[$midiaId])) {
                    throw new RuntimeException('Uma das imagens selecionadas não é válida.');
                }
            }

            $pdo->beginTransaction();

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE galerias SET
                        titulo=:titulo,
                        slug=:slug,
                        descricao=:descricao,
                        imagem_capa_id=:capa,
                        seo_titulo=:seo_titulo,
                        seo_descricao=:seo_descricao,
                        seo_noindex=:seo_noindex,
                        status=:status,
                        publicado_em=:publicado_em
                     WHERE id=:id'
                );

                $stmt->execute([
                    'titulo' => $titulo,
                    'slug' => $slug,
                    'descricao' => $descricao ?: null,
                    'capa' => $capaId ?: null,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status,
                    'publicado_em' => $publicadoEm,
                    'id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO galerias
                        (autor_id,titulo,slug,descricao,imagem_capa_id,seo_titulo,seo_descricao,seo_noindex,status,publicado_em)
                     VALUES
                        (:autor,:titulo,:slug,:descricao,:capa,:seo_titulo,:seo_descricao,:seo_noindex,:status,:publicado_em)'
                );

                $stmt->execute([
                    'autor' => (int)Auth::id(),
                    'titulo' => $titulo,
                    'slug' => $slug,
                    'descricao' => $descricao ?: null,
                    'capa' => $capaId ?: null,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status,
                    'publicado_em' => $publicadoEm,
                ]);

                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare(
                'DELETE FROM galeria_midias WHERE galeria_id = :id'
            )->execute(['id' => $id]);

            $insert = $pdo->prepare(
                'INSERT INTO galeria_midias (galeria_id,midia_id,legenda,ordem)
                 VALUES (:galeria,:midia,:legenda,:ordem)'
            );

            $legendas = $_POST['legenda'] ?? [];
            $ordens = $_POST['ordem'] ?? [];
            $autoOrder = 10;

            foreach ($midias as $midiaId) {
                $ordem = isset($ordens[$midiaId]) && is_numeric($ordens[$midiaId])
                    ? (int)$ordens[$midiaId]
                    : $autoOrder;

                $legenda = trim((string)($legendas[$midiaId] ?? ''));

                $insert->execute([
                    'galeria' => $id,
                    'midia' => $midiaId,
                    'legenda' => $legenda !== '' ? mb_substr($legenda, 0, 255) : null,
                    'ordem' => $ordem,
                ]);

                $autoOrder += 10;
            }

            $pdo->commit();

            logAction(
                $pdo,
                'galeria.salvar',
                'galerias',
                $id,
                $titulo . ' · ' . count($midias) . ' foto(s)'
            );

            Session::flash('success', 'Galeria salva com sucesso.');
            header('Location: ' . url('admin/galerias/index.php'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }

    $selected = [];
    foreach (array_map('intval', $_POST['midias'] ?? []) as $midiaId) {
        $selected[$midiaId] = [
            'legenda' => (string)(($_POST['legenda'] ?? [])[$midiaId] ?? ''),
            'ordem' => (int)(($_POST['ordem'] ?? [])[$midiaId] ?? 0),
        ];
    }
}

$pageTitle = $id > 0 ? 'Editar galeria' : 'Nova galeria';
require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $id > 0 ? 'Editar galeria' : 'Nova galeria' ?></h1>
        <p class="text-secondary mb-0">Monte um álbum reutilizando imagens da Biblioteca de Mídia.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/galerias/index.php')) ?>">Voltar</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input
                            class="form-control"
                            name="titulo"
                            value="<?= e((string)$galeria['titulo']) ?>"
                            required
                            maxlength="220"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input
                            class="form-control"
                            name="slug"
                            value="<?= e((string)$galeria['slug']) ?>"
                            placeholder="gerada automaticamente"
                        >
                        <div class="form-text">URL pública: /galeria/slug-da-galeria</div>
                    </div>

                    <div>
                        <label class="form-label">Descrição</label>
                        <textarea
                            class="form-control"
                            name="descricao"
                            rows="5"
                        ><?= e((string)$galeria['descricao']) ?></textarea>
                    </div>
                </div>
            </div>

                        <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">Fotos da galeria</span>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        id="galleryPhotosOpen"
                    >
                        <i class="bi bi-images me-1"></i>
                        Adicionar/editar fotos
                    </button>
                </div>

                <div class="card-body p-4">
                    <p class="text-secondary small">
                        As fotos são escolhidas no modal da Biblioteca de Mídia.
                        Você pode selecionar várias de uma vez e fazer upload sem sair da galeria.
                    </p>

                    <div id="gallerySelectedImages">
                        <?php foreach ($selected as $imageId => $meta): ?>
                            <?php
                            $imageId = (int)$imageId;
                            $image = $galleryImagesById[$imageId] ?? null;
                            if (!$image) {
                                continue;
                            }
                            ?>
                            <div
                                class="border rounded-3 p-2 mb-2"
                                data-r5-gallery-item
                                data-media-id="<?= $imageId ?>"
                            >
                                <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center">
                                    <img
                                        src="<?= e(mediaUrl((string)$image['caminho'])) ?>"
                                        alt="<?= e($image['alt_text'] ?: $image['titulo'] ?: $image['nome_original']) ?>"
                                        class="img-thumbnail flex-shrink-0"
                                        style="width:120px;height:82px;object-fit:cover"
                                    >

                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold text-truncate mb-2">
                                            <?= e($image['titulo'] ?: $image['nome_original']) ?>
                                        </div>

                                        <input
                                            type="hidden"
                                            name="midias[]"
                                            value="<?= $imageId ?>"
                                        >

                                        <div class="row g-2">
                                            <div class="col-md-8">
                                                <input
                                                    class="form-control form-control-sm"
                                                    name="legenda[<?= $imageId ?>]"
                                                    value="<?= e((string)($meta['legenda'] ?? '')) ?>"
                                                    placeholder="Legenda opcional"
                                                    data-r5-caption
                                                >
                                            </div>

                                            <div class="col-md-4">
                                                <input
                                                    class="form-control form-control-sm"
                                                    type="number"
                                                    name="ordem[<?= $imageId ?>]"
                                                    value="<?= e((string)($meta['ordem'] ?? '')) ?>"
                                                    placeholder="Ordem"
                                                    data-r5-order
                                                >
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger flex-shrink-0"
                                        data-r5-gallery-remove
                                        title="Remover foto da galeria"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                            <?php
                            $statusOptions = [
                                'rascunho' => 'Rascunho',
                                'publicado' => 'Publicado',
                                'arquivado' => 'Arquivado',
                            ];
                            ?>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option
                                    value="<?= e($value) ?>"
                                    <?= (string)$galeria['status'] === $value ? 'selected' : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Data de publicação</label>
                        <input
                            class="form-control"
                            type="datetime-local"
                            name="publicado_em"
                            value="<?= e(
                                !empty($galeria['publicado_em'])
                                    ? date('Y-m-d\TH:i', strtotime((string)$galeria['publicado_em']))
                                    : ''
                            ) ?>"
                        >
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">SEO do conteúdo</div>

                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Título SEO</label>
                        <input
                            class="form-control"
                            name="seo_titulo"
                            maxlength="180"
                            value="<?= e((string)($galeria['seo_titulo'] ?? '')) ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Meta description</label>
                        <textarea
                            class="form-control"
                            name="seo_descricao"
                            maxlength="320"
                            rows="3"
                        ><?= e((string)($galeria['seo_descricao'] ?? '')) ?></textarea>
                    </div>

                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="seo_noindex"
                            id="seoNoindex"
                            <?= !empty($galeria['seo_noindex']) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="seoNoindex">
                            Não indexar esta galeria
                        </label>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Imagem de capa</div>

                <div class="card-body p-4">
                                        <input
                        type="hidden"
                        name="imagem_capa_id"
                        id="galleryCoverId"
                        value="<?= e((string)$galeria['imagem_capa_id']) ?>"
                    >

                    <div id="galleryCoverPreview">
                        <?php if (
                            $currentGalleryCover
                            && MediaService::isImage($currentGalleryCover)
                        ): ?>
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <img
                                    src="<?= e(mediaUrl((string)$currentGalleryCover['caminho'])) ?>"
                                    alt="<?= e(
                                        (string)(
                                            $currentGalleryCover['alt_text']
                                            ?: $currentGalleryCover['titulo']
                                            ?: $currentGalleryCover['nome_original']
                                        )
                                    ) ?>"
                                    class="img-thumbnail featured-preview"
                                >

                                <div>
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)(
                                                $currentGalleryCover['titulo']
                                                ?: $currentGalleryCover['nome_original']
                                            )
                                        ) ?>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-link text-danger p-0 mt-1"
                                        data-media-featured-remove
                                    >
                                        Remover imagem
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small">
                                Sem imagem de capa.
                            </div>
                        <?php endif; ?>
                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-primary w-100 mt-3"
                        id="galleryCoverOpen"
                    >
                        <i class="bi bi-images me-1"></i>
                        Escolher capa na Biblioteca
                    </button>

                    <div class="form-text">
                        A capa não precisa obrigatoriamente fazer parte do álbum.
                    </div>
                </div>
            </div>

            <button class="btn btn-primary w-100 py-2">
                Salvar galeria
            </button>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="<?= e(url('public/js/editor-media-picker.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.89.0'))) ?>"></script>
<script src="<?= e(url('public/js/admin-image-modal-v89-r5.js?v=0.89.0-r5')) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

PortalAdminImageModal.bindSingle({
    openButton: document.getElementById('galleryCoverOpen'),
    input: document.getElementById('galleryCoverId'),
    preview: document.getElementById('galleryCoverPreview'),
    title: 'Escolher capa da galeria',
    subtitle: 'Selecione uma imagem da Biblioteca de Mídia para usar como capa.',
    confirmText: 'Usar como capa'
});

PortalAdminImageModal.bindMultiple({
    openButton: document.getElementById('galleryPhotosOpen'),
    container: document.getElementById('gallerySelectedImages'),
    title: 'Selecionar fotos da galeria',
    subtitle: 'Marque uma ou várias imagens. Você também pode fazer upload dentro deste modal.',
    confirmText: 'Usar fotos selecionadas',
    emptyText: 'Nenhuma foto selecionada para esta galeria.'
});
</script>



<?php require __DIR__ . '/../_footer.php'; ?>
