<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');

$pdo =
    Database::connection();

NewsFeatureService::ensureSchema($pdo);

$id =
    max(
        0,
        (int)($_GET['id'] ?? 0)
    );

if ($id <= 0) {
    http_response_code(400);
    exit('Notícia inválida para pré-visualização.');
}

$stmt =
    $pdo->prepare(
        "SELECT
            p.*,
            c.nome AS comunidade_nome,
            u.nome AS autor_nome,
            m.caminho AS imagem_capa_midia,
            m.alt_text AS imagem_capa_alt
         FROM posts p
         LEFT JOIN comunidades c
            ON c.id=p.comunidade_id
         LEFT JOIN usuarios u
            ON u.id=p.autor_id
         LEFT JOIN midias m
            ON m.id=p.imagem_capa_id
         WHERE p.id=:id
         LIMIT 1"
    );

$stmt->execute([
    'id' => $id,
]);

$post =
    $stmt->fetch();

if (!$post) {
    http_response_code(404);
    exit('Notícia não encontrada.');
}

$metaTitle =
    'Pré-visualização — '
    . (string)$post['titulo'];

$metaDescription =
    'Pré-visualização administrativa';

$metaNoindex = true;

$articleContentPublic =
    TrustedEmbedService::normalize(
        (string)($post['conteudo'] ?? '')
    );

$cover =
    (string)(
        $post['imagem_capa_midia']
        ?? ''
    );

require themeFile(
    $pdo,
    'header.php'
);
?>

<article class="container py-5 content-reading">
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <strong>Pré-visualização administrativa.</strong>
            Esta tela permite conferir a notícia mesmo antes de ela estar publicada.
        </div>

        <a
            class="btn btn-sm btn-outline-dark"
            href="<?= e(url('admin/noticias/form.php?id=' . $id)) ?>"
        >
            Voltar ao editor
        </a>
    </div>

    <header class="mb-4">
        <div class="text-secondary mb-2">
            <?= e((string)($post['comunidade_nome'] ?: 'Paroquial')) ?>
        </div>

        <h1 class="display-5 fw-bold">
            <?= e((string)$post['titulo']) ?>
        </h1>

        <div class="text-secondary">
            Status:
            <strong><?= e((string)$post['status']) ?></strong>
            <?php if (!empty($post['publicado_em'])): ?>
                · <?= e(formatDateBr((string)$post['publicado_em'])) ?>
            <?php endif; ?>
        </div>
    </header>

    <?php if (
        $cover !== ''
        && (int)($post['exibir_imagem_capa'] ?? 1) === 1
    ): ?>
        <img
            class="article-cover mb-4"
            src="<?= e(mediaUrl($cover)) ?>"
            alt="<?= e(
                (string)(
                    $post['imagem_capa_alt']
                    ?: $post['titulo']
                )
            ) ?>"
        >
    <?php endif; ?>

    <?php if (trim($articleContentPublic) !== ''): ?>
        <div class="article-body">
            <?= $articleContentPublic ?>
        </div>
    <?php endif; ?>

    <?= ContentBlockService::render(
        $pdo,
        'post',
        $id
    ) ?>

    <?= NewsFeatureService::renderAssets(
        $pdo,
        $id
    ) ?>
</article>

<?php require themeFile($pdo, 'footer.php'); ?>
