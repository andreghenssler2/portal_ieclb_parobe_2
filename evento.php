<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');

$slug = routeSlug('evento');
$stmt = $pdo->prepare(
    "SELECT e.*, c.nome AS comunidade_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt
     FROM eventos e
     LEFT JOIN comunidades c ON c.id = e.comunidade_id
     LEFT JOIN midias m ON m.id = e.imagem_capa_id
     WHERE e.slug = :slug
       AND e.status = 'publicado'
     LIMIT 1"
);
$stmt->execute(['slug' => $slug]);
$evento = $stmt->fetch();

if (!$evento) {
    http_response_code(404);
    $metaTitle = 'Evento não encontrado - ' . $siteLabel;
    $metaDescription = 'O evento ou culto solicitado não está disponível.';
    require __DIR__ . '/theme/ieclb/header.php';
    echo '<div class="container py-5"><h1 class="h2">Evento ou culto não encontrado</h1><p class="text-secondary">O conteúdo solicitado não está disponível.</p><a class="btn btn-primary" href="' . e(url('agenda.php')) . '">Ver agenda</a></div>';
    require __DIR__ . '/theme/ieclb/footer.php';
    exit;
}

$metaTitle = $evento['titulo'] . ' - ' . $siteLabel;
$metaDescription = $evento['resumo'] ?: trim(strip_tags(mb_substr((string)($evento['descricao'] ?? ''), 0, 160)));
$metaImage = $evento['imagem_capa_midia'] ? mediaUrl((string)$evento['imagem_capa_midia']) : '';
$canonicalUrl = contentUrl('evento', (string)$evento['slug']);
$metaOgType = 'article';
require __DIR__ . '/theme/ieclb/header.php';
?>
<article class="container py-5 content-reading">
    <header class="mb-4">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge <?= $evento['tipo'] === 'culto' ? 'text-bg-primary' : 'text-bg-info' ?>"><?= e(eventTypeLabel($evento['tipo'])) ?></span>
            <?php if ((int)$evento['santa_ceia'] === 1): ?><span class="badge text-bg-light border">Com Santa Ceia</span><?php endif; ?>
        </div>
        <h1 class="display-5 fw-bold mb-3"><?= e($evento['titulo']) ?></h1>
        <?php if ($evento['resumo']): ?><p class="lead text-secondary"><?= e($evento['resumo']) ?></p><?php endif; ?>
    </header>

    <?php if ($evento['imagem_capa_midia']): ?>
        <img class="article-cover mb-4" src="<?= e(mediaUrl((string)$evento['imagem_capa_midia'])) ?>" alt="<?= e($evento['imagem_capa_alt'] ?: $evento['titulo']) ?>">
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="small text-secondary">Data e horário</div>
                    <div class="fw-semibold"><?= e(formatDateOnlyBr($evento['data_inicio'])) ?> às <?= e(formatTimeBr($evento['data_inicio'])) ?></div>
                    <?php if ($evento['data_fim']): ?><div class="small text-secondary">Término: <?= e(formatDateBr($evento['data_fim'])) ?></div><?php endif; ?>
                </div>
                <div class="col-sm-6">
                    <div class="small text-secondary">Comunidade</div>
                    <div class="fw-semibold"><?= e($evento['comunidade_nome'] ?: 'Paroquial / Todas') ?></div>
                </div>
                <?php if ($evento['local']): ?>
                    <div class="col-sm-6">
                        <div class="small text-secondary">Local</div>
                        <div class="fw-semibold"><?= e($evento['local']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($evento['endereco']): ?>
                    <div class="col-sm-6">
                        <div class="small text-secondary">Endereço</div>
                        <div><?= e($evento['endereco']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($evento['descricao']): ?><div class="article-body"><?= $evento['descricao'] ?></div><?php endif; ?>

    <div class="mt-4"><a class="btn btn-outline-primary" href="<?= e(url('agenda.php')) ?>">Voltar para a agenda</a></div>
</article>
<?php require __DIR__ . '/theme/ieclb/footer.php'; ?>
