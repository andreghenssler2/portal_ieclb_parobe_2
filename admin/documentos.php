<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$q = trim((string)($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q, 0, 120) : substr($q, 0, 120);
$categorySlug = strtolower(trim((string)($_GET['categoria'] ?? '')));
$page = max(1, (int)($_GET['pagina'] ?? 1));

$list = DocumentService::publicList($pdo, $q, $categorySlug, $page, 30);
$categories = DocumentService::categories($pdo, true);

$metaTitle = 'Documentos e Downloads';
$metaDescription = 'Documentos, boletins, formulários e arquivos públicos da IECLB Parobé.';
$metaNoindex = ($q !== '' || $categorySlug !== '' || $page > 1);
$canonicalUrl = url('documentos');

function publicDocumentsPageUrl(int $page, string $q, string $categorySlug): string
{
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($categorySlug !== '') $params['categoria'] = $categorySlug;
    if ($page > 1) $params['pagina'] = $page;
    return url('documentos' . ($params ? '?' . http_build_query($params) : ''));
}

require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-semibold mb-2">Documentos e Downloads</h1>
            <p class="lead text-secondary mb-0">Boletins, formulários, atas e materiais disponibilizados pela Paróquia.</p>
        </div>
    </div>

    <form method="get" action="<?=e(url('documentos'))?>" class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="row g-2">
                <div class="col-lg-7"><input class="form-control form-control-lg" type="search" name="q" value="<?=e($q)?>" placeholder="Pesquisar documentos..."></div>
                <div class="col-md-8 col-lg-3">
                    <select class="form-select form-select-lg" name="categoria">
                        <option value="">Todas as categorias</option>
                        <?php foreach($categories as $category):?><option value="<?=e($category['slug'])?>" <?=$categorySlug===$category['slug']?'selected':''?>><?=e($category['nome'])?></option><?php endforeach;?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-2"><button class="btn btn-primary btn-lg w-100">Pesquisar</button></div>
            </div>
        </div>
    </form>

    <?php if($q!=='' || $categorySlug!==''):?>
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="text-secondary"><?=$list['total']?> resultado(s) encontrado(s).</div>
            <a class="small" href="<?=e(url('documentos'))?>">Limpar filtros</a>
        </div>
    <?php endif;?>

    <?php if(!$list['items']):?>
        <div class="alert alert-light border">Nenhum documento publicado foi encontrado.</div>
    <?php else:?>
        <div class="row g-3">
        <?php foreach($list['items'] as $document):?>
            <div class="col-12">
                <article class="card border-0 shadow-sm">
                    <div class="card-body p-4 d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div class="flex-shrink-0">
                            <span class="badge text-bg-light border fs-6 px-3 py-2"><?=e(DocumentService::fileLabel($document))?></span>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                <?php if($document['categoria_nome']):?><a class="small text-decoration-none" href="<?=e(url('documentos?categoria='.rawurlencode((string)$document['categoria_slug'])))?>"><?=e($document['categoria_nome'])?></a><?php endif;?>
                                <?php if($document['publicado_em']):?><span class="small text-secondary"><?=e(formatDateOnlyBr($document['publicado_em']))?></span><?php endif;?>
                            </div>
                            <h2 class="h5 mb-1"><a class="text-decoration-none" href="<?=e(contentUrl('documento',(string)$document['slug']))?>"><?=e($document['titulo'])?></a></h2>
                            <?php if($document['descricao']):?><p class="text-secondary mb-0"><?=e(portalExcerpt((string)$document['descricao'],220))?></p><?php endif;?>
                            <div class="small text-secondary mt-2">
                                <?php if($document['tamanho']):?><?=e(formatBytes((int)$document['tamanho']))?> · <?php endif;?>
                                <?=number_format((int)$document['downloads'],0,',','.')?> download(s)
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-shrink-0">
                            <a class="btn btn-outline-secondary" href="<?=e(contentUrl('documento',(string)$document['slug']))?>">Detalhes</a>
                            <?php if(!empty($document['midia_id'])):?><a class="btn btn-primary" href="<?=e(DocumentService::downloadUrl((string)$document['slug']))?>"><i class="bi bi-download me-1"></i>Baixar</a><?php endif;?>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach;?>
        </div>
    <?php endif;?>

    <?php if($list['pages']>1):?>
    <nav class="mt-4" aria-label="Paginação de documentos"><ul class="pagination justify-content-center">
        <li class="page-item <?=$list['page']<=1?'disabled':''?>"><a class="page-link" href="<?=e(publicDocumentsPageUrl(max(1,$list['page']-1),$q,$categorySlug))?>">Anterior</a></li>
        <?php for($p=max(1,$list['page']-2);$p<=min($list['pages'],$list['page']+2);$p++):?><li class="page-item <?=$p===$list['page']?'active':''?>"><a class="page-link" href="<?=e(publicDocumentsPageUrl($p,$q,$categorySlug))?>"><?=$p?></a></li><?php endfor;?>
        <li class="page-item <?=$list['page']>=$list['pages']?'disabled':''?>"><a class="page-link" href="<?=e(publicDocumentsPageUrl(min($list['pages'],$list['page']+1),$q,$categorySlug))?>">Próxima</a></li>
    </ul></nav>
    <?php endif;?>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
