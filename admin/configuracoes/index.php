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
        $types=['site_email'=>'email','site_instagram'=>'url','site_youtube'=>'url','site_facebook'=>'url','site_logo_id'=>'numero','site_favicon_id'=>'numero'];
        $pdo->beginTransaction(); foreach ($defaults as $key=>$_) saveSiteConfig($pdo,$key,$settings[$key]??'',$types[$key]??'texto'); $pdo->commit();
        logAction($pdo,'configuracoes.geral','configuracoes',null,'Configurações gerais atualizadas');
        Session::flash('success','Configurações gerais atualizadas.'); header('Location: '.url('admin/configuracoes/index.php')); exit;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
}
$images=$pdo->query("SELECT id,caminho,titulo,nome_original FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC")->fetchAll();
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
<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Logo e favicon</div><div class="card-body p-4"><div class="row g-4">
<?php foreach([['site_logo_id','site_logo_upload','Logo'],['site_favicon_id','site_favicon_upload','Favicon']] as [$key,$upload,$label]):?><div class="col-lg-6"><label class="form-label"><?=e($label)?></label><select class="form-select mb-2" name="<?=e($key)?>"><option value="">Nenhuma imagem</option><?php foreach($images as $img):?><option value="<?=(int)$img['id']?>" <?=(string)$settings[$key]===(string)$img['id']?'selected':''?>><?=e($img['titulo']?:$img['nome_original'])?></option><?php endforeach;?></select><input class="form-control" type="file" name="<?=e($upload)?>" accept="image/jpeg,image/png,image/webp,image/gif"></div><?php endforeach;?>
</div></div></div>
<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Data e hora</div><div class="card-body p-4"><div class="row g-3">
<div class="col-lg-6"><label class="form-label">Fuso horário</label><select class="form-select" name="site_timezone"><?php foreach(['America/Sao_Paulo','America/Fortaleza','America/Manaus','America/Rio_Branco','UTC'] as $tz):?><option value="<?=e($tz)?>" <?=$settings['site_timezone']===$tz?'selected':''?>><?=e($tz)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Formato de data</label><select class="form-select" name="date_format"><?php foreach(['d/m/Y'=>'18/08/2026','d-m-Y'=>'18-08-2026','Y-m-d'=>'2026-08-18','d.m.Y'=>'18.08.2026'] as $v=>$label):?><option value="<?=e($v)?>" <?=$settings['date_format']===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Formato de hora</label><select class="form-select" name="time_format"><option value="H:i" <?=$settings['time_format']==='H:i'?'selected':''?>>20:42</option><option value="H\hi" <?=$settings['time_format']==='H\hi'?'selected':''?>>20h42</option><option value="g:i A" <?=$settings['time_format']==='g:i A'?'selected':''?>>8:42 PM</option></select></div>
</div></div></div>
<button class="btn btn-primary px-4">Salvar configurações</button></form>
<?php require __DIR__.'/../_footer.php';?>
