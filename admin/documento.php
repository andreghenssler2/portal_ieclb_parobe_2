<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$slug = routeSlug('documento');
$document = $slug !== '' ? DocumentService::findPublishedBySlug($pdo, $slug) : null;

if (!$document) {
    http_response_code(404);
    $metaTitle = 'Documento não encontrado';
    $metaDescription = 'O documento solicitado não foi encontrado.';
    $metaNoindex = true;
    require themeFile($pdo, 'header.php');
    echo '<section class="container py-5"><div class="alert alert-light border"><h1 class="h3">Documento não encontrado</h1><p class="mb-0">O arquivo pode ter sido removido ou ainda não está publicado.</p></div></section>';
    require themeFile($pdo, 'footer.php');
    exit;
}

redirectCanonicalContent('documento', (string)$document['slug']);

$metaTitle = trim((string)($document['seo_titulo'] ?? '')) ?: (string)$document['titulo'];
$metaDescription = trim((string)($document['seo_descricao'] ?? '')) ?: portalExcerpt((string)($document['descricao'] ?? ''), 160);
$metaNoindex = !empty($document['seo_noindex']);
$canonicalUrl = contentUrl('documento', (string)$document['slug']);

require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <nav class="small mb-3"><a href="<?=e(url('documentos'))?>">Documentos</a><?php if($document['categoria_nome']):?> <span class="text-secondary">/</span> <a href="<?=e(url('documentos?categoria='.rawurlencode((string)$document['categoria_slug'])))?>"><?=e($document['categoria_nome'])?></a><?php endif;?></nav>
            <article class="card border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <span class="badge text-bg-light border"><?=e(DocumentService::fileLabel($document))?></span>
                        <?php if($document['publicado_em']):?><span class="text-secondary small">Publicado em <?=e(formatDateOnlyBr($document['publicado_em']))?></span><?php endif;?>
                    </div>
                    <h1 class="display-6 fw-semibold mb-3"><?=e($document['titulo'])?></h1>
                    <?php if($document['descricao']):?><div class="fs-5 text-secondary lh-lg mb-4"><?=nl2br(e((string)$document['descricao']))?></div><?php endif;?>

                    <div class="border rounded-3 p-3 p-md-4 bg-body-tertiary">
                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?=e($document['nome_original'] ?: $document['titulo'])?></div>
                                <div class="small text-secondary mt-1">
                                    <?=e(DocumentService::fileLabel($document))?>
                                    <?php if($document['tamanho']):?> · <?=e(formatBytes((int)$document['tamanho']))?><?php endif;?>
                                    · <?=number_format((int)$document['downloads'],0,',','.')?> download(s)
                                </div>
                            </div>
                            <?php if(!empty($document['midia_id'])):?>
                                <a class="btn btn-primary btn-lg" href="<?=e(DocumentService::downloadUrl((string)$document['slug']))?>"><i class="bi bi-download me-2"></i>Baixar arquivo</a>
                            <?php else:?>
                                <span class="badge text-bg-warning">Arquivo temporariamente indisponível</span>
                            <?php endif;?>
                        </div>
                    </div>

                    <?php if(($_GET['arquivo'] ?? '')==='indisponivel'):?><div class="alert alert-warning mt-3 mb-0">O arquivo físico não está disponível no servidor. Verifique a Biblioteca de Mídia.</div><?php endif;?>
                </div>
            </article>
        </div>
    </div>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
