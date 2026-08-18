<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$post = ['titulo'=>'','resumo'=>'','conteudo'=>'','comunidade_id'=>'','categoria_id'=>'','status'=>'rascunho','destaque'=>0,'publicado_em'=>''];
if ($id) {
    $stmt=$pdo->prepare('SELECT * FROM posts WHERE id=:id'); $stmt->execute(['id'=>$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Notícia não encontrada.'); }
    $post=$found;
}
$comunidades=$pdo->query('SELECT id,nome FROM comunidades WHERE ativa=1 ORDER BY ordem,nome')->fetchAll();
$categorias=$pdo->query('SELECT id,nome FROM categorias ORDER BY nome')->fetchAll();
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) { $error='Token de segurança inválido.'; }
    else {
        $titulo=trim((string)($_POST['titulo'] ?? '')); $conteudo=trim((string)($_POST['conteudo'] ?? ''));
        if ($titulo==='' || $conteudo==='') { $error='Título e conteúdo são obrigatórios.'; }
        else {
            $status=(string)($_POST['status'] ?? 'rascunho');
            if (!in_array($status,['rascunho','agendado','publicado','arquivado'],true)) $status='rascunho';
            $publicadoEm=trim((string)($_POST['publicado_em'] ?? ''));
            if ($publicadoEm !== '') $publicadoEm=(new DateTime($publicadoEm))->format('Y-m-d H:i:s');
            elseif ($status==='publicado') $publicadoEm=date('Y-m-d H:i:s'); else $publicadoEm=null;
            $data=[
                'autor_id'=>Auth::id(),
                'comunidade_id'=>($_POST['comunidade_id'] ?? '')!=='' ? (int)$_POST['comunidade_id'] : null,
                'categoria_id'=>($_POST['categoria_id'] ?? '')!=='' ? (int)$_POST['categoria_id'] : null,
                'titulo'=>$titulo,
                'slug'=>uniqueSlug($pdo,'posts',$titulo,$id),
                'resumo'=>trim((string)($_POST['resumo'] ?? '')) ?: null,
                'conteudo'=>$conteudo,
                'status'=>$status,
                'destaque'=>isset($_POST['destaque']) ? 1 : 0,
                'publicado_em'=>$publicadoEm,
            ];
            if ($id) {
                $data['id']=$id;
                $stmt=$pdo->prepare('UPDATE posts SET autor_id=:autor_id,comunidade_id=:comunidade_id,categoria_id=:categoria_id,titulo=:titulo,slug=:slug,resumo=:resumo,conteudo=:conteudo,status=:status,destaque=:destaque,publicado_em=:publicado_em WHERE id=:id');
            } else {
                $stmt=$pdo->prepare('INSERT INTO posts (autor_id,comunidade_id,categoria_id,titulo,slug,resumo,conteudo,status,destaque,publicado_em) VALUES (:autor_id,:comunidade_id,:categoria_id,:titulo,:slug,:resumo,:conteudo,:status,:destaque,:publicado_em)');
            }
            $stmt->execute($data);
            Session::flash('success',$id?'Notícia atualizada.':'Notícia criada.');
            header('Location: '.url('admin/noticias/index.php')); exit;
        }
    }
}
$pageTitle=$id?'Editar notícia':'Nova notícia';
require __DIR__ . '/../_header.php';
?>
<h1 class="h3 mb-4"><?= e($pageTitle) ?></h1>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body p-4">
<?= Csrf::field() ?>
<div class="row g-3">
<div class="col-12"><label class="form-label">Título</label><input class="form-control form-control-lg" name="titulo" value="<?= e($post['titulo']) ?>" required></div>
<div class="col-md-6"><label class="form-label">Comunidade</label><select class="form-select" name="comunidade_id"><option value="">Paroquial / Todas</option><?php foreach($comunidades as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['comunidade_id']===(string)$c['id']?'selected':'' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Categoria</label><select class="form-select" name="categoria_id"><option value="">Sem categoria</option><?php foreach($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['categoria_id']===(string)$c['id']?'selected':'' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">Resumo</label><textarea class="form-control" name="resumo" rows="3"><?= e($post['resumo'] ?? '') ?></textarea></div>
<div class="col-12"><label class="form-label">Conteúdo</label><textarea id="conteudo" class="form-control" name="conteudo" rows="14" required><?= e($post['conteudo']) ?></textarea></div>
<div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['rascunho'=>'Rascunho','agendado'=>'Agendado','publicado'=>'Publicado','arquivado'=>'Arquivado'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= $post['status']===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Publicar em</label><input type="datetime-local" class="form-control" name="publicado_em" value="<?= $post['publicado_em'] ? e((new DateTime($post['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>"></div>
<div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="destaque" id="destaque" <?= $post['destaque']?'checked':'' ?>><label class="form-check-label" for="destaque">Destacar na página inicial</label></div></div>
</div>
<div class="mt-4 d-flex gap-2"><button class="btn btn-primary">Salvar</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/noticias/index.php')) ?>">Cancelar</a></div>
</div></form>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script>tinymce.init({selector:'#conteudo',height:480,menubar:false,plugins:'link lists table code image media',toolbar:'undo redo | blocks | bold italic | bullist numlist | link table | alignleft aligncenter alignright | code'});</script>
<?php require __DIR__ . '/../_footer.php'; ?>
