<?php
require_once __DIR__.'/../../bootstrap.php'; Auth::requirePermission('configuracoes.gerenciar'); $pdo=Database::connection(); $error='';
$defaults=['reading_home_posts'=>'9','reading_home_events'=>'6','reading_home_galleries'=>'3','reading_home_communities'=>'10'];$s=array_merge($defaults,siteConfigAll($pdo));
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!Csrf::validate($_POST['_token']??null))$error='Token de segurança inválido.'; else try{foreach($defaults as $k=>$_){$v=max(1,min(30,(int)($_POST[$k]??$s[$k])));$s[$k]=(string)$v;saveSiteConfig($pdo,$k,$s[$k],'numero');}logAction($pdo,'configuracoes.leitura','configuracoes');Session::flash('success','Configurações de leitura atualizadas.');header('Location: '.url('admin/configuracoes/leitura.php'));exit;}catch(Throwable $e){$error=$e->getMessage();}
}
$pageTitle='Configurações de leitura';require __DIR__.'/../_header.php';?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">Leitura</h1><p class="text-secondary mb-0">Quantidade de conteúdos carregados na página inicial.</p></div><a class="btn btn-outline-secondary" href="<?=e(url('admin/aparencia/widgets.php'))?>">Ordem dos widgets</a></div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post" class="card border-0 shadow-sm"><div class="card-body p-4"><?=Csrf::field()?><div class="row g-3">
<?php foreach(['reading_home_posts'=>'Últimas notícias','reading_home_events'=>'Próximos eventos/cultos','reading_home_galleries'=>'Galerias recentes','reading_home_communities'=>'Comunidades'] as $k=>$label):?><div class="col-md-6"><label class="form-label"><?=e($label)?></label><input class="form-control" type="number" min="1" max="30" name="<?=e($k)?>" value="<?=e($s[$k])?>"></div><?php endforeach;?>
<div class="col-12"><div class="alert alert-light border mb-0">Para ocultar ou mudar a ordem de uma seção da Home, use <strong>Aparência → Widgets</strong>.</div></div><div class="col-12"><button class="btn btn-primary">Salvar leitura</button></div>
</div></div></form><?php require __DIR__.'/../_footer.php';?>
