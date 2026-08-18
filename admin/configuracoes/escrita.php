<?php
require_once __DIR__.'/../../bootstrap.php'; Auth::requirePermission('configuracoes.gerenciar'); $pdo=Database::connection(); $error='';
$defaults=['writing_default_category'=>'','writing_default_status'=>'rascunho','writing_excerpt_length'=>'180']; $s=array_merge($defaults,siteConfigAll($pdo));
if($_SERVER['REQUEST_METHOD']==='POST'){
 foreach(array_keys($defaults) as $k) if(array_key_exists($k,$_POST))$s[$k]=trim((string)$_POST[$k]);
 if(!Csrf::validate($_POST['_token']??null))$error='Token de segurança inválido.'; else try{
  $cat=$s['writing_default_category']!==''?(int)$s['writing_default_category']:0; if($cat>0){$st=$pdo->prepare('SELECT 1 FROM categorias WHERE id=:id');$st->execute(['id'=>$cat]);if(!$st->fetchColumn())throw new RuntimeException('Categoria padrão inválida.');}
  if(!in_array($s['writing_default_status'],['rascunho','publicado'],true))$s['writing_default_status']='rascunho'; $s['writing_excerpt_length']=(string)max(80,min(500,(int)$s['writing_excerpt_length']));
  foreach($defaults as $k=>$_)saveSiteConfig($pdo,$k,$s[$k],$k==='writing_excerpt_length'?'numero':'texto'); logAction($pdo,'configuracoes.escrita','configuracoes'); Session::flash('success','Configurações de escrita atualizadas.'); header('Location: '.url('admin/configuracoes/escrita.php'));exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$cats=$pdo->query('SELECT id,nome FROM categorias ORDER BY nome')->fetchAll(); $pageTitle='Configurações de escrita'; require __DIR__.'/../_header.php';
?>
<h1 class="h3 mb-1">Escrita</h1><p class="text-secondary mb-4">Padrões usados ao criar uma nova notícia.</p><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body p-4"><?=Csrf::field()?><div class="row g-3">
<div class="col-lg-6"><label class="form-label">Categoria padrão</label><select class="form-select" name="writing_default_category"><option value="">Sem categoria</option><?php foreach($cats as $c):?><option value="<?=(int)$c['id']?>" <?=(string)$s['writing_default_category']===(string)$c['id']?'selected':''?>><?=e($c['nome'])?></option><?php endforeach;?></select></div>
<div class="col-lg-3"><label class="form-label">Status padrão</label><select class="form-select" name="writing_default_status"><option value="rascunho" <?=$s['writing_default_status']==='rascunho'?'selected':''?>>Rascunho</option><option value="publicado" <?=$s['writing_default_status']==='publicado'?'selected':''?>>Publicado</option></select></div>
<div class="col-lg-3"><label class="form-label">Tamanho do resumo automático</label><div class="input-group"><input class="form-control" type="number" min="80" max="500" name="writing_excerpt_length" value="<?=e($s['writing_excerpt_length'])?>"><span class="input-group-text">caracteres</span></div></div>
<div class="col-12"><div class="form-text">Esses valores são aplicados somente ao iniciar uma nova notícia; não alteram posts já existentes.</div></div><div class="col-12"><button class="btn btn-primary">Salvar escrita</button></div>
</div></div></form><?php require __DIR__.'/../_footer.php';?>
