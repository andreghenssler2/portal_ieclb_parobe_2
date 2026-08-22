<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('liderancas.gerenciar');

$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$item = $id > 0 ? LeadershipService::find($pdo, $id) : null;
if ($id > 0 && !$item) {
    http_response_code(404);
    exit('Perfil não encontrado.');
}

$defaults = [
    'nome'=>'','slug'=>'','tipo'=>'lideranca','funcao'=>'','resumo'=>'','biografia'=>'',
    'email'=>'','telefone'=>'','whatsapp'=>'','instagram'=>'','facebook'=>'',
    'exibir_email'=>0,'exibir_telefone'=>0,'exibir_whatsapp'=>0,
    'foto_id'=>'','comunidade_id'=>'','grupo_id'=>'','ativo'=>1,'ordem'=>0,
    'seo_titulo'=>'','seo_descricao'=>'','seo_noindex'=>0,
];
$item = array_merge($defaults, $item ?: []);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach(array_keys($defaults) as $key) {
        if (array_key_exists($key, $_POST)) $item[$key] = $_POST[$key];
    }
    foreach(['exibir_email','exibir_telefone','exibir_whatsapp','ativo','seo_noindex'] as $key) {
        $item[$key] = isset($_POST[$key]) ? 1 : 0;
    }

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $savedId = LeadershipService::save($pdo, array_merge($_POST, [
                'id'=>$id,
                'exibir_email'=>isset($_POST['exibir_email'])?1:0,
                'exibir_telefone'=>isset($_POST['exibir_telefone'])?1:0,
                'exibir_whatsapp'=>isset($_POST['exibir_whatsapp'])?1:0,
                'ativo'=>isset($_POST['ativo'])?1:0,
                'seo_noindex'=>isset($_POST['seo_noindex'])?1:0,
            ]), (int)Auth::id());
            logAction($pdo, $id>0?'lideranca.editar':'lideranca.criar', 'liderancas', $savedId, trim((string)($_POST['nome']??'')));
            Session::flash('success', $id>0 ? 'Perfil atualizado.' : 'Perfil criado.');
            header('Location: '.url('admin/liderancas/form.php?id='.$savedId));
            exit;
        } catch(Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$communities = LeadershipService::communities($pdo);
$groups = LeadershipService::groups($pdo);
$midias = LeadershipService::imageChoices($pdo);
$currentPhoto = !empty($item['foto_id']) ? MediaService::find($pdo, (int)$item['foto_id']) : null;

$pageTitle = $id>0 ? 'Editar liderança' : 'Nova liderança';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1"><?=e($pageTitle)?></h1><p class="text-secondary mb-0">Perfil público de pastores, presbitério, lideranças e equipe.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?=e(url('admin/liderancas/index.php'))?>">Voltar</a><?php if($id>0&&!empty($item['slug'])&&!empty($item['ativo'])):?><a class="btn btn-outline-primary" target="_blank" href="<?=e(contentUrl('lideranca',(string)$item['slug']))?>">Ver perfil</a><?php endif;?></div>
</div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>

<form method="post" id="leadershipForm">
<?=Csrf::field()?><input type="hidden" name="id" value="<?=$id?>">
<div class="row g-4">
<div class="col-xl-8">
    <div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Nome</label><input class="form-control form-control-lg" name="nome" maxlength="180" required value="<?=e((string)$item['nome'])?>"></div>
            <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select form-select-lg" name="tipo"><?php foreach(LeadershipService::typeLabels() as $value=>$label):?><option value="<?=e($value)?>" <?=$item['tipo']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
            <div class="col-md-8"><label class="form-label">Função / Cargo</label><input class="form-control" name="funcao" maxlength="180" value="<?=e((string)$item['funcao'])?>" placeholder="Ex.: Pastor, Presidente, Secretária, Coordenador"></div>
            <div class="col-md-4"><label class="form-label">Slug</label><input class="form-control" name="slug" maxlength="220" value="<?=e((string)$item['slug'])?>" placeholder="automática"></div>
            <div class="col-12"><label class="form-label">Resumo</label><textarea class="form-control" name="resumo" rows="3" maxlength="500" placeholder="Texto curto para o card e mecanismos de busca."><?=e((string)$item['resumo'])?></textarea></div>
            <div class="col-12"><label class="form-label">Biografia / Apresentação</label><textarea class="form-control" name="biografia" rows="12" placeholder="Conte a trajetória, ministério, responsabilidades e outras informações públicas."><?=e((string)$item['biografia'])?></textarea></div>
        </div>
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Contatos</div><div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" maxlength="190" value="<?=e((string)$item['email'])?>"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="exibir_email" id="showEmail" <?=$item['exibir_email']?'checked':''?>><label class="form-check-label small" for="showEmail">Exibir publicamente</label></div></div>
            <div class="col-md-6"><label class="form-label">Telefone</label><input class="form-control" name="telefone" maxlength="40" value="<?=e((string)$item['telefone'])?>"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="exibir_telefone" id="showPhone" <?=$item['exibir_telefone']?'checked':''?>><label class="form-check-label small" for="showPhone">Exibir publicamente</label></div></div>
            <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="whatsapp" maxlength="40" value="<?=e((string)$item['whatsapp'])?>"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="exibir_whatsapp" id="showWhatsapp" <?=$item['exibir_whatsapp']?'checked':''?>><label class="form-check-label small" for="showWhatsapp">Exibir botão de WhatsApp</label></div></div>
            <div class="col-md-6"><label class="form-label">Instagram</label><input class="form-control" name="instagram" maxlength="500" value="<?=e((string)$item['instagram'])?>" placeholder="https://instagram.com/..."></div>
            <div class="col-md-6"><label class="form-label">Facebook</label><input class="form-control" name="facebook" maxlength="500" value="<?=e((string)$item['facebook'])?>" placeholder="https://facebook.com/..."></div>
        </div>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">SEO</div><div class="card-body p-4">
        <div class="mb-3"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="220" value="<?=e((string)$item['seo_titulo'])?>" placeholder="Se vazio, usa o nome e função"></div>
        <div class="mb-3"><label class="form-label">Descrição SEO</label><textarea class="form-control" name="seo_descricao" maxlength="320" rows="3"><?=e((string)$item['seo_descricao'])?></textarea></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="noindex" <?=$item['seo_noindex']?'checked':''?>><label class="form-check-label" for="noindex">Não indexar este perfil nos buscadores</label></div>
    </div></div>
</div>

<div class="col-xl-4">
    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Publicação</div><div class="card-body p-4">
        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="ativo" id="active" <?=$item['ativo']?'checked':''?>><label class="form-check-label" for="active">Perfil público ativo</label></div>
        <div class="mb-3"><label class="form-label">Ordem</label><input class="form-control" type="number" name="ordem" value="<?=e((string)$item['ordem'])?>"><div class="form-text">Números menores aparecem primeiro.</div></div>
        <button class="btn btn-primary w-100"><?= $id>0?'Salvar alterações':'Criar perfil' ?></button>
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Foto</div><div class="card-body p-4">
        <input type="hidden" name="foto_id" id="leadershipPhotoId" value="<?=e((string)$item['foto_id'])?>">
        <div id="leadershipPhotoPreview" class="featured-picker-preview">
            <?php if($currentPhoto && MediaService::isImage($currentPhoto)):?>
                <div class="d-flex align-items-center gap-3">
                    <img src="<?=e(mediaUrl((string)$currentPhoto['caminho']))?>" alt="<?=e($currentPhoto['alt_text'] ?: $currentPhoto['titulo'] ?: $currentPhoto['nome_original'])?>" class="img-thumbnail featured-preview">
                    <div><div class="fw-semibold"><?=e($currentPhoto['titulo'] ?: $currentPhoto['nome_original'])?></div><button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-media-featured-remove>Remover imagem</button></div>
                </div>
            <?php else:?><div class="text-secondary small">Nenhuma foto selecionada.</div><?php endif;?>
        </div>
        <button type="button" class="btn btn-outline-primary w-100 mt-3" data-media-featured-open><i class="bi bi-images me-1"></i>Escolher na Biblioteca</button>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Vínculos</div><div class="card-body p-4">
        <div class="mb-3"><label class="form-label">Comunidade</label><select class="form-select" name="comunidade_id"><option value="">Paroquial / Todas</option><?php foreach($communities as $community):?><option value="<?=(int)$community['id']?>" <?=(string)$item['comunidade_id']===(string)$community['id']?'selected':''?>><?=e($community['nome'])?></option><?php endforeach;?></select></div>
        <div><label class="form-label">Grupo / Ministério</label><select class="form-select" name="grupo_id"><option value="">Nenhum</option><?php foreach($groups as $group):?><option value="<?=(int)$group['id']?>" <?=(string)$item['grupo_id']===(string)$group['id']?'selected':''?>><?=e($group['nome'])?></option><?php endforeach;?></select><?php if(!$groups):?><div class="form-text">Nenhum grupo ativo encontrado.</div><?php endif;?></div>
    </div></div>
</div>
</div>
</form>

<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="<?=e(url('public/js/editor-media-picker.js'))?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?=json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>,
    csrfToken: <?=json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>
});
PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('leadershipPhotoId'),
    preview: document.getElementById('leadershipPhotoPreview')
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
