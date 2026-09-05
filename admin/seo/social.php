<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();
$defaults = [
    'seo_social_title' => '',
    'seo_social_description' => '',
    'seo_og_image_id' => '',
    'seo_open_graph_ativo' => '1',
    'seo_twitter_card_ativo' => '1',
    'seo_twitter_site' => '',
];
$settings = array_merge($defaults, siteConfigAll($pdo));
$error = '';
$midias = $pdo->query(
    "SELECT id,caminho,titulo,alt_text,nome_original,largura,altura 
     FROM midias 
     WHERE mime_type LIKE 'image/%' 
     ORDER BY id DESC 
     LIMIT 500"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['seo_social_title'] = trim((string)($_POST['seo_social_title'] ?? ''));
    $settings['seo_social_description'] = trim((string)($_POST['seo_social_description'] ?? ''));
    $settings['seo_og_image_id'] = trim((string)($_POST['seo_og_image_id'] ?? ''));
    $settings['seo_twitter_site'] = trim((string)($_POST['seo_twitter_site'] ?? ''));
    $settings['seo_open_graph_ativo'] = isset($_POST['seo_open_graph_ativo']) ? '1' : '0';
    $settings['seo_twitter_card_ativo'] = isset($_POST['seo_twitter_card_ativo']) ? '1' : '0';

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            if (mb_strlen($settings['seo_social_title']) > 180) throw new RuntimeException('O título social deve ter no máximo 180 caracteres.');
            if (mb_strlen($settings['seo_social_description']) > 320) throw new RuntimeException('A descrição social deve ter no máximo 320 caracteres.');
            if ($settings['seo_twitter_site'] !== '' && !preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $settings['seo_twitter_site'])) throw new RuntimeException('Informe um usuário válido do X/Twitter, como @usuario.');

            if ($settings['seo_og_image_id'] !== '') {
                $selectedImage = MediaService::find($pdo, (int)$settings['seo_og_image_id']);
                if (!$selectedImage || !MediaService::isImage($selectedImage)) throw new RuntimeException('A imagem social selecionada é inválida.');
            }

            if (isset($_FILES['seo_og_image_upload']) && (int)($_FILES['seo_og_image_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $media = MediaService::upload($pdo, $_FILES['seo_og_image_upload'], (int)Auth::id(), 'Imagem social padrão', 'Imagem de compartilhamento');
                if (!MediaService::isImage($media)) { MediaService::delete($pdo, (int)$media['id']); throw new RuntimeException('A imagem social precisa ser uma imagem válida.'); }
                $settings['seo_og_image_id'] = (string)$media['id'];
            }
            foreach ($defaults as $key => $_) saveSiteConfig($pdo, $key, $settings[$key], in_array($key,['seo_og_image_id'],true)?'numero':'texto');
            logAction($pdo, 'seo.social.atualizar', 'configuracoes', null, 'Configurações de compartilhamento social atualizadas');
            Session::flash('success', 'Configurações sociais atualizadas.');
            header('Location: ' . url('admin/seo/social.php')); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$selectedOgImage =
    !empty($settings['seo_og_image_id'])
        ? MediaService::find(
            $pdo,
            (int)$settings['seo_og_image_id']
        )
        : null;
$pageTitle = 'SEO - Social';
require __DIR__ . '/../_header.php';
?>
<div class="mb-4"><h1 class="h3 mb-1">SEO · Social</h1><p class="text-secondary mb-0">Controle a aparência dos links compartilhados em redes sociais e mensageiros.</p></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body p-4">
<?= Csrf::field() ?>
<div class="row g-3">
    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="seo_open_graph_ativo" id="ogActive" <?= $settings['seo_open_graph_ativo']==='1'?'checked':'' ?>><label class="form-check-label" for="ogActive">Ativar Open Graph (Facebook, WhatsApp e outros)</label></div></div>
    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="seo_twitter_card_ativo" id="twActive" <?= $settings['seo_twitter_card_ativo']==='1'?'checked':'' ?>><label class="form-check-label" for="twActive">Ativar Twitter/X Card</label></div></div>
    <div class="col-12"><label class="form-label">Título social padrão</label><input class="form-control" name="seo_social_title" maxlength="180" value="<?= e($settings['seo_social_title']) ?>" placeholder="Se vazio, usa o título SEO padrão"></div>
    <div class="col-12"><label class="form-label">Descrição social padrão</label><textarea class="form-control" name="seo_social_description" maxlength="320" rows="3" placeholder="Se vazia, usa a meta description padrão"><?= e($settings['seo_social_description']) ?></textarea></div>
    <div class="col-md-6"><label class="form-label">Usuário no X/Twitter</label><input class="form-control" name="seo_twitter_site" value="<?= e($settings['seo_twitter_site']) ?>" placeholder="@ieclbparobe"></div>
        <div class="col-12">
        <label class="form-label">
            Imagem social padrão
        </label>

        <input
            type="hidden"
            name="seo_og_image_id"
            id="seoOgImageId"
            value="<?= e((string)$settings['seo_og_image_id']) ?>"
        >

        <div class="border rounded-3 p-3 bg-body-tertiary">
            <div
                id="seoOgImagePreview"
                class="mb-3"
            >
                <?php if (
                    $selectedOgImage
                    && MediaService::isImage($selectedOgImage)
                ): ?>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <img
                            src="<?= e(mediaUrl((string)$selectedOgImage['caminho'])) ?>"
                            alt="<?= e(
                                (string)(
                                    $selectedOgImage['alt_text']
                                    ?: $selectedOgImage['titulo']
                                    ?: $selectedOgImage['nome_original']
                                )
                            ) ?>"
                            class="img-thumbnail featured-preview"
                        >

                        <div>
                            <div class="fw-semibold">
                                <?= e(
                                    (string)(
                                        $selectedOgImage['titulo']
                                        ?: $selectedOgImage['nome_original']
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
                        Nenhuma imagem social selecionada.
                    </div>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button
                    type="button"
                    class="btn btn-outline-primary"
                    id="seoOgImageOpen"
                >
                    <i class="bi bi-images me-1"></i>
                    Escolher na Biblioteca de Mídia
                </button>

                <span class="form-text m-0">
                    Recomendado: 1200 × 630 px.
                </span>
            </div>
        </div>
    </div>
</div>
<div class="mt-4"><button class="btn btn-primary px-4">Salvar alterações</button></div>
</div></form>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>

<script src="<?= e(url('public/js/editor-media-picker.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.89.0'))) ?>"></script>

<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(
        url('admin/midias/upload-editor.php'),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>,
    csrfToken: <?= json_encode(
        Csrf::token(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>
});

const seoOgImageOpen =
    document.getElementById('seoOgImageOpen');

PortalMediaPicker.bindFeatured({
    openButton: seoOgImageOpen,
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('seoOgImageId'),
    preview: document.getElementById('seoOgImagePreview')
});

/*
 * O picker compartilhado usa o modo "featured" para seleção única.
 * Aqui apenas personalizamos os textos para o contexto SEO/Social.
 */
if (seoOgImageOpen) {
    seoOgImageOpen.addEventListener('click', function () {
        const title =
            document.getElementById('portalMediaPickerTitle');

        const subtitle =
            document.getElementById('portalMediaPickerSubtitle');

        const useButton =
            document.getElementById('portalMediaInsertButton');

        if (title) {
            title.textContent =
                'Escolher imagem social padrão';
        }

        if (subtitle) {
            subtitle.textContent =
                'Selecione uma imagem da Biblioteca de Mídia para Facebook, WhatsApp, X e outros compartilhamentos.';
        }

        if (useButton) {
            useButton.textContent =
                'Usar como imagem social';
        }
    });
}
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
