<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$slug = routeSlug('lideranca');
$item = $slug!=='' ? LeadershipService::findActiveBySlug($pdo,$slug) : null;

if(!$item){
    http_response_code(404);
    $metaTitle='Liderança não encontrada';
    $metaDescription='O perfil solicitado não foi encontrado.';
    $metaNoindex=true;
    require themeFile($pdo,'header.php');
    echo '<section class="container py-5"><div class="alert alert-light border"><h1 class="h3">Perfil não encontrado</h1><p class="mb-0">Este perfil pode estar inativo ou ter sido removido.</p></div></section>';
    require themeFile($pdo,'footer.php');
    exit;
}

redirectCanonicalContent('lideranca',(string)$item['slug']);

$metaTitle = trim((string)($item['seo_titulo']??'')) ?: trim((string)$item['nome'].' · '.(string)($item['funcao']??''));
$metaDescription = trim((string)($item['seo_descricao']??'')) ?: portalExcerpt((string)($item['resumo'] ?: $item['biografia']),160);
$metaNoindex = !empty($item['seo_noindex']);
$canonicalUrl = contentUrl('lideranca',(string)$item['slug']);
$metaImage = $item['foto_caminho'] ? mediaUrl((string)$item['foto_caminho']) : null;

require themeFile($pdo,'header.php');
?>
<section class="container py-5">
<div class="row justify-content-center">
<div class="col-xl-10">
    <nav class="small mb-4"><a href="<?=e(url('liderancas'))?>">Lideranças</a> <span class="text-secondary">/</span> <span class="text-secondary"><?=e($item['nome'])?></span></nav>
    <div class="row g-5 align-items-start">
        <div class="col-lg-4">
            <?php if($item['foto_caminho']):?><img src="<?=e(mediaUrl((string)$item['foto_caminho']))?>" class="img-fluid rounded-4 shadow-sm w-100" alt="<?=e($item['foto_alt'] ?: $item['foto_titulo'] ?: $item['nome'])?>" style="max-height:560px;object-fit:cover"><?php else:?><div class="rounded-4 bg-body-tertiary d-flex align-items-center justify-content-center text-secondary" style="min-height:420px"><i class="bi bi-person-circle" style="font-size:7rem"></i></div><?php endif;?>
        </div>
        <div class="col-lg-8">
            <span class="badge text-bg-light border mb-3"><?=e(LeadershipService::typeLabel((string)$item['tipo']))?></span>
            <h1 class="display-5 fw-semibold mb-2"><?=e($item['nome'])?></h1>
            <?php if($item['funcao']):?><div class="fs-4 text-primary mb-3"><?=e($item['funcao'])?></div><?php endif;?>
            <?php if($item['resumo']):?><p class="lead text-secondary"><?=e($item['resumo'])?></p><?php endif;?>

            <?php if($item['comunidade_nome']||$item['grupo_nome']):?><div class="d-flex flex-wrap gap-2 my-4">
                <?php if($item['comunidade_nome']):?><span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-geo-alt me-1"></i><?=e($item['comunidade_nome'])?></span><?php endif;?>
                <?php if($item['grupo_nome']):?><span class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-people me-1"></i><?=e($item['grupo_nome'])?></span><?php endif;?>
            </div><?php endif;?>

            <?php if($item['biografia']):?><div class="lh-lg fs-5 my-4"><?=nl2br(e((string)$item['biografia']))?></div><?php endif;?>

            <?php
            $contactButtons=[];
            if($item['exibir_email'] && $item['email']) $contactButtons[]='<a class="btn btn-outline-primary" href="mailto:'.e((string)$item['email']).'"><i class="bi bi-envelope me-1"></i>E-mail</a>';
            if($item['exibir_telefone'] && $item['telefone']) $contactButtons[]='<a class="btn btn-outline-primary" href="tel:'.e((string)preg_replace('/[^0-9+]/','',(string)$item['telefone'])).'"><i class="bi bi-telephone me-1"></i>'.e((string)$item['telefone']).'</a>';
            $wa=LeadershipService::whatsappUrl($item['whatsapp']??null);
            if($item['exibir_whatsapp'] && $wa!=='') $contactButtons[]='<a class="btn btn-success" target="_blank" rel="noopener" href="'.e($wa).'"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>';
            if($item['instagram']) $contactButtons[]='<a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="'.e((string)$item['instagram']).'"><i class="bi bi-instagram me-1"></i>Instagram</a>';
            if($item['facebook']) $contactButtons[]='<a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="'.e((string)$item['facebook']).'"><i class="bi bi-facebook me-1"></i>Facebook</a>';
            ?>
            <?php if($contactButtons):?><div class="d-flex flex-wrap gap-2 mt-4"><?=implode('',$contactButtons)?></div><?php endif;?>
        </div>
    </div>
</div>
</div>
</section>
<?php require themeFile($pdo,'footer.php'); ?>
