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
<div class="col-12"><article class="card border-0 shadow-sm overflow-hidden"><div class="row g-0">
<?php if(!empty($post['imagem_capa_midia'])):?><div class="col-md-4 col-lg-3"><a href="<?=e(contentUrl('noticia',(string)$post['slug']))?>" class="d-block h-100"><img src="<?=e(mediaUrl((string)$post['imagem_capa_midia']))?>" alt="<?=e((string)($post['imagem_capa_alt'] ?: $post['titulo']))?>" class="w-100 h-100" style="object-fit:cover;min-height:190px"></a></div><?php endif;?>
<div class="<?=!empty($post['imagem_capa_midia'])?'col-md-8 col-lg-9':'col-12'?>"><div class="card-body p-4"><div class="d-flex gap-3">
<div class="display-6 fw-bold text-secondary opacity-50" style="min-width:2.5rem"><?=str_pad((string)($index+1),2,'0',STR_PAD_LEFT)?></div>
<div class="flex-grow-1">
<div class="small text-secondary mb-2"><?=e($post['comunidade_nome'] ?: 'Paroquial')?> · <?=e(formatDateBr((string)($post['publicado_em'] ?: $post['created_at'])))?></div>
<h2 class="h4 mb-2"><a class="text-decoration-none text-reset" href="<?=e(contentUrl('noticia',(string)$post['slug']))?>"><?=e($post['titulo'])?></a></h2>
<?php if($post['resumo']):?><p class="text-secondary mb-3"><?=e($post['resumo'])?></p><?php endif;?>
<div class="d-flex flex-wrap align-items-center gap-3"><span class="small text-secondary"><i class="bi bi-eye me-1"></i><?=number_format((int)$post['visualizacoes_periodo'],0,',','.')?> visualização<?=((int)$post['visualizacoes_periodo'])===1?'':'ões'?></span><a class="btn btn-sm btn-outline-primary" href="<?=e(contentUrl('noticia',(string)$post['slug']))?>">Ler notícia</a></div>
</div></div></div></div>
</div></article></div>
<?php endforeach;?>
</div></section>
<?php require themeFile($pdo,'footer.php'); ?>
