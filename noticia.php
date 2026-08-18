<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$slug = routeSlug('noticia');
$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');

$stmt = $pdo->prepare("SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome, u.nome AS autor_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN usuarios u ON u.id=p.autor_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.slug=:slug AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) LIMIT 1");
$stmt->execute(['slug' => $slug]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    $metaTitle = 'Notícia não encontrada - ' . $siteLabel;
    $metaDescription = 'A notícia solicitada não está disponível.';
    $metaNoindex = true;
    require themeFile($pdo, 'header.php');
    echo '<div class="container py-5"><h1>Notícia não encontrada</h1><p class="text-secondary">O conteúdo solicitado não está disponível.</p></div>';
    require themeFile($pdo, 'footer.php');
    exit;
}

redirectCanonicalContent('noticia', (string)$post['slug']);
$pdo->prepare('UPDATE posts SET visualizacoes=visualizacoes+1 WHERE id=:id')->execute(['id' => $post['id']]);
$cover = $post['imagem_capa_midia'] ?: $post['imagem_capa'];
$metaTitle = trim((string)($post['seo_titulo'] ?? '')) ?: $post['titulo'];
$metaDescription = trim((string)($post['seo_descricao'] ?? '')) ?: ($post['resumo'] ?: trim(strip_tags(mb_substr((string)$post['conteudo'], 0, 160))));
$metaNoindex = (int)($post['seo_noindex'] ?? 0) === 1;
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$canonicalUrl = contentUrl('noticia', (string)$post['slug']);
$metaOgType = 'article';
require themeFile($pdo, 'header.php');
?>
<article class="container py-5 content-reading"><div class="mb-4"><div class="text-secondary mb-2"><?= e($post['categoria_nome'] ?: 'Notícia') ?> · <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div><h1 class="display-5 fw-bold"><?= e($post['titulo']) ?></h1><div class="text-secondary">Publicado em <?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?><?php if ($post['autor_nome']): ?> · <?= e($post['autor_nome']) ?><?php endif; ?></div></div>
<?php if ($cover): ?><img class="article-cover mb-4" src="<?= e(mediaUrl((string)$cover)) ?>" alt="<?= e($post['imagem_capa_alt'] ?: $post['titulo']) ?>"><?php endif; ?>
<?php if ($post['resumo']): ?><p class="lead"><?= e($post['resumo']) ?></p><?php endif; ?><div class="article-body"><?= $post['conteudo'] ?></div></article>
<?php require themeFile($pdo, 'footer.php'); ?>
