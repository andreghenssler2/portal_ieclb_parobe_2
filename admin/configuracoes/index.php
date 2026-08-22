<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('configuracoes.gerenciar');
$pdo = Database::connection();
$error = '';
$defaults = [
    'site_nome' => 'Paróquia Evangélica de Confissão Luterana de Parobé',
    'site_descricao' => 'Portal da IECLB Parobé',
    'site_email' => '', 'site_telefone' => '', 'site_endereco' => '',
    'site_instagram' => '', 'site_youtube' => '', 'site_facebook' => '',
    'site_logo_id' => '', 'site_favicon_id' => '',
    'site_timezone' => defined('TIMEZONE') ? TIMEZONE : 'America/Sao_Paulo',
    'date_format' => 'd/m/Y', 'time_format' => 'H:i',
];
$settings = array_merge($defaults, siteConfigAll($pdo));

// Compatibilidade com links antigos da v0.6-v0.11.
$legacySection = (string)($_GET['secao'] ?? '');
if ($legacySection === 'leitura') { header('Location: '.url('admin/configuracoes/leitura.php')); exit; }
if ($legacySection === 'aparencia') { header('Location: '.url('admin/aparencia/personalizar.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($defaults) as $key) if (array_key_exists($key, $_POST)) $settings[$key] = trim((string)$_POST[$key]);
    if (!Csrf::validate($_POST['_token'] ?? null)) $error = 'Token de segurança inválido.';
    else try {
        if ($settings['site_nome'] === '') throw new RuntimeException('Informe o nome do portal.');
        if ($settings['site_email'] !== '' && !filter_var($settings['site_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
        foreach (['site_instagram','site_youtube','site_facebook'] as $field) if ($settings[$field] !== '' && !filter_var($settings[$field], FILTER_VALIDATE_URL)) throw new RuntimeException('Informe URLs completas e válidas para as redes sociais.');
        if (!in_array($settings['site_timezone'], DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Fuso horário inválido.');
        if (!in_array($settings['date_format'], ['d/m/Y','d-m-Y','Y-m-d','d.m.Y'], true)) $settings['date_format']='d/m/Y';
        if (!in_array($settings['time_format'], ['H:i','H\\hi','g:i A'], true)) $settings['time_format']='H:i';

        foreach (['site_logo_upload'=>'site_logo_id','site_favicon_upload'=>'site_favicon_id'] as $fileField=>$settingKey) {
            if (isset($_FILES[$fileField]) && (int)($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $media = MediaService::upload($pdo, $_FILES[$fileField], (int)Auth::id());
                if (!MediaService::isImage($media)) { MediaService::delete($pdo,(int)$media['id']); throw new RuntimeException('Logo e favicon precisam ser imagens.'); }
                $settings[$settingKey]=(string)$media['id'];
            }
        }
        foreach (['site_logo_id'=>'Logo','site_favicon_id'=>'Favicon'] as $settingKey=>$mediaLabel) {
            $selectedId = (int)($settings[$settingKey] ?? 0);
            if ($selectedId <= 0) {
                $settings[$settingKey] = '';
                continue;
            }

            $selectedMedia = MediaService::find($pdo, $selectedId);
            if (!$selectedMedia || !MediaService::isImage($selectedMedia)) {
                throw new RuntimeException(
                    $mediaLabel . ' selecionado na Biblioteca de Mídia é inválido.'
                );
            }

            $settings[$settingKey] = (string)$selectedId;
        }
        $types=['site_email'=>'email','site_instagram'=>'url','site_youtube'=>'url','site_facebook'=>'url','site_logo_id'=>'numero','site_favicon_id'=>'numero'];
        $pdo->beginTransaction(); foreach ($defaults as $key=>$_) saveSiteConfig($pdo,$key,$settings[$key]??'',$types[$key]??'texto'); $pdo->commit();
        logAction($pdo,'configuracoes.geral','configuracoes',null,'Configurações gerais atualizadas');
        Session::flash('success','Configurações gerais atualizadas.'); header('Location: '.url('admin/configuracoes/index.php')); exit;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
}
$midias=$pdo->query("SELECT id,caminho,titulo,alt_text,nome_original,largura,altura FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC")->fetchAll();
$siteLogoAtual=!empty($settings['site_logo_id']) ? MediaService::find($pdo,(int)$settings['site_logo_id']) : null;
$siteFaviconAtual=!empty($settings['site_favicon_id']) ? MediaService::find($pdo,(int)$settings['site_favicon_id']) : null;
$pageTitle='Configurações gerais'; require __DIR__.'/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">Configurações gerais</h1><p class="text-secondary mb-0">Identidade, contato, fuso horário e formatos do portal.</p></div><a class="btn btn-outline-secondary" href="<?=e(url())?>" target="_blank">Ver portal</a></div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data"><?=Csrf::field()?>
<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Identidade e contato</div><div class="card-body p-4"><div class="row g-3">
<div class="col-lg-8"><label class="form-label">Nome do portal / paróquia</label><input class="form-control" name="site_nome" value="<?=e($settings['site_nome'])?>" required></div>
<div class="col-lg-4"><label class="form-label">Telefone</label><input class="form-control" name="site_telefone" value="<?=e($settings['site_telefone'])?>"></div>
<div class="col-lg-8"><label class="form-label">Descrição curta</label><input class="form-control" name="site_descricao" value="<?=e($settings['site_descricao'])?>"></div>
<div class="col-lg-4"><label class="form-label">E-mail</label><input class="form-control" type="email" name="site_email" value="<?=e($settings['site_email'])?>"></div>
<div class="col-12"><label class="form-label">Endereço</label><input class="form-control" name="site_endereco" value="<?=e($settings['site_endereco'])?>"></div>
<div class="col-md-4"><label class="form-label">Instagram</label><input class="form-control" type="url" name="site_instagram" value="<?=e($settings['site_instagram'])?>" placeholder="https://..."></div>
<div class="col-md-4"><label class="form-label">YouTube</label><input class="form-control" type="url" name="site_youtube" value="<?=e($settings['site_youtube'])?>" placeholder="https://..."></div>
<div class="col-md-4"><label class="form-label">Facebook</label><input class="form-control" type="url" name="site_facebook" value="<?=e($settings['site_facebook'])?>" placeholder="https://..."></div>
</div></div></div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Logo e favicon</div>
    <div class="card-body p-4">
        <div class="row g-4">
            <div class="col-lg-7">
                <label class="form-label fw-semibold">Logo</label>
                <input type="hidden" name="site_logo_id" id="siteLogoId" value="<?=e((string)$settings['site_logo_id'])?>">

                <div id="siteLogoPreview" class="border rounded bg-body-tertiary p-3 mb-3" style="min-height:120px">
                    <?php if($siteLogoAtual && MediaService::isImage($siteLogoAtual)):?>
                        <div class="d-flex align-items-center gap-3">
                            <img
                                src="<?=e(mediaUrl((string)$siteLogoAtual['caminho']))?>"
                                alt="<?=e((string)($siteLogoAtual['alt_text'] ?: $siteLogoAtual['titulo'] ?: $siteLogoAtual['nome_original']))?>"
                                class="img-thumbnail"
                                style="max-width:220px;max-height:100px;object-fit:contain"
                            >
                            <div>
                                <div class="fw-semibold"><?=e((string)($siteLogoAtual['titulo'] ?: $siteLogoAtual['nome_original']))?></div>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-site-logo-remove>Remover imagem</button>
                            </div>
                        </div>
                    <?php else:?>
                        <div class="text-secondary small d-flex align-items-center gap-2">
                            <i class="bi bi-image fs-3"></i>
                            Nenhum logo selecionado.
                        </div>
                    <?php endif;?>
                </div>

                <button type="button" class="btn btn-outline-primary" data-site-logo-open>
                    <i class="bi bi-images me-1"></i>Escolher na Biblioteca
                </button>
                <div class="form-text">Use PNG, JPG, GIF ou WebP. Você também pode enviar uma nova imagem pelo seletor.</div>
            </div>

            <div class="col-lg-5">
                <label class="form-label fw-semibold">Favicon</label>
                <input type="hidden" name="site_favicon_id" id="siteFaviconId" value="<?=e((string)$settings['site_favicon_id'])?>">

                <div id="siteFaviconPreview" class="border rounded bg-body-tertiary p-3 mb-3" style="min-height:120px">
                    <?php if($siteFaviconAtual && MediaService::isImage($siteFaviconAtual)):?>
                        <div class="d-flex align-items-center gap-3">
                            <img
                                src="<?=e(mediaUrl((string)$siteFaviconAtual['caminho']))?>"
                                alt="<?=e((string)($siteFaviconAtual['alt_text'] ?: $siteFaviconAtual['titulo'] ?: $siteFaviconAtual['nome_original']))?>"
                                class="img-thumbnail"
                                style="width:72px;height:72px;object-fit:contain"
                            >
                            <div>
                                <div class="fw-semibold"><?=e((string)($siteFaviconAtual['titulo'] ?: $siteFaviconAtual['nome_original']))?></div>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-site-favicon-remove>Remover imagem</button>
                            </div>
                        </div>
                    <?php else:?>
                        <div class="text-secondary small d-flex align-items-center gap-2">
                            <i class="bi bi-app-indicator fs-3"></i>
                            Nenhum favicon selecionado.
                        </div>
                    <?php endif;?>
                </div>

                <button type="button" class="btn btn-outline-primary" data-site-favicon-open>
                    <i class="bi bi-images me-1"></i>Escolher na Biblioteca
                </button>
                <div class="form-text">Para favicon, prefira uma imagem quadrada.</div>
            </div>
        </div>
    </div>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Data e hora</div><div class="card-body p-4"><div class="row g-3">
<div class="col-lg-6"><label class="form-label">Fuso horário</label><select class="form-select" name="site_timezone"><?php foreach(['America/Sao_Paulo','America/Fortaleza','America/Manaus','America/Rio_Branco','UTC'] as $tz):?><option value="<?=e($tz)?>" <?=$settings['site_timezone']===$tz?'selected':''?>><?=e($tz)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Formato de data</label><select class="form-select" name="date_format"><?php foreach(['d/m/Y'=>'18/08/2026','d-m-Y'=>'18-08-2026','Y-m-d'=>'2026-08-18','d.m.Y'=>'18.08.2026'] as $v=>$label):?><option value="<?=e($v)?>" <?=$settings['date_format']===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Formato de hora</label><select class="form-select" name="time_format"><option value="H:i" <?=$settings['time_format']==='H:i'?'selected':''?>>20:42</option><option value="H\hi" <?=$settings['time_format']==='H\hi'?'selected':''?>>20h42</option><option value="g:i A" <?=$settings['time_format']==='g:i A'?'selected':''?>>8:42 PM</option></select></div>
</div></div></div>
<button class="btn btn-primary px-4">Salvar configurações</button></form>

<?php require __DIR__.'/../_editor_media_picker.php'; ?>
<script src="<?=e(url('public/js/editor-media-picker.js'))?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.PortalMediaPicker) {
        return;
    }

    PortalMediaPicker.init({
        modalId: 'portalMediaPickerModal',
        uploadUrl: <?=json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>,
        csrfToken: <?=json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>
    });

    const logoOpen = document.querySelector('[data-site-logo-open]');
    const faviconOpen = document.querySelector('[data-site-favicon-open]');

    PortalMediaPicker.bindFeatured({
        openButton: logoOpen,
        removeButtonSelector: '[data-site-logo-remove]',
        input: document.getElementById('siteLogoId'),
        preview: document.getElementById('siteLogoPreview')
    });

    PortalMediaPicker.bindFeatured({
        openButton: faviconOpen,
        removeButtonSelector: '[data-site-favicon-remove]',
        input: document.getElementById('siteFaviconId'),
        preview: document.getElementById('siteFaviconPreview')
    });

    function customizePicker(button, title, subtitle, confirmLabel) {
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            const modal = document.getElementById('portalMediaPickerModal');
            if (!modal) {
                return;
            }

            const modalTitle = modal.querySelector('#portalMediaPickerTitle');
            const modalSubtitle = modal.querySelector('#portalMediaPickerSubtitle');
            const insertButton = modal.querySelector('#portalMediaInsertButton');

            if (modalTitle) modalTitle.textContent = title;
            if (modalSubtitle) modalSubtitle.textContent = subtitle;
            if (insertButton) insertButton.textContent = confirmLabel;
        });
    }

    customizePicker(
        logoOpen,
        'Escolher logo do Portal',
        'Selecione uma imagem existente ou envie uma nova para usar como logo.',
        'Usar como logo'
    );

    customizePicker(
        faviconOpen,
        'Escolher favicon do Portal',
        'Selecione uma imagem quadrada existente ou envie uma nova.',
        'Usar como favicon'
    );
});
</script>
<?php require __DIR__.'/../_footer.php';?>
