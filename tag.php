<?php
require_once __DIR__ . '/bootstrap.php';
$pdo=Database::connection();
$slug=routeTagSlug();
$stmt=$pdo->prepare('SELECT id,nome,slug,descricao FROM tags WHERE slug=:slug LIMIT 1');
$stmt->execute(['slug'=>$slug]);
$tag=$stmt->fetch();
if(!$tag){ http_response_code(404); $metaTitle='Tag não encontrada'; $metaNoindex=true; require themeFile($pdo,'header.php'); echo '<div class="container py-5"><h1>Tag não encontrada</h1></div>'; require themeFile($pdo,'footer.php'); exit; }
$canonicalUrl=tagUrl((string)$tag['slug']);
$metaTitle='Tag: '.$tag['nome'];
$metaDescription=$tag['descricao'] ?: 'Notícias marcadas com '.$tag['nome'].'.';
$alternateFeedUrl = siteConfig($pdo,'seo_feed_ativo','1')==='1' && siteConfig($pdo,'seo_feed_tags','1')==='1' ? rssFeedUrl('tag',(string)$tag['slug']) : '';
$alternateFeedTitle = 'Tag '.$tag['nome'].' - RSS';
$sql="SELECT p.id,p.titulo,p.slug,p.resumo,p.conteudo,p.publicado_em,p.created_at,m.caminho AS imagem FROM posts p INNER JOIN post_tags pt ON pt.post_id=p.id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE pt.tag_id=:tag_id AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0 ORDER BY COALESCE(p.publicado_em,p.created_at) DESC";
$ps=$pdo->prepare($sql); $ps->execute(['tag_id'=>$tag['id']]); $posts=$ps->fetchAll();
require themeFile($pdo,'header.php');
?>
<section class="container py-5"><div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><div class="text-secondary text-uppercase small fw-semibold">Tag</div><h1 class="display-6 fw-bold">#<?= e($tag['nome']) ?></h1><?php if($tag['descricao']): ?><p class="lead text-secondary"><?= e($tag['descricao']) ?></p><?php endif; ?></div><?php if($alternateFeedUrl):?><a class="btn btn-outline-secondary" href="<?=e($alternateFeedUrl)?>">RSS da tag</a><?php endif;?></div>
<div class="row g-4"><?php if(!$posts): ?><div class="col-12"><div class="alert alert-light border">Nenhuma notícia publicada com esta tag.</div></div><?php endif; ?><?php foreach($posts as $p): ?><div class="col-md-6 col-xl-4"><article class="card h-100 border-0 shadow-sm overflow-hidden"><?php if($p['imagem']): ?><img class="news-card-image" src="<?= e(mediaUrl((string)$p['imagem'])) ?>" alt="<?= e($p['titulo']) ?>"><?php endif; ?><div class="card-body"><div class="small text-secondary mb-2"><?= e(formatDateBr($p['publicado_em'] ?: $p['created_at'])) ?></div><h2 class="h5"><a class="stretched-link text-decoration-none text-reset" href="<?= e(contentUrl('noticia',(string)$p['slug'])) ?>"><?= e($p['titulo']) ?></a></h2><p class="text-secondary mb-0"><?= e($p['resumo'] ?: portalExcerpt($p['conteudo'],150)) ?></p></div></article></div><?php endforeach; ?></div></section>
<?php require themeFile($pdo,'footer.php'); ?>
