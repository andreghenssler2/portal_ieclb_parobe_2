<?php
require_once __DIR__ . '/bootstrap.php';
$pdo=Database::connection();
$period=NewsAnalyticsService::normalizePeriod((string)($_GET['periodo'] ?? 'total'));
$ranking=NewsAnalyticsService::ranking($pdo,$period,30);
$metaTitle='Mais Lidas - IECLB Parobé';
$metaDescription='Notícias mais lidas do Portal IECLB Parobé.';
$canonicalUrl=url('mais-lidas');
require themeFile($pdo,'header.php');
?>
<section class="container py-5">
<style>
.most-read-cover{width:180px;min-width:180px;height:120px}
@media (max-width:767.98px){
    .most-read-cover{width:100%;min-width:0;height:200px}
}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
<div><h1 class="display-6 fw-bold mb-2">Mais Lidas</h1><p class="lead text-secondary mb-0">Notícias que mais despertaram interesse no Portal.</p></div>
<div class="btn-group">
<a class="btn <?=$period==='7'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('mais-lidas?periodo=7'))?>">7 dias</a>
<a class="btn <?=$period==='30'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('mais-lidas?periodo=30'))?>">30 dias</a>
<a class="btn <?=$period==='total'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('mais-lidas?periodo=total'))?>">Todo período</a>
</div></div>
<div class="small text-secondary mb-4"><?=e(NewsAnalyticsService::periodLabel($period))?></div>
<?php if(!$ranking):?><div class="alert alert-light border">Ainda não há visualizações suficientes para este ranking.</div><?php endif;?>
<div class="row g-4">
<?php foreach($ranking as $index=>$post):?>
<div class="col-12"><article class="card border-0 shadow-sm overflow-hidden">
<div class="card-body p-4"><div class="d-flex flex-column flex-md-row gap-3 gap-md-4">
<?php if(!empty($post['imagem_capa_midia'])):?>
<a href="<?=e(contentUrl('noticia',(string)$post['slug']))?>" class="most-read-cover d-block flex-shrink-0 overflow-hidden rounded">
<img src="<?=e(mediaUrl((string)$post['imagem_capa_midia']))?>" alt="<?=e((string)($post['imagem_capa_alt'] ?: $post['titulo']))?>" class="w-100 h-100" style="object-fit:cover">
</a>
<?php else:?>
<div class="most-read-cover flex-shrink-0 rounded bg-light border d-flex align-items-center justify-content-center text-secondary" aria-hidden="true">
<i class="bi bi-image fs-2"></i>
</div>
<?php endif;?>
<div class="flex-grow-1">
<div class="small text-secondary mb-2"><?=e($post['comunidade_nome'] ?: 'Paroquial')?> · <?=e(formatDateBr((string)($post['publicado_em'] ?: $post['created_at'])))?></div>
<h2 class="h4 mb-2"><a class="text-decoration-none text-reset" href="<?=e(contentUrl('noticia',(string)$post['slug']))?>"><?=e($post['titulo'])?></a></h2>
<?php if($post['resumo']):?><p class="text-secondary mb-3"><?=e($post['resumo'])?></p><?php endif;?>
<div class="d-flex flex-wrap align-items-center gap-3"><span class="small text-secondary"><i class="bi bi-eye me-1"></i><?=number_format((int)$post['visualizacoes_periodo'],0,',','.')?> visualização<?=((int)$post['visualizacoes_periodo'])===1?'':'ões'?></span><a class="btn btn-sm btn-outline-primary" href="<?=e(contentUrl('noticia',(string)$post['slug']))?>">Ler notícia</a></div>
</div></div></div>
</article></div>
<?php endforeach;?>
</div></section>
<?php require themeFile($pdo,'footer.php'); ?>
