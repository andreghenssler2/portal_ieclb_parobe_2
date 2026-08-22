<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
$error = '';
$editId = (int)($_GET['editar'] ?? 0);
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $stmt = $pdo->prepare('SELECT nome FROM tags WHERE id=:id LIMIT 1');
                $stmt->execute(['id'=>$id]);
                $tag = $stmt->fetch();
                if (!$tag) throw new RuntimeException('Tag não encontrada.');
                $pdo->prepare('DELETE FROM tags WHERE id=:id')->execute(['id'=>$id]);
                logAction($pdo, 'tag.excluir', 'tags', $id, (string)$tag['nome']);
                Session::flash('success', 'Tag excluída.');
                header('Location: ' . url('admin/tags/index.php')); exit;
            } catch (Throwable $e) { $error = 'Não foi possível excluir a tag: ' . $e->getMessage(); }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $slugInput = trim((string)($_POST['slug'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            if ($nome === '') {
                $error = 'Informe o nome da tag.';
            } else {
                try {
                    $slug = uniqueSlug($pdo, 'tags', $slugInput !== '' ? $slugInput : $nome, $id > 0 ? $id : null);
                    if ($id > 0) {
                        $stmt=$pdo->prepare('UPDATE tags SET nome=:nome,slug=:slug,descricao=:descricao WHERE id=:id');
                        $stmt->execute(['nome'=>$nome,'slug'=>$slug,'descricao'=>$descricao ?: null,'id'=>$id]);
                        logAction($pdo,'tag.editar','tags',$id,$nome);
                        Session::flash('success','Tag atualizada.');
                    } else {
                        $stmt=$pdo->prepare('INSERT INTO tags (nome,slug,descricao) VALUES (:nome,:slug,:descricao)');
                        $stmt->execute(['nome'=>$nome,'slug'=>$slug,'descricao'=>$descricao ?: null]);
                        $id=(int)$pdo->lastInsertId();
                        logAction($pdo,'tag.criar','tags',$id,$nome);
                        Session::flash('success','Tag criada.');
                    }
                    header('Location: ' . url('admin/tags/index.php')); exit;
                } catch (Throwable $e) { $error='Não foi possível salvar a tag: '.$e->getMessage(); }
            }
        }
    }
}

if ($editId > 0) {
    $stmt=$pdo->prepare('SELECT id,nome,slug,descricao FROM tags WHERE id=:id LIMIT 1');
    $stmt->execute(['id'=>$editId]);
    $edit=$stmt->fetch() ?: null;
    if (!$edit && $error==='') $error='Tag não encontrada.';
}
$tags=$pdo->query("SELECT t.id,t.nome,t.slug,t.descricao,COUNT(p.id) total_posts FROM tags t LEFT JOIN post_tags pt ON pt.tag_id=t.id LEFT JOIN posts p ON p.id=pt.post_id AND p.status <> 'lixeira' GROUP BY t.id,t.nome,t.slug,t.descricao ORDER BY t.nome")->fetchAll();
$pageTitle='Tags de Posts';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h3 mb-1">Tags de Posts</h1><p class="text-secondary mb-0">Crie palavras-chave para relacionar notícias de assuntos semelhantes.</p></div><?php if($edit): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/tags/index.php')) ?>">Cancelar edição</a><?php endif; ?></div>
<?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4"><div class="col-xl-4"><form method="post" class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold py-3"><?= $edit?'Editar tag':'Adicionar tag' ?></div><div class="card-body p-4">
<?= Csrf::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
<div class="mb-3"><label class="form-label">Nome</label><input class="form-control" name="nome" maxlength="100" required value="<?= e($edit['nome']??'') ?>"></div>
<div class="mb-3"><label class="form-label">Slug</label><input class="form-control" name="slug" maxlength="120" placeholder="gerada-automaticamente" value="<?= e($edit['slug']??'') ?>"><div class="form-text">Se vazio, será gerada pelo nome.</div></div>
<div class="mb-3"><label class="form-label">Descrição</label><textarea class="form-control" name="descricao" rows="4" maxlength="255"><?= e($edit['descricao']??'') ?></textarea></div>
<button class="btn btn-primary w-100"><?= $edit?'Salvar alterações':'Adicionar tag' ?></button></div></form></div>
<div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nome</th><th>Slug</th><th class="text-center">Posts</th><th></th></tr></thead><tbody>
<?php if(!$tags): ?><tr><td colspan="4" class="text-secondary">Nenhuma tag cadastrada.</td></tr><?php endif; ?>
<?php foreach($tags as $tag): ?><tr><td><div class="fw-semibold"><?= e($tag['nome']) ?></div><?php if($tag['descricao']): ?><div class="small text-secondary"><?= e($tag['descricao']) ?></div><?php endif; ?></td><td><code><?= e($tag['slug']) ?></code></td><td class="text-center"><?= (int)$tag['total_posts'] ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(tagUrl((string)$tag['slug'])) ?>">Ver</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/tags/index.php?editar='.(int)$tag['id'])) ?>">Editar</a> <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta tag?');"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$tag['id'] ?>"><button class="btn btn-sm btn-outline-danger">Excluir</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div></div></div>
<?php require __DIR__ . '/../_footer.php'; ?>
