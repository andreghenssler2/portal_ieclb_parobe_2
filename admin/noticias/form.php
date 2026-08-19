<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$defaultCategory = siteConfig($pdo, 'writing_default_category', '');
$defaultStatus = siteConfig($pdo, 'writing_default_status', 'rascunho');
if (!in_array($defaultStatus, ['rascunho','publicado'], true)) $defaultStatus = 'rascunho';
$defaultCommentsOpen = siteConfig($pdo, 'comments_default_open', '1') === '1' ? 1 : 0;
$post = ['titulo'=>'','resumo'=>'','seo_titulo'=>'','seo_descricao'=>'','seo_noindex'=>0,'conteudo'=>'','comunidade_id'=>'','categoria_id'=>$defaultCategory,'status'=>$defaultStatus,'destaque'=>0,'comentarios_ativos'=>$defaultCommentsOpen,'publicado_em'=>'','imagem_capa_id'=>''];
if ($id) {
    $stmt=$pdo->prepare('SELECT * FROM posts WHERE id=:id'); $stmt->execute(['id'=>$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Notícia não encontrada.'); }
    if (($found['status'] ?? '') === 'lixeira') {
        Session::flash('error', 'Restaure a notícia da Lixeira antes de editá-la.');
        header('Location: ' . url('admin/noticias/index.php?status=lixeira'));
        exit;
    }
    $post=$found;
}
$comunidades=$pdo->query('SELECT id,nome FROM comunidades WHERE ativa=1 ORDER BY ordem,nome')->fetchAll();
$categorias=CategoryService::tree($pdo);
$tags=$pdo->query('SELECT id,nome,slug FROM tags ORDER BY nome')->fetchAll();
$selectedTags=[];
if ($id) { $st=$pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id=:id'); $st->execute(['id'=>$id]); $selectedTags=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); }
$midias=$pdo->query("SELECT id,caminho,titulo,alt_text,nome_original,largura,altura FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC")->fetchAll();
$imagemCapaAtual = !empty($post['imagem_capa_id']) ? MediaService::find($pdo, (int)$post['imagem_capa_id']) : null;
$revisionCount = 0;
if ($id) { try { $revisionCount = RevisionService::count($pdo, 'post', $id); } catch (Throwable $ignored) { $revisionCount = 0; } }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach (['titulo','resumo','seo_titulo','seo_descricao','conteudo','comunidade_id','categoria_id','status','publicado_em','imagem_capa_id'] as $field) {
        if (array_key_exists($field,$_POST)) $post[$field]=$_POST[$field];
    }
    $post['destaque']=isset($_POST['destaque']) ? 1 : 0;
    $post['comentarios_ativos']=isset($_POST['comentarios_ativos']) ? 1 : 0;
    $post['seo_noindex']=isset($_POST['seo_noindex']) ? 1 : 0;
    $selectedTags=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['tags'] ?? [])), static fn($v)=>$v>0)));

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
                    'seo_titulo'=>trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao'=>trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex'=>isset($_POST['seo_noindex']) ? 1 : 0,
                    'status'=>$status,
                    'destaque'=>isset($_POST['destaque']) ? 1 : 0,
                    'comentarios_ativos'=>isset($_POST['comentarios_ativos']) ? 1 : 0,
                    'publicado_em'=>$publicadoEm,
                ];
                if ($id) {
                    $data['id']=$id;
                    $stmt=$pdo->prepare('UPDATE posts SET autor_id=:autor_id,comunidade_id=:comunidade_id,categoria_id=:categoria_id,titulo=:titulo,slug=:slug,resumo=:resumo,conteudo=:conteudo,imagem_capa_id=:imagem_capa_id,seo_titulo=:seo_titulo,seo_descricao=:seo_descricao,seo_noindex=:seo_noindex,status=:status,destaque=:destaque,comentarios_ativos=:comentarios_ativos,publicado_em=:publicado_em WHERE id=:id');
                } else {
                    $stmt=$pdo->prepare('INSERT INTO posts (autor_id,comunidade_id,categoria_id,titulo,slug,resumo,conteudo,imagem_capa_id,seo_titulo,seo_descricao,seo_noindex,status,destaque,comentarios_ativos,publicado_em) VALUES (:autor_id,:comunidade_id,:categoria_id,:titulo,:slug,:resumo,:conteudo,:imagem_capa_id,:seo_titulo,:seo_descricao,:seo_noindex,:status,:destaque,:comentarios_ativos,:publicado_em)');
                }
                $pdo->beginTransaction();
                try {
                    if ($id) {
                        RevisionService::create($pdo, 'post', $id, Auth::id());
                    }
                    $stmt->execute($data);
                    $savedId = $id ?: (int)$pdo->lastInsertId();
                    $pdo->prepare('DELETE FROM post_tags WHERE post_id=:post_id')->execute(['post_id'=>$savedId]);
                    if ($selectedTags) {
                        $validStmt=$pdo->prepare('SELECT id FROM tags WHERE id IN (' . implode(',', array_fill(0,count($selectedTags),'?')) . ')');
                        $validStmt->execute($selectedTags);
                        $validIds=array_map('intval',$validStmt->fetchAll(PDO::FETCH_COLUMN));
                        $link=$pdo->prepare('INSERT INTO post_tags (post_id,tag_id) VALUES (:post_id,:tag_id)');
                        foreach($validIds as $tagId) $link->execute(['post_id'=>$savedId,'tag_id'=>$tagId]);
                    }
                    $pdo->commit();
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $txe;
                }
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
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><h1 class="h3 mb-0"><?= e($pageTitle) ?></h1><?php if ($id): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/revisoes/index.php?tipo=post&id=' . $id)) ?>"><i class="bi bi-clock-history me-1"></i>Revisões (<?= (int)$revisionCount ?>)</a><?php endif; ?></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body p-4">
<?= Csrf::field() ?>
<div class="row g-3">
<div class="col-12"><label class="form-label">Título</label><input class="form-control form-control-lg" name="titulo" value="<?= e((string)$post['titulo']) ?>" required></div>
<div class="col-md-6"><label class="form-label">Comunidade</label><select class="form-select" name="comunidade_id"><option value="">Paroquial / Todas</option><?php foreach($comunidades as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['comunidade_id']===(string)$c['id']?'selected':'' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Categoria</label><select class="form-select" name="categoria_id"><option value="">Sem categoria</option><?php foreach($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$post['categoria_id']===(string)$c['id']?'selected':'' ?>><?= e(CategoryService::optionLabel($c)) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">Resumo</label><textarea class="form-control" name="resumo" rows="3"><?= e((string)($post['resumo'] ?? '')) ?></textarea></div>
<div class="col-12"><label class="form-label">Tags</label><div class="border rounded-3 p-3"><div class="d-flex flex-wrap gap-2 mb-3"><input type="search" id="tagSearch" class="form-control form-control-sm" style="max-width:320px" placeholder="Buscar tag..."><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url('admin/tags/index.php')) ?>">Gerenciar tags</a></div><div id="tagChoices" class="d-flex flex-wrap gap-2"><?php foreach($tags as $tag): ?><label class="tag-choice" data-tag-name="<?= e(mb_strtolower((string)$tag['nome'])) ?>"><input class="form-check-input me-1" type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>" <?= in_array((int)$tag['id'],$selectedTags,true)?'checked':'' ?>><?= e($tag['nome']) ?></label><?php endforeach; ?><?php if(!$tags): ?><span class="text-secondary small">Nenhuma tag cadastrada.</span><?php endif; ?></div><div class="form-text mt-2">Uma notícia pode ter várias tags.</div></div></div>

<div class="col-12"><div class="border rounded-3 p-3 bg-light-subtle">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><label class="form-label fw-semibold mb-0">Imagem destacada</label><div class="form-text mt-0">Faça upload ou escolha visualmente uma imagem da Biblioteca de Mídia.</div></div><button class="btn btn-sm btn-outline-primary" type="button" data-media-featured-open>Escolher da biblioteca</button></div>
<input type="hidden" name="imagem_capa_id" id="imagemCapaId" value="<?= e((string)($post['imagem_capa_id'] ?? '')) ?>">
<div class="row g-3 align-items-start"><div class="col-lg-5"><input class="form-control" type="file" name="imagem_capa_upload" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-text">JPG, PNG, WEBP ou GIF. Máximo <?= e(formatBytes(mediaUploadMaxSize($pdo))) ?>.</div></div>
<div class="col-lg-7"><div id="imagemCapaPreview" class="featured-picker-preview"><?php if ($imagemCapaAtual && MediaService::isImage($imagemCapaAtual)): ?><div class="d-flex align-items-center gap-3"><img src="<?= e(mediaUrl($imagemCapaAtual['caminho'])) ?>" alt="<?= e($imagemCapaAtual['alt_text'] ?: $imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?>" class="img-thumbnail featured-preview"><div><div class="fw-semibold"><?= e($imagemCapaAtual['titulo'] ?: $imagemCapaAtual['nome_original']) ?></div><button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-media-featured-remove>Remover imagem</button></div></div><?php else: ?><div class="text-secondary small">Nenhuma imagem selecionada.</div><?php endif; ?></div></div></div>
</div></div>

<div class="col-12"><label class="form-label">Conteúdo</label><textarea id="conteudo" class="form-control" name="conteudo" rows="14"><?= e((string)$post['conteudo']) ?></textarea></div>
<div class="col-12"><div class="border rounded-3 p-3"><div class="fw-semibold mb-3">SEO do conteúdo</div><div class="row g-3"><div class="col-12"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="180" value="<?= e((string)($post['seo_titulo'] ?? '')) ?>" placeholder="Se vazio, usa o título da notícia"></div><div class="col-12"><label class="form-label">Meta description</label><textarea class="form-control" name="seo_descricao" maxlength="320" rows="2" placeholder="Se vazia, usa o resumo"><?= e((string)($post['seo_descricao'] ?? '')) ?></textarea></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="seoNoindex" <?= !empty($post['seo_noindex']) ? 'checked' : '' ?>><label class="form-check-label" for="seoNoindex">Não permitir que esta notícia seja indexada pelos buscadores</label></div></div></div></div></div>
<div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['rascunho'=>'Rascunho','agendado'=>'Agendado','publicado'=>'Publicado','arquivado'=>'Arquivado'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= $post['status']===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Publicar em</label><input type="datetime-local" class="form-control" name="publicado_em" value="<?= $post['publicado_em'] ? e((new DateTime((string)$post['publicado_em']))->format('Y-m-d\TH:i')) : '' ?>"></div>
<div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="destaque" id="destaque" <?= $post['destaque']?'checked':'' ?>><label class="form-check-label" for="destaque">Destacar na página inicial</label></div></div>
<div class="col-12"><div class="border rounded-3 p-3 bg-light-subtle"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="comentarios_ativos" id="comentariosAtivos" <?= !empty($post['comentarios_ativos'])?'checked':'' ?>><label class="form-check-label fw-semibold" for="comentariosAtivos">Permitir comentários nesta notícia</label><div class="form-text">A configuração global em Configurações &gt; Discussão também precisa estar ativada para aceitar novos comentários.</div></div></div></div>
</div>
<div class="mt-4 d-flex gap-2"><button class="btn btn-primary">Salvar</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/noticias/index.php')) ?>">Cancelar</a><?php if ($id && !empty($post['slug'])): ?><a class="btn btn-outline-primary" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Visualizar</a><?php endif; ?></div>
</div></form>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>
<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

tinymce.init({
    selector:'#conteudo',
    height:480,
    menubar:false,
    plugins:'link lists table code image media',
    toolbar:'undo redo | blocks | bold italic | bullist numlist | link portalmedia table | alignleft aligncenter alignright | code',
    setup:function(editor){
        editor.ui.registry.addButton('portalmedia', {
            icon: 'image',
            tooltip: 'Inserir imagens da Biblioteca de Mídia',
            onAction: function () { PortalMediaPicker.openForEditor(editor); }
        });
        editor.on('change keyup', function(){ editor.save(); });
    }
});

document.querySelector('form').addEventListener('submit', function(){
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});

document.getElementById('tagSearch')?.addEventListener('input', function(){
    const q=this.value.trim().toLocaleLowerCase('pt-BR');
    document.querySelectorAll('#tagChoices [data-tag-name]').forEach(function(el){ el.classList.toggle('d-none', q!=='' && !el.dataset.tagName.includes(q)); });
});

PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('imagemCapaId'),
    preview: document.getElementById('imagemCapaPreview')
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
