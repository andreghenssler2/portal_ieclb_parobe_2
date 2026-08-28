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
$images = $pdo->query("SELECT id,caminho,titulo,alt_text,nome_original FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 200")->fetchAll();

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
    <div class="col-md-6"><label class="form-label">Imagem padrão já existente</label><select class="form-select" name="seo_og_image_id" id="ogImageSelect"><option value="">Nenhuma</option><?php foreach ($images as $image): ?><option value="<?= (int)$image['id'] ?>" data-url="<?= e(mediaUrl($image['caminho'])) ?>" <?= (string)$settings['seo_og_image_id']===(string)$image['id']?'selected':'' ?>><?= e($image['titulo'] ?: $image['nome_original']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Ou enviar nova imagem</label><input class="form-control" type="file" name="seo_og_image_upload" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-text">Recomendado: 1200 × 630 px.</div></div>
    <div class="col-md-6"><div id="ogImagePreview" class="seo-image-preview"></div></div>
</div>
<div class="mt-4"><button class="btn btn-primary px-4">Salvar alterações</button></div>
</div></form>
<script>
const ogSel=document.getElementById('ogImageSelect'), ogPrev=document.getElementById('ogImagePreview');
function refreshOg(){const o=ogSel.options[ogSel.selectedIndex]; const src=o?.dataset?.url||''; ogPrev.innerHTML=src?`<img src="${src}" class="img-fluid rounded border" alt="Prévia">`:'';} ogSel.addEventListener('change',refreshOg); refreshOg();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
