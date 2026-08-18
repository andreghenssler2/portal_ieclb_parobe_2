<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');

$slug = routeSlug('pagina');
$stmt = $pdo->prepare(
    "SELECT p.*, u.nome AS autor_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     LEFT JOIN midias m ON m.id = p.imagem_capa_id
     WHERE p.slug = :slug
       AND p.status = 'publicado'
       AND (p.publicado_em IS NULL OR p.publicado_em <= NOW())
     LIMIT 1"
);
$stmt->execute(['slug' => $slug]);
$pagina = $stmt->fetch();

if (!$pagina) {
    http_response_code(404);
    $metaTitle = 'Página não encontrada - ' . $siteLabel;
    $metaDescription = 'O conteúdo solicitado não está disponível.';
    require __DIR__ . '/theme/ieclb/header.php';
    echo '<div class="container py-5"><h1 class="h2">Página não encontrada</h1><p class="text-secondary">O conteúdo solicitado não está disponível.</p><a class="btn btn-primary" href="' . e(url()) . '">Voltar ao início</a></div>';
    require __DIR__ . '/theme/ieclb/footer.php';
    exit;
}

$cover = $pagina['imagem_capa_midia'] ?? null;
$metaTitle = $pagina['titulo'] . ' - ' . $siteLabel;
$metaDescription = $pagina['resumo'] ?: trim(strip_tags(mb_substr((string)$pagina['conteudo'], 0, 160)));
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$canonicalUrl = contentUrl('pagina', (string)$pagina['slug']);
require __DIR__ . '/theme/ieclb/header.php';
?>
<article class="container py-5 content-reading">
    <header class="mb-4">
        <h1 class="display-5 fw-bold mb-3"><?= e($pagina['titulo']) ?></h1>
        <?php if ($pagina['resumo']): ?><p class="lead text-secondary"><?= e($pagina['resumo']) ?></p><?php endif; ?>
    </header>

    <?php if ($cover): ?>
        <img class="article-cover mb-4" src="<?= e(mediaUrl((string)$cover)) ?>" alt="<?= e($pagina['imagem_capa_alt'] ?: $pagina['titulo']) ?>">
    <?php endif; ?>

    <div class="article-body"><?= $pagina['conteudo'] ?></div>
</article>
<?php require __DIR__ . '/theme/ieclb/footer.php'; ?>
