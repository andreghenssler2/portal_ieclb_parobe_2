<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();

CategoryService::ensureSchema($pdo);

$slug = routeCategorySlug();

$stmt = $pdo->prepare(
    'SELECT id, nome, slug, descricao
     FROM categorias
     WHERE slug = :slug
     LIMIT 1'
);

$stmt->execute([
    'slug' => $slug,
]);

$categoria = $stmt->fetch();

if (!$categoria) {
    http_response_code(404);

    $metaTitle = 'Categoria não encontrada';
    $metaNoindex = true;

    require themeFile($pdo, 'header.php');

    echo '<div class="container py-5"><h1>Categoria não encontrada</h1></div>';

    require themeFile($pdo, 'footer.php');

    exit;
}

/*
|--------------------------------------------------------------------------
| Paginação
|--------------------------------------------------------------------------
|
| Cada categoria exibe 20 notícias por página.
|
| Página 1:
| /categoria/minha-categoria
|
| Página 2:
| /categoria/minha-categoria?pagina=2
|
*/

$perPage = 20;

$pageRaw = $_GET['pagina'] ?? '1';

if (
    is_array($pageRaw)
    || !preg_match('/^\d+$/', (string)$pageRaw)
) {
    $page = 1;
} else {
    $page = max(1, (int)$pageRaw);
}

/*
 * Evita URL duplicada:
 * ?pagina=1 redireciona para a URL normal da categoria.
 */
if (
    $page === 1
    && isset($_GET['pagina'])
    && PHP_SAPI !== 'cli'
    && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
) {
    header(
        'Location: ' . categoryUrl((string)$categoria['slug']),
        true,
        301
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Total de notícias publicadas da categoria
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(DISTINCT p.id)
    FROM posts p
    INNER JOIN post_categorias pc
        ON pc.post_id = p.id
    WHERE pc.categoria_id = :categoria_id
      AND p.status = 'publicado'
      AND (
            p.publicado_em IS NULL
            OR p.publicado_em <= NOW()
          )
      AND p.seo_noindex = 0
";

$countStmt = $pdo->prepare($countSql);

$countStmt->execute([
    'categoria_id' => (int)$categoria['id'],
]);

$totalPosts = (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil($totalPosts / $perPage)
);

/*
 * Página maior que a última existente.
 */
if ($page > $totalPages && $totalPosts > 0) {
    http_response_code(404);

    $metaTitle = 'Página da categoria não encontrada';
    $metaDescription = 'A página solicitada não existe.';
    $metaNoindex = true;

    require themeFile($pdo, 'header.php');

    echo '<div class="container py-5">';
    echo '<h1 class="h2">Página não encontrada</h1>';
    echo '<p class="text-secondary">Esta página da categoria não existe.</p>';
    echo '<a class="btn btn-primary" href="' . e(categoryUrl((string)$categoria['slug'])) . '">';
    echo 'Voltar para a categoria';
    echo '</a>';
    echo '</div>';

    require themeFile($pdo, 'footer.php');

    exit;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Notícias da página atual
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT DISTINCT
        p.id,
        p.titulo,
        p.slug,
        p.resumo,
        p.conteudo,
        p.publicado_em,
        p.created_at,
        m.caminho AS imagem
    FROM posts p
    INNER JOIN post_categorias pc
        ON pc.post_id = p.id
    LEFT JOIN midias m
        ON m.id = p.imagem_capa_id
    WHERE pc.categoria_id = :categoria_id
      AND p.status = 'publicado'
      AND (
            p.publicado_em IS NULL
            OR p.publicado_em <= NOW()
          )
      AND p.seo_noindex = 0
    ORDER BY COALESCE(p.publicado_em, p.created_at) DESC
    LIMIT :limit
    OFFSET :offset
";

$ps = $pdo->prepare($sql);

$ps->bindValue(
    ':categoria_id',
    (int)$categoria['id'],
    PDO::PARAM_INT
);

$ps->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$ps->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$ps->execute();

$posts = $ps->fetchAll();

/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
*/

$canonicalUrl = categoryUrl(
    (string)$categoria['slug']
);

if ($page > 1) {
    $canonicalUrl .= '?pagina=' . $page;
}

$metaTitle =
    'Categoria: '
    . $categoria['nome']
    . ($page > 1 ? ' - Página ' . $page : '');

$metaDescription =
    $categoria['descricao']
    ?: 'Notícias da categoria ' . $categoria['nome'] . '.';

$alternateFeedUrl =
    siteConfig($pdo, 'seo_feed_ativo', '1') === '1'
    && siteConfig($pdo, 'seo_feed_categorias', '1') === '1'
        ? rssFeedUrl(
            'categoria',
            (string)$categoria['slug']
        )
        : '';

$alternateFeedTitle =
    'Categoria '
    . $categoria['nome']
    . ' - RSS';

/*
|--------------------------------------------------------------------------
| Helper de URL da paginação
|--------------------------------------------------------------------------
*/

$categoryPageUrl = static function (
    int $targetPage
) use ($categoria): string {

    $base = categoryUrl(
        (string)$categoria['slug']
    );

    if ($targetPage <= 1) {
        return $base;
    }

    return $base
        . '?pagina='
        . $targetPage;
};

/*
 * Janela de números exibidos.
 *
 * Exemplo:
 * 1 ... 4 5 [6] 7 8 ... 20
 */
$pageNumbers = [];

if ($totalPages > 1) {

    $start = max(
        1,
        $page - 2
    );

    $end = min(
        $totalPages,
        $page + 2
    );

    if ($page <= 3) {
        $end = min(
            $totalPages,
            5
        );
    }

    if ($page >= $totalPages - 2) {
        $start = max(
            1,
            $totalPages - 4
        );
    }

    for ($i = $start; $i <= $end; $i++) {
        $pageNumbers[] = $i;
    }
}

require themeFile(
    $pdo,
    'header.php'
);

?>

<section class="container py-5">

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

        <div>

            <div class="text-secondary text-uppercase small fw-semibold">
                Categoria
            </div>

            <h1 class="display-6 fw-bold">
                <?= e($categoria['nome']) ?>
            </h1>

            <?php if ($categoria['descricao']): ?>

                <p class="lead text-secondary mb-0">
                    <?= e($categoria['descricao']) ?>
                </p>

            <?php endif; ?>

            <?php if ($totalPosts > 0): ?>

                <div class="small text-secondary mt-2">

                    <?= e((string)$totalPosts) ?>
                    <?= $totalPosts === 1 ? 'notícia' : 'notícias' ?>

                    <?php if ($totalPages > 1): ?>
                        · Página <?= e((string)$page) ?>
                        de <?= e((string)$totalPages) ?>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

        <?php if ($alternateFeedUrl): ?>

            <a
                class="btn btn-outline-secondary"
                href="<?= e($alternateFeedUrl) ?>"
            >
                RSS da categoria
            </a>

        <?php endif; ?>

    </div>

    <div class="row g-4">

        <?php if (!$posts): ?>

            <div class="col-12">

                <div class="alert alert-light border">
                    Nenhuma notícia publicada nesta categoria.
                </div>

            </div>

        <?php endif; ?>

        <?php foreach ($posts as $p): ?>

            <div class="col-md-6 col-xl-4">

                <article class="card h-100 border-0 shadow-sm overflow-hidden">

                    <?php if ($p['imagem']): ?>

                        <img
                            class="news-card-image"
                            src="<?= e(mediaUrl((string)$p['imagem'])) ?>"
                            alt="<?= e($p['titulo']) ?>"
                        >

                    <?php endif; ?>

                    <div class="card-body">

                        <div class="small text-secondary mb-2">

                            <?= e(
                                formatDateBr(
                                    $p['publicado_em']
                                    ?: $p['created_at']
                                )
                            ) ?>

                        </div>

                        <h2 class="h5">

                            <a
                                class="stretched-link text-decoration-none text-reset"
                                href="<?= e(
                                    contentUrl(
                                        'noticia',
                                        (string)$p['slug']
                                    )
                                ) ?>"
                            >
                                <?= e($p['titulo']) ?>
                            </a>

                        </h2>

                        <p class="text-secondary mb-0">

                            <?= e(
                                $p['resumo']
                                ?: portalExcerpt(
                                    $p['conteudo'],
                                    150
                                )
                            ) ?>

                        </p>

                    </div>

                </article>

            </div>

        <?php endforeach; ?>

    </div>

    <?php if ($totalPages > 1): ?>

        <nav
            class="mt-5"
            aria-label="Paginação da categoria"
        >

            <ul class="pagination justify-content-center flex-wrap">

                <li
                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                >

                    <?php if ($page <= 1): ?>

                        <span
                            class="page-link"
                            aria-disabled="true"
                        >
                            Anterior
                        </span>

                    <?php else: ?>

                        <a
                            class="page-link"
                            href="<?= e($categoryPageUrl($page - 1)) ?>"
                            rel="prev"
                        >
                            Anterior
                        </a>

                    <?php endif; ?>

                </li>

                <?php if ($pageNumbers && $pageNumbers[0] > 1): ?>

                    <li class="page-item">

                        <a
                            class="page-link"
                            href="<?= e($categoryPageUrl(1)) ?>"
                        >
                            1
                        </a>

                    </li>

                    <?php if ($pageNumbers[0] > 2): ?>

                        <li class="page-item disabled">
                            <span class="page-link">…</span>
                        </li>

                    <?php endif; ?>

                <?php endif; ?>

                <?php foreach ($pageNumbers as $number): ?>

                    <li
                        class="page-item <?= $number === $page ? 'active' : '' ?>"
                        <?= $number === $page ? 'aria-current="page"' : '' ?>
                    >

                        <?php if ($number === $page): ?>

                            <span class="page-link">
                                <?= e((string)$number) ?>
                            </span>

                        <?php else: ?>

                            <a
                                class="page-link"
                                href="<?= e($categoryPageUrl($number)) ?>"
                            >
                                <?= e((string)$number) ?>
                            </a>

                        <?php endif; ?>

                    </li>

                <?php endforeach; ?>

                <?php
                $lastVisible = $pageNumbers
                    ? $pageNumbers[count($pageNumbers) - 1]
                    : 0;
                ?>

                <?php if ($lastVisible < $totalPages): ?>

                    <?php if ($lastVisible < $totalPages - 1): ?>

                        <li class="page-item disabled">
                            <span class="page-link">…</span>
                        </li>

                    <?php endif; ?>

                    <li class="page-item">

                        <a
                            class="page-link"
                            href="<?= e($categoryPageUrl($totalPages)) ?>"
                        >
                            <?= e((string)$totalPages) ?>
                        </a>

                    </li>

                <?php endif; ?>

                <li
                    class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                >

                    <?php if ($page >= $totalPages): ?>

                        <span
                            class="page-link"
                            aria-disabled="true"
                        >
                            Próxima
                        </span>

                    <?php else: ?>

                        <a
                            class="page-link"
                            href="<?= e($categoryPageUrl($page + 1)) ?>"
                            rel="next"
                        >
                            Próxima
                        </a>

                    <?php endif; ?>

                </li>

            </ul>

        </nav>

    <?php endif; ?>

</section>

<?php

require themeFile(
    $pdo,
    'footer.php'
);
