<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('midias.gerenciar');
$pdo = Database::connection();
$id = (int)($_GET['id'] ?? 0);
$media = MediaService::find($pdo, $id);
if (!$media) { http_response_code(404); exit('Mídia não encontrada.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) $error = 'Token de segurança inválido.';
    else {
        $titulo = trim((string)($_POST['titulo'] ?? '')) ?: null;
        $alt = trim((string)($_POST['alt_text'] ?? '')) ?: null;
        $pdo->prepare('UPDATE midias SET titulo=:titulo, alt_text=:alt_text WHERE id=:id')->execute(['titulo'=>$titulo,'alt_text'=>$alt,'id'=>$id]);
        logAction($pdo, 'midia.editar', 'midias', $id);
        Session::flash('success','Dados da mídia atualizados.');
        header('Location: '.url('admin/midias/editar.php?id='.$id)); exit;
    }
}
$media = MediaService::find($pdo, $id);
$pageTitle='Detalhes da mídia'; require __DIR__.'/../_header.php';
?>
<h1 class="h3 mb-4">Detalhes da mídia</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4"><div class="col-lg-5">
<div class="card border-0 shadow-sm"><div class="card-body text-center p-4">
<?php if (MediaService::isImage($media)): ?><img class="img-fluid rounded media-preview" src="<?= e(mediaUrl($media['caminho'])) ?>" alt="<?= e($media['alt_text'] ?: $media['titulo'] ?: '') ?>"><?php else: ?><div class="media-file-placeholder rounded"><strong>.<?= e(strtoupper($media['extensao'])) ?></strong></div><?php endif; ?>
<div class="small text-secondary mt-3 text-start"><div><strong>Arquivo:</strong> <?= e($media['nome_original']) ?></div><div><strong>Tipo:</strong> <?= e($media['mime_type']) ?></div><div><strong>Tamanho:</strong> <?= e(formatBytes((int)$media['tamanho'])) ?></div><?php if ($media['largura'] && $media['altura']): ?><div><strong>Dimensões:</strong> <?= (int)$media['largura'] ?> × <?= (int)$media['altura'] ?> px</div><?php endif; ?></div>
</div></div></div>
<div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post"><?= Csrf::field() ?><div class="mb-3"><label class="form-label">Título</label><input class="form-control" name="titulo" value="<?= e($media['titulo'] ?? '') ?>"></div><div class="mb-3"><label class="form-label">Texto alternativo</label><input class="form-control" name="alt_text" value="<?= e($media['alt_text'] ?? '') ?>"><div class="form-text">Descreva a imagem para acessibilidade e SEO.</div></div><div class="mb-3"><label class="form-label">URL</label><div class="input-group"><input id="mediaUrl" class="form-control" readonly value="<?= e(mediaUrl($media['caminho'])) ?>"><button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('mediaUrl').value)">Copiar</button></div></div><button class="btn btn-primary">Salvar</button> <a class="btn btn-outline-secondary" href="<?= e(url('admin/midias/index.php')) ?>">Voltar</a></form><hr><form method="post" action="<?= e(url('admin/midias/index.php')) ?>" onsubmit="return confirm('Excluir permanentemente esta mídia?')"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$id ?>"><button class="btn btn-outline-danger">Excluir permanentemente</button></form></div></div></div></div>
<?php require __DIR__.'/../_footer.php'; ?>
