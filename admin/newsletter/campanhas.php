<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('newsletter.gerenciar');
$pdo=Database::connection();
if($_SERVER['REQUEST_METHOD']==='POST' && Csrf::validate($_POST['_token']??null)){
 $id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
 if($id>0 && $action==='delete'){$s=$pdo->prepare("DELETE FROM newsletter_campanhas WHERE id=:id AND status='rascunho'");$s->execute(['id'=>$id]);Session::flash($s->rowCount()?'success':'error',$s->rowCount()?'Campanha excluída.':'Apenas campanhas em rascunho podem ser excluídas.');header('Location: '.url('admin/newsletter/campanhas.php'));exit;}
}
$active=(int)$pdo->query("SELECT COUNT(*) FROM newsletter_assinantes WHERE status='ativo'")->fetchColumn();
$rows=$pdo->query("SELECT c.*,u.nome autor_nome,(SELECT COUNT(*) FROM newsletter_envios e WHERE e.campanha_id=c.id AND e.status='enviado') enviados,(SELECT COUNT(*) FROM newsletter_envios e WHERE e.campanha_id=c.id AND e.status='falhou') falhas FROM newsletter_campanhas c LEFT JOIN usuarios u ON u.id=c.autor_id ORDER BY c.created_at DESC")->fetchAll();
$pageTitle='Newsletter - Campanhas';require __DIR__.'/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h3 mb-1">Campanhas</h1><p class="text-secondary mb-0">Crie e envie boletins para <?= $active ?> assinante(s) ativo(s).</p></div><a class="btn btn-primary" href="<?=e(url('admin/newsletter/campanha-form.php'))?>"><i class="bi bi-plus-lg me-1"></i>Nova campanha</a></div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Campanha</th><th>Status</th><th>Enviados</th><th>Falhas</th><th>Criada</th><th></th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="6" class="text-secondary">Nenhuma campanha criada.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr><td><strong><?=e($r['assunto'])?></strong><?php if($r['preheader']):?><div class="small text-secondary"><?=e($r['preheader'])?></div><?php endif;?></td><td><span class="badge <?= $r['status']==='enviado'?'text-bg-success':($r['status']==='enviando'?'text-bg-warning':'text-bg-secondary') ?>"><?=e(ucfirst((string)$r['status']))?></span></td><td><?= (int)$r['enviados'] ?></td><td><?= (int)$r['falhas'] ?></td><td><?=e(formatDateBr($r['created_at']))?></td><td class="text-end text-nowrap"><?php if($r['status']==='rascunho'):?><a class="btn btn-sm btn-outline-secondary" href="<?=e(url('admin/newsletter/campanha-form.php?id='.(int)$r['id']))?>">Editar</a><?php endif;?><?php if($r['status']!=='enviado'):?><a class="btn btn-sm btn-primary" href="<?=e(url('admin/newsletter/enviar.php?id='.(int)$r['id']))?>">Enviar</a><?php endif;?><?php if($r['status']==='rascunho'):?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-sm btn-outline-danger" name="action" value="delete" onclick="return confirm('Excluir este rascunho?')">Excluir</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
<?php require __DIR__.'/../_footer.php'; ?>
