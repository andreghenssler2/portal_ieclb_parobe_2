<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('aparencia.gerenciar');
$pdo = Database::connection();
$error = '';

$labels = [
    'banners'=>'Banners','apresentacao'=>'Apresentação','destaque'=>'Notícia em destaque','agenda'=>'Agenda','noticias'=>'Últimas notícias','galerias'=>'Galerias','comunidades'=>'Comunidades','html'=>'HTML personalizado'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['acao'] ?? 'salvar');
            if ($action === 'adicionar_html') {
                $stmt=$pdo->prepare("INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao) VALUES ('home','html',:titulo,:conteudo,1,:ordem,NULL)");
                $max=(int)$pdo->query("SELECT COALESCE(MAX(ordem),0) FROM widgets WHERE area='home'")->fetchColumn();
                $stmt->execute(['titulo'=>trim((string)($_POST['novo_titulo']??'Bloco personalizado')) ?: 'Bloco personalizado','conteudo'=>(string)($_POST['novo_conteudo']??''),'ordem'=>$max+10]);
                logAction($pdo,'widget.criar','widgets',(int)$pdo->lastInsertId(),'HTML personalizado');
                Session::flash('success','Widget personalizado adicionado.');
            } elseif ($action === 'excluir') {
                $id=(int)($_POST['id']??0);
                $stmt=$pdo->prepare("DELETE FROM widgets WHERE id=:id AND area='home' AND tipo='html'"); $stmt->execute(['id'=>$id]);
                logAction($pdo,'widget.excluir','widgets',$id);
                Session::flash('success','Widget removido.');
            } else {
                $ids=array_map('intval',(array)($_POST['widget_id']??[]));
                $titles=(array)($_POST['titulo']??[]); $orders=(array)($_POST['ordem']??[]); $contents=(array)($_POST['conteudo']??[]); $active=(array)($_POST['ativo']??[]);
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("UPDATE widgets SET titulo=:titulo, conteudo=:conteudo, ativo=:ativo, ordem=:ordem WHERE id=:id AND area='home'");
                foreach($ids as $id){
                    if($id<=0) continue;
                    $stmt->execute(['titulo'=>trim((string)($titles[$id]??'')),'conteudo'=>(string)($contents[$id]??''),'ativo'=>isset($active[$id])?1:0,'ordem'=>(int)($orders[$id]??0),'id'=>$id]);
                }
                $pdo->commit(); logAction($pdo,'widgets.atualizar','widgets',null,'Widgets da Home atualizados'); Session::flash('success','Widgets atualizados.');
            }
            header('Location: '.url('admin/aparencia/widgets.php')); exit;
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
    }
}

$widgets=$pdo->query("SELECT * FROM widgets WHERE area='home' ORDER BY ordem,id")->fetchAll();
$pageTitle='Widgets'; require __DIR__.'/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Widgets da Home</h1><p class="text-secondary mb-0">Ative, desative e ordene as seções da página inicial.</p></div><a class="btn btn-outline-primary" href="<?= e(url()) ?>" target="_blank">Visualizar Home</a></div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<form method="post" class="mb-4"><?=Csrf::field()?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th style="width:90px">Ordem</th><th>Widget</th><th>Título exibido</th><th class="text-center" style="width:100px">Ativo</th></tr></thead><tbody>
<?php foreach($widgets as $w): $id=(int)$w['id']; ?>
<tr><td><input type="hidden" name="widget_id[]" value="<?=$id?>"><input class="form-control form-control-sm" type="number" name="ordem[<?=$id?>]" value="<?=(int)$w['ordem']?>" step="10"></td><td><div class="fw-semibold"><?=e($labels[$w['tipo']]??$w['tipo'])?></div><div class="small text-secondary"><code><?=e($w['tipo'])?></code></div><?php if($w['tipo']==='html'):?><textarea class="form-control form-control-sm mt-2" rows="4" name="conteudo[<?=$id?>]" placeholder="HTML do bloco"><?=e($w['conteudo'])?></textarea><?php else:?><input type="hidden" name="conteudo[<?=$id?>]" value="<?=e($w['conteudo'])?>"><?php endif;?></td><td><input class="form-control form-control-sm" name="titulo[<?=$id?>]" value="<?=e($w['titulo'])?>" placeholder="Usar título padrão"></td><td class="text-center"><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="ativo[<?=$id?>]" value="1" <?=(int)$w['ativo']===1?'checked':''?>></div><?php if($w['tipo']==='html'):?><button class="btn btn-sm btn-link text-danger d-block mx-auto mt-1" type="submit" name="acao" value="excluir" formaction="<?=e(url('admin/aparencia/widgets.php'))?>" onclick="this.form.querySelector('input[name=id]')?.remove(); const i=document.createElement('input');i.type='hidden';i.name='id';i.value='<?=$id?>';this.form.append(i);return confirm('Excluir este widget?');">Excluir</button><?php endif;?></td></tr>
<?php endforeach;?>
</tbody></table></div></div></div><div class="mt-3"><button class="btn btn-primary px-4" name="acao" value="salvar">Salvar ordem e visibilidade</button></div></form>
<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Adicionar HTML personalizado</div><div class="card-body p-4"><form method="post"><?=Csrf::field()?><input type="hidden" name="acao" value="adicionar_html"><div class="row g-3"><div class="col-md-4"><label class="form-label">Título</label><input class="form-control" name="novo_titulo" value="Bloco personalizado"></div><div class="col-md-8"><label class="form-label">HTML</label><textarea class="form-control font-monospace" name="novo_conteudo" rows="5" placeholder="<section>...</section>"></textarea></div><div class="col-12"><div class="form-text mb-2">O HTML é inserido na Home como conteúdo administrativo confiável. Use apenas código que você conhece.</div><button class="btn btn-outline-primary">Adicionar widget</button></div></div></form></div></div>
<?php require __DIR__.'/../_footer.php';?>
