<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$q = SearchService::normalizeQuery((string)($_GET['q'] ?? ''));
$results = [];

$queryLength = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);

if ($queryLength >= 2) {
    $results = SearchService::search($pdo, $q);
}

$labels = [
    'noticia' => 'Notícia',
    'pagina' => 'Página',
    'evento' => 'Evento',
    'galeria' => 'Galeria',
    'documento' => 'Documento',
    'lideranca' => 'Liderança',
];

$metaTitle = $q !== '' ? 'Busca por ' . $q : 'Busca';
$metaDescription = 'Busca interna do Portal IECLB Parobé.';
$metaNoindex = true;
$canonicalUrl = url('busca');

require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="search-page mx-auto">
        <h1 class="h2 mb-4">Busca</h1>

        <form class="input-group input-group-lg mb-4" method="get" action="<?=e(url('busca'))?>">
            <input
                class="form-control"
                type="search"
                name="q"
                value="<?=e($q)?>"
                placeholder="Buscar notícias, páginas, eventos, documentos, lideranças..."
                minlength="2"
                required
            >
            <button class="btn btn-primary">Buscar</button>
        </form>

        <?php if($q !== '' && $queryLength < 2):?>
            <div class="alert alert-warning">Digite pelo menos 2 caracteres.</div>
        <?php elseif($q !== ''):?>
            <p class="text-secondary mb-4">
                <?=count($results)?> resultado(s) para <strong><?=e($q)?></strong>.
            </p>

            <?php if(!$results):?>
                <div class="alert alert-light border">
                    Nenhum conteúdo encontrado.
                </div>
            <?php endif;?>

            <div class="list-group list-group-flush search-results">
                <?php foreach($results as $r):?>
                    <a
                        class="list-group-item list-group-item-action px-0 py-3"
                        href="<?=e(contentUrl((string)$r['type'], (string)$r['slug']))?>"
                    >
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <span class="badge text-bg-light border mb-2">
                                    <?=e($labels[$r['type']] ?? $r['type'])?>
                                </span>
                                <h2 class="h5 mb-1"><?=e((string)$r['titulo'])?></h2>
                                <p class="text-secondary mb-0">
                                    <?=e((string)($r['resumo'] ?: portalExcerpt((string)$r['conteudo'], 180)))?>
                                </p>
                            </div>

                            <?php if(!empty($r['dt'])):?>
                                <small class="text-secondary text-nowrap">
                                    <?=e(formatDateOnlyBr((string)$r['dt']))?>
                                </small>
                            <?php endif;?>
                        </div>
                    </a>
                <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
