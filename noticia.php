<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$slug = trim((string)($_GET['slug'] ?? ''));
$stmt = $pdo->prepare("SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome, u.nome AS autor_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN usuarios u ON u.id=p.autor_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.slug=:slug AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) LIMIT 1");
$stmt->execute(['slug'=>$slug]);
$post=$stmt->fetch();
if (!$post) { http_response_code(404); $metaTitle='Notícia não encontrada'; require __DIR__.'/theme/ieclb/header.php'; echo '<div class="container py-5"><h1>Notícia não encontrada</h1></div>'; require __DIR__.'/theme/ieclb/footer.php'; exit; }
$pdo->prepare('UPDATE posts SET visualizacoes=visualizacoes+1 WHERE id=:id')->execute(['id'=>$post['id']]);
$metaTitle=$post['titulo'].' - IECLB Parobé';
$metaDescription=$post['resumo'] ?: strip_tags(mb_substr($post['conteudo'],0,150));
require __DIR__.'/theme/ieclb/header.php';
$cover = $post['imagem_capa_midia'] ?: $post['imagem_capa'];
?>
<article class="container py-5 content-reading"><div class="mb-4"><div class="text-secondary mb-2"><?= e($post['categoria_nome'] ?: 'Notícia') ?> · <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div><h1 class="display-5 fw-bold"><?= e($post['titulo']) ?></h1><div class="text-secondary">Publicado em <?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?><?php if ($post['autor_nome']): ?> · <?= e($post['autor_nome']) ?><?php endif; ?></div></div>
<?php if ($cover): ?><img class="article-cover mb-4" src="<?= e(mediaUrl($cover)) ?>" alt="<?= e($post['imagem_capa_alt'] ?: $post['titulo']) ?>"><?php endif; ?>
<?php if ($post['resumo']): ?><p class="lead"><?= e($post['resumo']) ?></p><?php endif; ?><div class="article-body"><?= $post['conteudo'] ?></div></article>
<?php require __DIR__.'/theme/ieclb/footer.php'; ?>
