<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo =
    Database::connection();

CommunityProfileService::ensureSchema(
    $pdo
);

$slug =
    trim(
        (string)($_GET['slug'] ?? '')
    );

$id =
    max(
        0,
        (int)($_GET['id'] ?? 0)
    );

if ($slug !== '') {
    $stmt =
        $pdo->prepare(
            'SELECT *
             FROM comunidades
             WHERE slug=:slug
               AND ativa=1
             LIMIT 1'
        );

    $stmt->execute([
        'slug' => $slug,
    ]);
} elseif ($id > 0) {
    $stmt =
        $pdo->prepare(
            'SELECT *
             FROM comunidades
             WHERE id=:id
               AND ativa=1
             LIMIT 1'
        );

    $stmt->execute([
        'id' => $id,
    ]);
} else {
    http_response_code(404);
    exit('Comunidade não encontrada.');
}

$community =
    $stmt->fetch();

if (!$community) {
    http_response_code(404);

    $metaTitle =
        'Comunidade não encontrada';

    require themeFile(
        $pdo,
        'header.php'
    );

    echo '<div class="container py-5"><h1>Comunidade não encontrada</h1></div>';

    require themeFile(
        $pdo,
        'footer.php'
    );

    exit;
}

$profile =
    CommunityProfileService::load(
        $pdo,
        (int)$community['id']
    );

$cover =
    CommunityProfileService::cover(
        $pdo,
        $profile
    );

$gallery =
    CommunityProfileService::gallery(
        $pdo,
        (int)$community['id']
    );

$events =
    CommunityProfileService::upcomingEvents(
        $pdo,
        (int)$community['id'],
        6
    );

$mapEmbedUrl =
    CommunityProfileService::mapEmbedUrl(
        $community
    );

$mapExternalUrl =
    CommunityProfileService::mapExternalUrl(
        $community
    );

$whatsappUrl =
    CommunityProfileService::whatsappUrl(
        (string)($profile['whatsapp'] ?? '')
    );

$socialLinks =
    CommunityProfileService::socialLinks(
        $profile
    );

$metaTitle =
    (string)$community['nome']
    . ' - Comunidades';

$metaDescription =
    trim(
        (string)($community['descricao'] ?? '')
    )
    ?: (
        'Informações, cultos e contatos da '
        . (string)$community['nome']
        . '.'
    );

if ($cover) {
    $metaImage =
        mediaUrl(
            (string)$cover['caminho']
        );

    $metaImageAlt =
        trim(
            (string)($cover['alt_text'] ?? '')
        )
        ?: (string)$community['nome'];
}

require themeFile(
    $pdo,
    'header.php'
);
?>

<article>
    <?php if ($cover): ?>
        <div class="position-relative bg-dark">
            <img
                src="<?= e(mediaUrl((string)$cover['caminho'])) ?>"
                alt="<?= e(
                    (string)(
                        $cover['alt_text']
                        ?: $community['nome']
                    )
                ) ?>"
                class="w-100"
                style="height:min(58vh,560px);object-fit:cover;opacity:.72"
            >

            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-end">
                <div class="container pb-5 text-white">
                    <div class="small text-uppercase opacity-75 mb-2">
                        Comunidade
                    </div>

                    <h1 class="display-4 fw-bold mb-2">
                        <?= e((string)$community['nome']) ?>
                    </h1>

                    <?php
                    $heroLocation =
                        CommunityProfileService::mapQuery(
                            $community
                        );
                    ?>

                    <?php if ($heroLocation !== ''): ?>
                        <div class="fs-5">
                            <i class="bi bi-geo-alt me-1"></i>
                            <?= e($heroLocation) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <header class="container py-5">
            <div class="small text-uppercase text-secondary mb-2">
                Comunidade
            </div>

            <h1 class="display-4 fw-bold">
                <?= e((string)$community['nome']) ?>
            </h1>

            <?php
            $heroLocation =
                CommunityProfileService::mapQuery(
                    $community
                );
            ?>

            <?php if ($heroLocation !== ''): ?>
                <p class="lead text-secondary mb-0">
                    <i class="bi bi-geo-alt me-1"></i>
                    <?= e($heroLocation) ?>
                </p>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-8">
                <?php if (!empty($community['descricao'])): ?>
                    <section class="mb-5">
                        <h2 class="h3 mb-3">
                            Sobre a comunidade
                        </h2>

                        <div class="lead">
                            <?= nl2br(
                                e(
                                    (string)$community['descricao']
                                )
                            ) ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($profile['horarios_cultos'])): ?>
                    <section class="mb-5">
                        <h2 class="h3 mb-3">
                            <i class="bi bi-clock me-2"></i>
                            Horários de cultos
                        </h2>

                        <div class="card border-0 bg-body-tertiary">
                            <div class="card-body p-4">
                                <?= nl2br(
                                    e(
                                        (string)$profile['horarios_cultos']
                                    )
                                ) ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($gallery): ?>
                    <section class="mb-5">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <h2 class="h3 mb-0">
                                Fotos
                            </h2>

                            <span class="text-secondary small">
                                <?= count($gallery) ?> foto(s)
                            </span>
                        </div>

                        <div class="row g-3">
                            <?php foreach ($gallery as $image): ?>
                                <?php
                                $alt =
                                    trim(
                                        (string)($image['alt_text'] ?? '')
                                    )
                                    ?: trim(
                                        (string)($image['titulo'] ?? '')
                                    )
                                    ?: (string)$community['nome'];
                                ?>

                                <div class="col-6 col-md-4">
                                    <a
                                        href="<?= e(mediaUrl((string)$image['caminho'])) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        class="d-block"
                                    >
                                        <img
                                            src="<?= e(mediaUrl((string)$image['caminho'])) ?>"
                                            alt="<?= e($alt) ?>"
                                            loading="lazy"
                                            class="img-fluid rounded shadow-sm w-100"
                                            style="aspect-ratio:4/3;object-fit:cover"
                                        >
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                        <h2 class="h3 mb-0">
                            Próximos eventos
                        </h2>

                        <a
                            class="btn btn-sm btn-outline-primary"
                            href="<?= e(
                                url(
                                    'agenda.php?comunidade='
                                    . (int)$community['id']
                                )
                            ) ?>"
                        >
                            Ver agenda
                        </a>
                    </div>

                    <?php if ($events): ?>
                        <div class="row g-3">
                            <?php foreach ($events as $event): ?>
                                <div class="col-md-6">
                                    <article class="card h-100 border-0 shadow-sm">
                                        <div class="card-body p-4">
                                            <div class="small text-secondary mb-2">
                                                <?= e(
                                                    formatDateBr(
                                                        (string)$event['data_inicio']
                                                    )
                                                ) ?>
                                                ·
                                                <?= e(
                                                    eventTypeLabel(
                                                        (string)$event['tipo']
                                                    )
                                                ) ?>
                                            </div>

                                            <h3 class="h5">
                                                <a
                                                    class="text-reset text-decoration-none stretched-link"
                                                    href="<?= e(
                                                        contentUrl(
                                                            'evento',
                                                            (string)$event['slug']
                                                        )
                                                    ) ?>"
                                                >
                                                    <?= e((string)$event['titulo']) ?>
                                                </a>
                                            </h3>

                                            <?php if (!empty($event['local'])): ?>
                                                <div class="small text-secondary">
                                                    <i class="bi bi-geo-alt me-1"></i>
                                                    <?= e((string)$event['local']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border">
                            Nenhum evento futuro publicado para esta comunidade.
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="col-lg-4">
                <?php if (
                    !empty($profile['pastor_nome'])
                    || !empty($profile['telefone'])
                    || !empty($profile['whatsapp'])
                    || !empty($profile['email'])
                    || $socialLinks
                ): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-3">
                                Contato
                            </h2>

                            <?php if (!empty($profile['pastor_nome'])): ?>
                                <div class="mb-3">
                                    <div class="small text-secondary">
                                        Pastor(a) / responsável
                                    </div>

                                    <div class="fw-semibold">
                                        <?= e((string)$profile['pastor_nome']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <?php if (!empty($profile['telefone'])): ?>
                                    <a
                                        class="btn btn-outline-secondary text-start"
                                        href="tel:<?= e(
                                            preg_replace(
                                                '/[^0-9+]/',
                                                '',
                                                (string)$profile['telefone']
                                            )
                                            ?: ''
                                        ) ?>"
                                    >
                                        <i class="bi bi-telephone me-2"></i>
                                        <?= e((string)$profile['telefone']) ?>
                                    </a>
                                <?php endif; ?>

                                <?php if ($whatsappUrl !== ''): ?>
                                    <a
                                        class="btn btn-outline-success text-start"
                                        target="_blank"
                                        rel="noopener"
                                        href="<?= e($whatsappUrl) ?>"
                                    >
                                        <i class="bi bi-whatsapp me-2"></i>
                                        WhatsApp
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($profile['email'])): ?>
                                    <a
                                        class="btn btn-outline-secondary text-start"
                                        href="mailto:<?= e((string)$profile['email']) ?>"
                                    >
                                        <i class="bi bi-envelope me-2"></i>
                                        <?= e((string)$profile['email']) ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($socialLinks): ?>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <?php foreach ($socialLinks as $social): ?>
                                        <a
                                            class="btn btn-sm btn-outline-primary"
                                            target="_blank"
                                            rel="noopener"
                                            href="<?= e((string)$social['url']) ?>"
                                        >
                                            <i class="bi <?= e((string)$social['icon']) ?> me-1"></i>
                                            <?= e((string)$social['label']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($mapEmbedUrl !== ''): ?>
                    <div class="card border-0 shadow-sm overflow-hidden">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-3">
                                Localização
                            </h2>

                            <div class="small text-secondary mb-3">
                                <?= e(
                                    CommunityProfileService::mapQuery(
                                        $community
                                    )
                                ) ?>
                            </div>

                            <div class="ratio ratio-4x3 rounded overflow-hidden border">
                                <iframe
                                    src="<?= e($mapEmbedUrl) ?>"
                                    title="Mapa - <?= e((string)$community['nome']) ?>"
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"
                                ></iframe>
                            </div>

                            <?php if ($mapExternalUrl !== ''): ?>
                                <a
                                    class="btn btn-outline-primary w-100 mt-3"
                                    target="_blank"
                                    rel="noopener"
                                    href="<?= e($mapExternalUrl) ?>"
                                >
                                    <i class="bi bi-map me-1"></i>
                                    Abrir no Google Maps
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</article>

<?php require themeFile($pdo, 'footer.php'); ?>
