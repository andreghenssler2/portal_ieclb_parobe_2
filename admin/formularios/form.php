<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$form = ['titulo'=>'','slug'=>'','descricao'=>'','mensagem_sucesso'=>'Sua resposta foi enviada com sucesso.','status'=>'rascunho','ativo'=>1,'publicado_em'=>''];
$campos = [];
$error = '';

if ($id > 0) {
    $stmt=$pdo->prepare('SELECT * FROM formularios WHERE id=:id LIMIT 1');
    $stmt->execute(['id'=>$id]);
    $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Formulário não encontrado.'); }
    $form=$found;
    $stmt=$pdo->prepare('SELECT * FROM formulario_campos WHERE formulario_id=:id ORDER BY ordem,id');
    $stmt->execute(['id'=>$id]);
    $campos=$stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $form=array_merge($form,$_POST);
    $form['ativo']=isset($_POST['ativo']) ? 1 : 0;
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error='Token de segurança inválido.';
    } else {
        try {
            $titulo=trim((string)($_POST['titulo'] ?? ''));
            if ($titulo==='') throw new RuntimeException('Informe o título do formulário.');
            $status=(string)($_POST['status'] ?? 'rascunho');
            if (!in_array($status,['rascunho','publicado','arquivado'],true)) throw new RuntimeException('Status inválido.');
            $slug=uniqueSlug($pdo,'formularios',trim((string)($_POST['slug'] ?? '')) ?: $titulo,$id>0?$id:null);
            $descricao=trim((string)($_POST['descricao'] ?? ''));
            $sucesso=trim((string)($_POST['mensagem_sucesso'] ?? '')) ?: 'Sua resposta foi enviada com sucesso.';
            $ativo=isset($_POST['ativo']) ? 1 : 0;
            $pubInput=trim((string)($_POST['publicado_em'] ?? ''));
            $publicadoEm=null;
            if ($pubInput!=='') {
                $ts=strtotime($pubInput); if ($ts===false) throw new RuntimeException('Data de publicação inválida.');
                $publicadoEm=date('Y-m-d H:i:s',$ts);
            }
            if ($status==='publicado' && !$publicadoEm) $publicadoEm=date('Y-m-d H:i:s');

            $rawTipos=$_POST['campo_tipo'] ?? [];
            $rawRotulos=$_POST['campo_rotulo'] ?? [];
            $rawNomes=$_POST['campo_nome'] ?? [];
            $rawPlace=$_POST['campo_placeholder'] ?? [];
            $rawOpcoes=$_POST['campo_opcoes'] ?? [];
            $rawObrig=$_POST['campo_obrigatorio'] ?? [];
            $rawOrdem=$_POST['campo_ordem'] ?? [];
            $allowed=['texto','email','telefone','numero','data','textarea','select','checkbox'];
            $parsed=[]; $used=[];
            foreach ($rawRotulos as $k=>$rotuloRaw) {
                $rotulo=trim((string)$rotuloRaw);
                if ($rotulo==='') continue;
                $tipo=(string)($rawTipos[$k] ?? 'texto');
                if (!in_array($tipo,$allowed,true)) throw new RuntimeException('Tipo de campo inválido.');
                $nome=slugify(trim((string)($rawNomes[$k] ?? '')) ?: $rotulo);
                $nome=str_replace('-','_',$nome);
                if ($nome==='') $nome='campo_'.($k+1);
                $base=$nome; $n=2; while(isset($used[$nome])) $nome=$base.'_'.$n++;
                $used[$nome]=true;
                $opcoes=trim((string)($rawOpcoes[$k] ?? ''));
                if ($tipo==='select' && $opcoes==='') throw new RuntimeException('Informe as opções do campo "'.$rotulo.'".');
                $parsed[]=['tipo'=>$tipo,'rotulo'=>$rotulo,'nome'=>$nome,'placeholder'=>trim((string)($rawPlace[$k] ?? '')),'opcoes'=>$opcoes,'obrigatorio'=>(isset($rawObrig[$k]) && (string)$rawObrig[$k] === '1')?1:0,'ordem'=>(int)($rawOrdem[$k] ?? (($k+1)*10))];
            }
            if (!$parsed) throw new RuntimeException('Adicione pelo menos um campo ao formulário.');

            $pdo->beginTransaction();
            if ($id>0) {
                $stmt=$pdo->prepare('UPDATE formularios SET titulo=:titulo,slug=:slug,descricao=:descricao,mensagem_sucesso=:sucesso,status=:status,ativo=:ativo,publicado_em=:publicado WHERE id=:id');
                $stmt->execute(['titulo'=>$titulo,'slug'=>$slug,'descricao'=>$descricao?:null,'sucesso'=>$sucesso,'status'=>$status,'ativo'=>$ativo,'publicado'=>$publicadoEm,'id'=>$id]);
            } else {
                $stmt=$pdo->prepare('INSERT INTO formularios (autor_id,titulo,slug,descricao,mensagem_sucesso,status,ativo,publicado_em) VALUES (:autor,:titulo,:slug,:descricao,:sucesso,:status,:ativo,:publicado)');
                $stmt->execute(['autor'=>(int)Auth::id(),'titulo'=>$titulo,'slug'=>$slug,'descricao'=>$descricao?:null,'sucesso'=>$sucesso,'status'=>$status,'ativo'=>$ativo,'publicado'=>$publicadoEm]);
                $id=(int)$pdo->lastInsertId();
            }
            // Mantém respostas existentes: remove apenas campos que nunca receberam valor; campos usados são desativados.
            $existing=$pdo->prepare('SELECT c.id FROM formulario_campos c WHERE c.formulario_id=:id'); $existing->execute(['id'=>$id]);
            $pdo->prepare('UPDATE formulario_campos SET ativo=0 WHERE formulario_id=:id')->execute(['id'=>$id]);
            // Nesta versão reconstruímos campos por nome; se já existe, atualiza.
            $find=$pdo->prepare('SELECT id FROM formulario_campos WHERE formulario_id=:formulario AND nome=:nome LIMIT 1');
            $update=$pdo->prepare('UPDATE formulario_campos SET tipo=:tipo,rotulo=:rotulo,placeholder=:placeholder,opcoes=:opcoes,obrigatorio=:obrigatorio,ativo=1,ordem=:ordem WHERE id=:id');
            $insert=$pdo->prepare('INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,opcoes,obrigatorio,ativo,ordem) VALUES (:formulario,:tipo,:rotulo,:nome,:placeholder,:opcoes,:obrigatorio,1,:ordem)');
            foreach ($parsed as $campo) {
                $find->execute(['formulario'=>$id,'nome'=>$campo['nome']]); $campoId=(int)$find->fetchColumn();
                $params=['tipo'=>$campo['tipo'],'rotulo'=>$campo['rotulo'],'placeholder'=>$campo['placeholder']?:null,'opcoes'=>$campo['opcoes']?:null,'obrigatorio'=>$campo['obrigatorio'],'ordem'=>$campo['ordem']];
                if ($campoId>0) { $update->execute($params+['id'=>$campoId]); }
                else { $insert->execute($params+['formulario'=>$id,'nome'=>$campo['nome']]); }
            }
            $pdo->commit();
            logAction($pdo,'formulario.salvar','formularios',$id,$titulo.' · '.count($parsed).' campo(s)');
            Session::flash('success','Formulário salvo com sucesso.');
            header('Location: '.url('admin/formularios/index.php')); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error=$e->getMessage();
        }
    }

    $campos=[];
    foreach (($_POST['campo_rotulo'] ?? []) as $k=>$rotulo) {
        if (trim((string)$rotulo)==='') continue;
        $campos[]=['tipo'=>(string)(($_POST['campo_tipo'] ?? [])[$k] ?? 'texto'),'rotulo'=>(string)$rotulo,'nome'=>(string)(($_POST['campo_nome'] ?? [])[$k] ?? ''),'placeholder'=>(string)(($_POST['campo_placeholder'] ?? [])[$k] ?? ''),'opcoes'=>(string)(($_POST['campo_opcoes'] ?? [])[$k] ?? ''),'obrigatorio'=>(isset(($_POST['campo_obrigatorio'] ?? [])[$k]) && (string)(($_POST['campo_obrigatorio'] ?? [])[$k]) === '1')?1:0,'ordem'=>(int)(($_POST['campo_ordem'] ?? [])[$k] ?? 0)];
    }
}

$pageTitle=$id>0?'Editar formulário':'Novo formulário';
require __DIR__.'/../_header.php';
$types=['texto'=>'Texto','email'=>'E-mail','telefone'=>'Telefone','numero'=>'Número','data'=>'Data','textarea'=>'Texto longo','select'=>'Lista de opções','checkbox'=>'Caixa de seleção'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1"><?= $id>0?'Editar formulário':'Novo formulário' ?></h1><p class="text-secondary mb-0">Monte campos personalizados para páginas de contato, inscrições e outros cadastros.</p></div><?php if ($id>0): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/formularios/notificacoes.php?id='.$id)) ?>"><i class="bi bi-envelope me-1"></i>Notificações por e-mail</a><?php endif; ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/formularios/index.php')) ?>">Voltar</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" id="formBuilder"><?= Csrf::field() ?>
<div class="row g-4">
<div class="col-xl-8">
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
<div class="mb-3"><label class="form-label">Título</label><input class="form-control" name="titulo" value="<?= e((string)$form['titulo']) ?>" required maxlength="220"></div>
<div class="mb-3"><label class="form-label">Slug</label><input class="form-control" name="slug" value="<?= e((string)$form['slug']) ?>" placeholder="gerada automaticamente"><div class="form-text">URL: /formulario/slug-do-formulario</div></div>
<div class="mb-3"><label class="form-label">Descrição</label><textarea class="form-control" name="descricao" rows="4"><?= e((string)$form['descricao']) ?></textarea></div>
<div><label class="form-label">Mensagem após envio</label><textarea class="form-control" name="mensagem_sucesso" rows="2" maxlength="500"><?= e((string)$form['mensagem_sucesso']) ?></textarea></div>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Campos</span><button class="btn btn-sm btn-primary" type="button" id="addField">Adicionar campo</button></div><div class="card-body p-4"><div id="fieldsContainer"></div><div id="emptyFields" class="text-secondary small">Adicione ao menos um campo.</div></div></div>
</div>
<div class="col-xl-4"><div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Publicação</div><div class="card-body p-4">
<div class="mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="rascunho" <?= $form['status']==='rascunho'?'selected':'' ?>>Rascunho</option><option value="publicado" <?= $form['status']==='publicado'?'selected':'' ?>>Publicado</option><option value="arquivado" <?= $form['status']==='arquivado'?'selected':'' ?>>Arquivado</option></select></div>
<div class="mb-3"><label class="form-label">Publicar em</label><input class="form-control" type="datetime-local" name="publicado_em" value="<?= e(!empty($form['publicado_em']) ? date('Y-m-d\\TH:i',strtotime((string)$form['publicado_em'])) : '') ?>"></div>
<div class="form-check"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" <?= (int)$form['ativo']===1?'checked':'' ?>><label class="form-check-label" for="ativo">Formulário ativo</label></div>
</div></div><button class="btn btn-primary w-100 py-2">Salvar formulário</button></div>
</div></form>
<template id="fieldTemplate"><div class="form-field-builder border rounded p-3 mb-3"><div class="d-flex justify-content-between align-items-center mb-3"><strong>Campo</strong><button type="button" class="btn btn-sm btn-outline-danger remove-field">Remover</button></div><div class="row g-3"><div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select field-type" name="campo_tipo[]"><?php foreach($types as $v=>$l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label">Rótulo</label><input class="form-control" name="campo_rotulo[]" required></div><div class="col-md-3"><label class="form-label">Nome interno</label><input class="form-control" name="campo_nome[]" placeholder="automático"></div><div class="col-md-7"><label class="form-label">Placeholder</label><input class="form-control" name="campo_placeholder[]"></div><div class="col-md-2"><label class="form-label">Ordem</label><input class="form-control" type="number" name="campo_ordem[]" value="10"></div><div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input required-check" type="checkbox"><input type="hidden" class="required-hidden" name="campo_obrigatorio[]" value=""><label class="form-check-label">Obrigatório</label></div></div><div class="col-12 options-wrap d-none"><label class="form-label">Opções (uma por linha)</label><textarea class="form-control" name="campo_opcoes[]" rows="3"></textarea></div></div></div></template>
<script>
const container=document.getElementById('fieldsContainer'), empty=document.getElementById('emptyFields'), tpl=document.getElementById('fieldTemplate');
function refreshEmpty(){empty.style.display=container.children.length?'none':'block';}
function bindField(el){const type=el.querySelector('.field-type'), wrap=el.querySelector('.options-wrap'), check=el.querySelector('.required-check'), hidden=el.querySelector('.required-hidden'); const sync=()=>{wrap.classList.toggle('d-none',type.value!=='select');}; type.addEventListener('change',sync); sync(); check.addEventListener('change',()=>hidden.value=check.checked?'1':''); hidden.value=check.checked?'1':''; el.querySelector('.remove-field').addEventListener('click',()=>{el.remove();refreshEmpty();});}
function addField(data={}){const node=tpl.content.firstElementChild.cloneNode(true); node.querySelector('[name="campo_tipo[]"]').value=data.tipo||'texto'; node.querySelector('[name="campo_rotulo[]"]').value=data.rotulo||''; node.querySelector('[name="campo_nome[]"]').value=data.nome||''; node.querySelector('[name="campo_placeholder[]"]').value=data.placeholder||''; node.querySelector('[name="campo_ordem[]"]').value=data.ordem||((container.children.length+1)*10); node.querySelector('[name="campo_opcoes[]"]').value=data.opcoes||''; node.querySelector('.required-check').checked=!!Number(data.obrigatorio||0); bindField(node); container.appendChild(node); refreshEmpty();}
document.getElementById('addField').addEventListener('click',()=>addField());
<?php foreach ($campos as $campo): ?>addField(<?= json_encode(['tipo'=>$campo['tipo'],'rotulo'=>$campo['rotulo'],'nome'=>$campo['nome'],'placeholder'=>$campo['placeholder'],'opcoes'=>$campo['opcoes'],'obrigatorio'=>(int)$campo['obrigatorio'],'ordem'=>(int)$campo['ordem']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>);<?php endforeach; ?>
if(!container.children.length) addField();
document.getElementById('formBuilder').addEventListener('submit',()=>{container.querySelectorAll('.form-field-builder').forEach(el=>{const check=el.querySelector('.required-check'), hidden=el.querySelector('.required-hidden'); hidden.value=check.checked?'1':'';});});
</script>
<?php require __DIR__.'/../_footer.php'; ?>
