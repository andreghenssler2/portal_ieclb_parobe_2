<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');
$pdo=Database::connection();
$id=(int)($_GET['id'] ?? 0);
$stmt=$pdo->prepare('SELECT r.*,f.titulo AS formulario_titulo,f.id AS formulario_id FROM formulario_respostas r INNER JOIN formularios f ON f.id=r.formulario_id WHERE r.id=:id LIMIT 1'); $stmt->execute(['id'=>$id]); $resp=$stmt->fetch();
if(!$resp){http_response_code(404);exit('Resposta não encontrada.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::validate($_POST['_token'] ?? null)){Session::flash('error','Token de segurança inválido.');}
    else { $status=(string)($_POST['status'] ?? 'lida'); if(in_array($status,['nova','lida','arquivada'],true)){ $pdo->prepare('UPDATE formulario_respostas SET status=:status WHERE id=:id')->execute(['status'=>$status,'id'=>$id]); logAction($pdo,'formulario.resposta.status','formulario_respostas',$id,$status); Session::flash('success','Status atualizado.'); } }
    header('Location: '.url('admin/formularios/resposta.php?id='.$id));exit;
}
if($resp['status']==='nova'){ $pdo->prepare("UPDATE formulario_respostas SET status='lida' WHERE id=:id")->execute(['id'=>$id]); $resp['status']='lida'; }
$stmt=$pdo->prepare('SELECT c.rotulo,c.tipo,v.valor FROM formulario_resposta_valores v INNER JOIN formulario_campos c ON c.id=v.campo_id WHERE v.resposta_id=:id ORDER BY c.ordem,c.id'); $stmt->execute(['id'=>$id]); $values=$stmt->fetchAll();
$pageTitle='Resposta #'.$id; require __DIR__.'/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Resposta #<?= $id ?></h1><p class="text-secondary mb-0"><?= e($resp['formulario_titulo']) ?> · <?= e(formatDateBr($resp['created_at'])) ?></p></div><a class="btn btn-outline-secondary" href="<?= e(url('admin/formularios/respostas.php?id='.(int)$resp['formulario_id'])) ?>">Voltar</a></div>
<div class="row g-4"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4"><?php foreach($values as $v): ?><div class="mb-4"><div class="small text-secondary mb-1"><?= e($v['rotulo']) ?></div><div class="fw-medium" style="white-space:pre-wrap"><?= e((string)$v['valor']) ?></div></div><?php endforeach; ?></div></div></div><div class="col-xl-4"><div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Detalhes</div><div class="card-body"><div class="small text-secondary">IP</div><div class="mb-3"><?= e($resp['ip'] ?: 'Não disponível') ?></div><div class="small text-secondary">Origem</div><div class="small text-break mb-3"><?= e($resp['origem'] ?: 'Não informada') ?></div><div class="small text-secondary">Navegador</div><div class="small text-break"><?= e($resp['user_agent'] ?: 'Não informado') ?></div></div></div><form method="post" class="card border-0 shadow-sm"><div class="card-body"><?= Csrf::field() ?><label class="form-label">Status</label><select class="form-select mb-3" name="status"><option value="nova" <?= $resp['status']==='nova'?'selected':'' ?>>Nova</option><option value="lida" <?= $resp['status']==='lida'?'selected':'' ?>>Lida</option><option value="arquivada" <?= $resp['status']==='arquivada'?'selected':'' ?>>Arquivada</option></select><button class="btn btn-primary w-100">Atualizar</button></div></form></div></div>
<?php require __DIR__.'/../_footer.php'; ?>
