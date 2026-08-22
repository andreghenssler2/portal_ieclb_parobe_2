<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('newsletter.gerenciar');
$pdo=Database::connection();$id=(int)($_GET['id']??0);$error='';
$item=['assunto'=>'','preheader'=>'','conteudo'=>''];
if($id){$s=$pdo->prepare('SELECT * FROM newsletter_campanhas WHERE id=:id LIMIT 1');$s->execute(['id'=>$id]);$item=$s->fetch()?:null;if(!$item){http_response_code(404);exit('Campanha não encontrada.');}if($item['status']!=='rascunho'){Session::flash('error','Campanhas em envio ou já enviadas não podem ser editadas.');header('Location: '.url('admin/newsletter/campanhas.php'));exit;}}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $item['assunto']=trim((string)($_POST['assunto']??''));$item['preheader']=trim((string)($_POST['preheader']??''));$item['conteudo']=trim((string)($_POST['conteudo']??''));
 if(!Csrf::validate($_POST['_token']??null))$error='Token de segurança inválido.';elseif($item['assunto']===''||trim(strip_tags($item['conteudo']))==='')$error='Assunto e conteúdo são obrigatórios.';else{try{if($id){$pdo->prepare("UPDATE newsletter_campanhas SET assunto=:a,preheader=:p,conteudo=:c,updated_at=NOW() WHERE id=:id AND status='rascunho'")->execute(['a'=>$item['assunto'],'p'=>$item['preheader']?:null,'c'=>$item['conteudo'],'id'=>$id]);logAction($pdo,'newsletter.campanha.editar','newsletter_campanhas',$id);}else{$pdo->prepare("INSERT INTO newsletter_campanhas (autor_id,assunto,preheader,conteudo,status) VALUES (:u,:a,:p,:c,'rascunho')")->execute(['u'=>Auth::id(),'a'=>$item['assunto'],'p'=>$item['preheader']?:null,'c'=>$item['conteudo']]);$id=(int)$pdo->lastInsertId();logAction($pdo,'newsletter.campanha.criar','newsletter_campanhas',$id);}Session::flash('success','Campanha salva.');header('Location: '.url('admin/newsletter/campanhas.php'));exit;}catch(Throwable $e){$error=$e->getMessage();}}
}
$pageTitle=$id?'Editar campanha':'Nova campanha';require __DIR__.'/../_header.php';
?>
<div class="mb-4"><h1 class="h3 mb-1"><?=e($pageTitle)?></h1><p class="text-secondary mb-0">Use <code>{{nome}}</code>, <code>{{email}}</code> e <code>{{descadastrar_url}}</code> para personalização.</p></div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<form method="post" id="campaignForm" class="card border-0 shadow-sm"><div class="card-body p-4"><?=Csrf::field()?><div class="row g-3"><div class="col-12"><label class="form-label">Assunto</label><input class="form-control" name="assunto" maxlength="220" required value="<?=e($item['assunto'])?>"></div><div class="col-12"><label class="form-label">Preheader</label><input class="form-control" name="preheader" maxlength="255" value="<?=e($item['preheader'])?>"><div class="form-text">Texto curto que alguns clientes de e-mail mostram ao lado do assunto.</div></div><div class="col-12"><label class="form-label">Conteúdo</label><textarea class="form-control" id="conteudo" name="conteudo" rows="18"><?=e($item['conteudo'])?></textarea></div></div><div class="mt-4"><button class="btn btn-primary">Salvar rascunho</button> <a class="btn btn-outline-secondary" href="<?=e(url('admin/newsletter/campanhas.php'))?>">Cancelar</a></div></div></form>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script>
PortalMediaPicker.init({modalId:'portalMediaPickerModal',uploadUrl:<?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,csrfToken:<?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>});
tinymce.init({selector:'#conteudo',height:520,menubar:false,plugins:'link lists table code image media',toolbar:'undo redo | blocks | bold italic | bullist numlist | link portalmedia table | code',setup:function(ed){ed.ui.registry.addButton('portalmedia',{icon:'image',tooltip:'Inserir imagens da Biblioteca de Mídia',onAction:function(){PortalMediaPicker.openForEditor(ed);}});ed.on('change keyup',function(){ed.save();});}});
document.getElementById('campaignForm').addEventListener('submit',function(){if(window.tinymce)tinymce.triggerSave();});
</script>
<?php require __DIR__.'/../_footer.php'; ?>
