<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$post = ['titulo'=>'','resumo'=>'','conteudo'=>'','comunidade_id'=>'','categoria_id'=>'','status'=>'rascunho','destaque'=>0,'publicado_em'=>'','imagem_capa_id'=>''];
if ($id) {
    $stmt=$pdo->prepare('SELECT * FROM posts WHERE id=:id'); $stmt->execute(['id'=>$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Notícia não encontrada.'); }
    $post=$found;
}
$comunidades=$pdo->query('SELECT id,nome FROM comunidades WHERE ativa=1 ORDER BY ordem,nome')->fetchAll();
$categorias=$pdo->query('SELECT id,nome FROM categorias ORDER BY nome')->fetchAll();
$midias=$pdo->query("SELECT id,caminho,titulo,alt_text,nome_original FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC LIMIT 80")->fetchAll();
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach (['titulo','resumo','conteudo','comunidade_id','categoria_id','status','publicado_em','imagem_capa_id'] as $field) {
        if (array_key_exists($field,$_POST)) $post[$field]=$_POST[$field];
    }
    $post['destaque']=isset($_POST['destaque']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) { $error='Token de segurança inválido.'; }
    else {
        $titulo=trim((string)($_POST['titulo'] ?? ''));
        $conteudo=trim((string)($_POST['conteudo'] ?? ''));
        $conteudoTexto = html_entity_decode(strip_tags($conteudo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $conteudoTexto = trim(str_replace("\u{00A0}", ' ', $conteudoTexto));
        if ($titulo==='' || $conteudoTexto==='') { $error='Título e conteúdo são obrigatórios.'; }
        else {
            try {
                $imagemCapaId = ($_POST['imagem_capa_id'] ?? '') !== '' ? (int)$_POST['imagem_capa_id'] : null;

                if (isset($_FILES['imagem_capa_upload']) && (int)($_FILES['imagem_capa_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newMedia = MediaService::upload($pdo, $_FILES['imagem_capa_upload'], (int)Auth::id(), $titulo, $titulo);
                    if (!MediaService::isImage($newMedia)) {
                        MediaService::delete($pdo, (int)$newMedia['id']);
                        throw new RuntimeException('A imagem destacada precisa ser um arquivo de imagem.');
                    }
                    $imagemCapaId = (int)$newMedia['id'];
                    logAction($pdo, 'midia.upload', 'midias', $imagemCapaId, 'Imagem destacada de notícia');
                }

                if ($imagemCapaId !== null) {
                    $selectedMedia = MediaService::find($pdo, $imagemCapaId);
                    if (!$selectedMedia || !MediaService::isImage($selectedMedia)) {
                        throw new RuntimeException('A imagem destacada selecionada é inválida.');
                    }
                }

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
                    'imagem_capa_id'=>$imagemCapaId,
                    'status'=>$status,
                    'destaque'=>isset($_POST['destaque']) ? 1 : 0,
                    'publicado_em'=>$publicadoEm,
                ];
                if ($id) {
                    $data['id']=$id;
                    $stmt=$pdo->prepare('UPDATE posts SET autor_id=:autor_id,comunidade_id=:comunidade_id,categoria_id=:categoria_id,titulo=:titulo,slug=:slug,resumo=:resumo,conteudo=:conteudo,imagem_capa_id=:imagem_capa_id,status=:status,destaque=:destaque,publicado_em=:publicado_em WHERE id=:id');
                } else {
                    $stmt=$pdo->prepare('INSERT INTO posts (autor_id,comunidade_id,categoria_id,titulo,slug,resumo,conteudo,imagem_capa_id,status,destaque,publicado_em) VALUES (:autor_id,:comunidade_id,:categoria_id,:titulo,:slug,:resumo,:conteudo,:imagem_capa_id,:status,:destaque,:publicado_em)');
                }
                $stmt->execute($data);
                $savedId = $id ?: (int)$pdo->lastInsertId();
                logAction($pdo, $id?'noticia.editar':'noticia.criar', 'posts', $savedId, $titulo);
                Session::flash('success',$id?'Notícia atualizada.':'Notícia criada.');
                header('Location: '.url('admin/noticias/index.php')); exit;
            } catch (Throwable $e) {
                $error=$e->getMessage();
            }
        }
    }
}
$pageTitle=$id?'Editar notícia':'Nova notícia';
require __DIR__ . '/../_header.php';
?>
<h1 class="h3 mb-4"><?= e($pageTitle) ?></h1>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body p-4">
<?= Csrf::field() ?>
<div class="row g-3">
<div class="col-12"><label class="form-label">Título</label><input class="form-control form-control-lg" name="titulo" value="<?= e((string)$post['titulo']) ?>" required></div>
<div class="col-md-6"><label class="form-label">Comunidade</label><select class="form-select" name="comunidade_id"><option value="">Paroquial / Todas</option><?php foreach($comunidades as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['comunidade_id']===(string)$c['id']?'selected':'' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Categoria</label><select class="form-select" name="categoria_id"><option value="">Sem categoria</option><?php foreach($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['categoria_id']===(string)$c['id']?'selected':'' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">Resumo</label><textarea class="form-control" name="resumo" rows="3"><?= e((string)($post['resumo'] ?? '')) ?></textarea></div>

<div class="col-12"><div class="border rounded-3 p-3 bg-light-subtle"><div class="d-flex justify-content-between align-items-center mb-3"><div><label class="form-label fw-semibold mb-0">Imagem destacada</label><div class="form-text mt-0">Envie uma nova imagem ou escolha uma já existente na biblioteca.</div></div><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url('admin/midias/index.php?tipo=imagens')) ?>">Abrir biblioteca</a></div>
<div class="row g-3"><div class="col-lg-5"><input class="form-control" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-text">JPG, PNG, WEBP ou GIF. Máximo <?= e(formatBytes(UPLOAD_MAX_SIZE)) ?>.</div></div>
<div class="col-lg-7"><select class="form-select" name="imagem_capa_id" id="imagemCapaSelect"><option value="">Sem imagem destacada</option><?php foreach($midias as $m): ?><option value="<?= (int)$m['id'] ?>" data-url="<?= e(mediaUrl($m['caminho'])) ?>" <?= (string)($post['imagem_capa_id'] ?? '')===(string)$m['id']?'selected':'' ?>><?= e($m['titulo'] ?: $m['nome_original']) ?></option><?php endforeach; ?></select></div></div>
<div id="imagemCapaPreview" class="mt-3"></div></div></div>

<div class="col-12"><label class="form-label">Conteúdo</label><textarea id="conteudo" class="form-control" name="conteudo" rows="14"><?= e((string)$post['conteudo']) ?></textarea></div>
<div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['rascunho'=>'Rascunho','agendado'=>'Agendado','publicado'=>'Publicado','arquivado'=>'Arquivado'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= $post['status']===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Publicar em</label><input type="datetime-local" class="form-control" name="publicado_em" value="<?= $post['publicado_em'] ? e((new DateTime((string)$post['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>"></div>
<div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="destaque" id="destaque" <?= $post['destaque']?'checked':'' ?>><label class="form-check-label" for="destaque">Destacar na página inicial</label></div></div>
</div>
<div class="mt-4 d-flex gap-2"><button class="btn btn-primary">Salvar</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/noticias/index.php')) ?>">Cancelar</a><?php if ($id && !empty($post['slug'])): ?><a class="btn btn-outline-primary" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Visualizar</a><?php endif; ?></div>
</div></form>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script>
tinymce.init({
    selector:'#conteudo',
    height:480,
    menubar:false,
    plugins:'link lists table code image media',
    toolbar:'undo redo | blocks | bold italic | bullist numlist | link image table | alignleft aligncenter alignright | code',
    setup:function(editor){
        editor.on('change keyup', function(){ editor.save(); });
    }
});
document.querySelector('form').addEventListener('submit', function(){
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});
const select=document.getElementById('imagemCapaSelect'); const preview=document.getElementById('imagemCapaPreview');
function updatePreview(){ const o=select.options[select.selectedIndex]; const src=o?.dataset?.url || ''; preview.innerHTML=src?`<img src="${src}" alt="Prévia" class="img-thumbnail featured-preview">`:''; }
select.addEventListener('change',updatePreview); updatePreview();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
