<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('documentos.gerenciar');
$pdo = Database::connection();
$error = '';
$editId = max(0, (int)($_GET['editar'] ?? $_POST['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($action === 'delete') {
                if ($id <= 0) throw new RuntimeException('Categoria inválida.');
                $stmt=$pdo->prepare('SELECT nome FROM documento_categorias WHERE id=:id LIMIT 1');
                $stmt->execute(['id'=>$id]);
                $name=$stmt->fetchColumn();
                if($name===false) throw new RuntimeException('Categoria não encontrada.');
                $pdo->prepare('DELETE FROM documento_categorias WHERE id=:id')->execute(['id'=>$id]);
                logAction($pdo,'documento_categoria.excluir','documento_categorias',$id,(string)$name);
                Session::flash('success','Categoria excluída. Os documentos vinculados ficaram sem categoria.');
                header('Location: '.url('admin/documentos/categorias.php')); exit;
            }

            $name=trim((string)($_POST['nome']??''));
            if($name==='') throw new RuntimeException('Informe o nome da categoria.');
            $slugInput=trim((string)($_POST['slug']??''));
            $slug=uniqueSlug($pdo,'documento_categorias',$slugInput!==''?$slugInput:$name,$id>0?$id:null);
            $description=trim((string)($_POST['descricao']??''));
            $order=max(-99999,min(99999,(int)($_POST['ordem']??0)));
            $active=isset($_POST['ativo'])?1:0;

            if($id>0){
                $stmt=$pdo->prepare('UPDATE documento_categorias SET nome=:nome,slug=:slug,descricao=:descricao,ordem=:ordem,ativo=:ativo,updated_at=NOW() WHERE id=:id');
                $stmt->execute(['nome'=>$name,'slug'=>$slug,'descricao'=>$description?:null,'ordem'=>$order,'ativo'=>$active,'id'=>$id]);
                logAction($pdo,'documento_categoria.editar','documento_categorias',$id,$name);
                Session::flash('success','Categoria atualizada.');
            }else{
                $stmt=$pdo->prepare('INSERT INTO documento_categorias (nome,slug,descricao,ordem,ativo,created_at,updated_at) VALUES (:nome,:slug,:descricao,:ordem,:ativo,NOW(),NOW())');
                $stmt->execute(['nome'=>$name,'slug'=>$slug,'descricao'=>$description?:null,'ordem'=>$order,'ativo'=>$active]);
                $id=(int)$pdo->lastInsertId();
                logAction($pdo,'documento_categoria.criar','documento_categorias',$id,$name);
                Session::flash('success','Categoria criada.');
            }
            header('Location: '.url('admin/documentos/categorias.php')); exit;
        } catch(Throwable $e) {
            $error=$e->getMessage();
        }
    }
}

$edit=null;
if($editId>0){
    $stmt=$pdo->prepare('SELECT * FROM documento_categorias WHERE id=:id LIMIT 1');
    $stmt->execute(['id'=>$editId]);
    $edit=$stmt->fetch()?:null;
}

$q=trim((string)($_GET['q']??''));
$page=max(1,(int)($_GET['pagina']??1));
$where=''; $params=[];
if($q!==''){ $where=' WHERE (dc.nome LIKE :q OR dc.slug LIKE :q OR dc.descricao LIKE :q)'; $params['q']='%'.$q.'%'; }
$count=$pdo->prepare('SELECT COUNT(*) FROM documento_categorias dc'.$where); $count->execute($params); $total=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($total/50)); $page=max(1,min($page,$pages)); $offset=($page-1)*50;
$sql="SELECT dc.*,COUNT(d.id) total_documentos FROM documento_categorias dc LEFT JOIN documentos d ON d.categoria_id=dc.id {$where} GROUP BY dc.id ORDER BY dc.ordem ASC,dc.nome ASC LIMIT :limit OFFSET :offset";
$stmt=$pdo->prepare($sql); foreach($params as $k=>$v)$stmt->bindValue(':'.$k,$v); $stmt->bindValue(':limit',50,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();$categories=$stmt->fetchAll()?:[];

$pageTitle='Categorias de Documentos';
require __DIR__.'/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h1 class="h3 mb-1">Categorias de Documentos</h1><p class="text-secondary mb-0">Organize o catálogo público de arquivos.</p></div>
    <a class="btn btn-outline-secondary" href="<?=e(url('admin/documentos/index.php'))?>">Voltar aos documentos</a>
</div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<div class="row g-4">
    <div class="col-xl-4">
        <form method="post" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><?=$edit?'Editar categoria':'Adicionar categoria'?></div>
            <div class="card-body p-4">
                <?=Csrf::field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
                <div class="mb-3"><label class="form-label">Nome</label><input class="form-control" name="nome" maxlength="120" required value="<?=e($edit['nome']??'')?>"></div>
                <div class="mb-3"><label class="form-label">Slug</label><input class="form-control" name="slug" maxlength="140" value="<?=e($edit['slug']??'')?>" placeholder="gerada-automaticamente"></div>
                <div class="mb-3"><label class="form-label">Descrição</label><textarea class="form-control" name="descricao" rows="4"><?=e($edit['descricao']??'')?></textarea></div>
                <div class="mb-3"><label class="form-label">Ordem</label><input class="form-control" type="number" name="ordem" value="<?=e((string)($edit['ordem']??0))?>"></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="ativo" id="active" <?=!$edit || !empty($edit['ativo'])?'checked':''?>><label class="form-check-label" for="active">Categoria ativa</label></div>
                <button class="btn btn-primary w-100"><?=$edit?'Salvar alterações':'Adicionar categoria'?></button>
                <?php if($edit):?><a class="btn btn-link w-100 mt-2" href="<?=e(url('admin/documentos/categorias.php'))?>">Cancelar edição</a><?php endif;?>
            </div>
        </form>
    </div>
    <div class="col-xl-8">
        <form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body d-flex gap-2"><input class="form-control" type="search" name="q" value="<?=e($q)?>" placeholder="Pesquisar categorias"><button class="btn btn-outline-primary">Pesquisar</button><?php if($q!==''):?><a class="btn btn-outline-secondary" href="<?=e(url('admin/documentos/categorias.php'))?>">Limpar</a><?php endif;?></div></form>
        <div class="card border-0 shadow-sm">
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nome</th><th>Slug</th><th class="text-center">Documentos</th><th>Ativa</th><th></th></tr></thead><tbody>
            <?php if(!$categories):?><tr><td colspan="5" class="text-secondary">Nenhuma categoria encontrada.</td></tr><?php endif;?>
            <?php foreach($categories as $category):?><tr>
                <td><div class="fw-semibold"><?=e($category['nome'])?></div><?php if($category['descricao']):?><div class="small text-secondary"><?=e($category['descricao'])?></div><?php endif;?></td>
                <td><code><?=e($category['slug'])?></code></td>
                <td class="text-center"><?=(int)$category['total_documentos']?></td>
                <td><?=$category['ativo']?'<span class="badge text-bg-success">Sim</span>':'<span class="text-secondary">Não</span>'?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?=e(url('admin/documentos/categorias.php?editar='.(int)$category['id']))?>">Editar</a> <form class="d-inline" method="post" onsubmit="return confirm('Excluir esta categoria?');"><?=Csrf::field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$category['id']?>"><button class="btn btn-sm btn-outline-danger">Excluir</button></form></td>
            </tr><?php endforeach;?>
            </tbody></table></div>
            <?php if($total>0):?><div class="card-footer bg-white d-flex justify-content-between align-items-center gap-3 flex-wrap"><span class="small text-secondary">Exibindo <?=$offset+1?>–<?=min($offset+count($categories),$total)?> de <?=$total?> · 50 por página</span>
            <?php if($pages>1):?><nav><ul class="pagination pagination-sm mb-0"><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):$paramsPage=['pagina'=>$p];if($q!=='')$paramsPage['q']=$q;?><li class="page-item <?=$p===$page?'active':''?>"><a class="page-link" href="<?=e(url('admin/documentos/categorias.php?'.http_build_query($paramsPage)))?>"><?=$p?></a></li><?php endfor;?></ul></nav><?php endif;?></div><?php endif;?>
        </div>
    </div>
</div>
<?php require __DIR__.'/../_footer.php';?>
