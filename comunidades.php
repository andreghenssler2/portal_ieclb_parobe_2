<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo =
    Database::connection();

CommunityProfileService::ensureSchema(
    $pdo
);

$comunidades =
    $pdo->query(
        "SELECT
            c.*,
            cp.pastor_nome,
            cp.horarios_cultos,
            cp.foto_capa_id,
            m.caminho AS foto_capa_caminho,
            m.alt_text AS foto_capa_alt
         FROM comunidades c
         LEFT JOIN comunidade_perfis cp
            ON cp.comunidade_id=c.id
         LEFT JOIN midias m
            ON m.id=cp.foto_capa_id
         WHERE c.ativa=1
         ORDER BY c.ordem,c.nome"
    )->fetchAll();

$siteLabel =
    siteConfig(
        $pdo,
        'seo_titulo',
        'IECLB Parobé'
    );

$metaTitle =
    'Comunidades - '
    . $siteLabel;

$metaDescription =
    'Conheça as comunidades da Paróquia Evangélica de Confissão Luterana de Parobé.';

require themeFile(
    $pdo,
    'header.php'
);
?>

<section class="container py-5">
    <div class="mb-5">
        <h1 class="display-6 fw-bold mb-2">
            Comunidades
        </h1>

        <p class="lead text-secondary mb-0">
            Conheça nossas comunidades, horários de cultos, contatos e próximos eventos.
        </p>
    </div>

    <div class="row g-4">
        <?php foreach ($comunidades as $community): ?>
            <?php
            $communityUrl =
                url(
                    'comunidade.php?slug='
                    . rawurlencode(
                        (string)$community['slug']
                    )
                );
            ?>

            <div class="col-md-6 col-xl-4">
                <article class="card h-100 border-0 shadow-sm overflow-hidden">
                    <?php if (!empty($community['foto_capa_caminho'])): ?>
                        <a href="<?= e($communityUrl) ?>">
                            <img
                                src="<?= e(
                                    mediaUrl(
                                        (string)$community['foto_capa_caminho']
                                    )
                                ) ?>"
                                alt="<?= e(
                                    (string)(
                                        $community['foto_capa_alt']
                                        ?: $community['nome']
                                    )
                                ) ?>"
                                class="card-img-top"
                                loading="lazy"
                                style="height:230px;object-fit:cover"
                            >
                        </a>
                    <?php endif; ?>

                    <div class="card-body p-4 d-flex flex-column">
                        <h2 class="h4">
                            <a
                                class="text-reset text-decoration-none"
                                href="<?= e($communityUrl) ?>"
                            >
                                <?= e((string)$community['nome']) ?>
                            </a>
                        </h2>

                        <?php
                        $location =
                            trim(
                                (string)($community['cidade'] ?? '')
                                . '/'
                                . (string)($community['uf'] ?? ''),
                                '/'
                            );
                        ?>

                        <?php if ($location !== ''): ?>
                            <div class="text-secondary small mb-3">
                                <i class="bi bi-geo-alt me-1"></i>
                                <?= e($location) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($community['descricao'])): ?>
                            <p class="text-secondary">
                                <?= e(
                                    portalExcerpt(
                                        (string)$community['descricao'],
                                        180
                                    )
                                ) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($community['pastor_nome'])): ?>
                            <div class="small mb-2">
                                <strong>Responsável:</strong>
                                <?= e((string)$community['pastor_nome']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($community['horarios_cultos'])): ?>
                            <div class="small text-secondary mb-3">
                                <i class="bi bi-clock me-1"></i>
                                <?= e(
                                    portalExcerpt(
                                        (string)$community['horarios_cultos'],
                                        100
                                    )
                                ) ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-auto pt-2">
                            <a
                                class="btn btn-outline-primary"
                                href="<?= e($communityUrl) ?>"
                            >
                                Conhecer comunidade
                            </a>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>

        <?php if (!$comunidades): ?>
            <div class="col-12">
                <div class="alert alert-light border">
                    Nenhuma comunidade ativa cadastrada.
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require themeFile($pdo, 'footer.php'); ?>
