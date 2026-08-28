<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo=Database::connection();
$period=NewsAnalyticsService::normalizePeriod((string)($_GET['periodo'] ?? '30'));
$ranking=NewsAnalyticsService::ranking($pdo,$period,50);
$summary=NewsAnalyticsService::summary($pdo);
$daily=NewsAnalyticsService::daily($pdo,30);
$pageTitle='Mais Lidas';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
<div><h1 class="h3 mb-1">Mais Lidas</h1><p class="text-secondary mb-0">Visualizações agregadas das notícias do Portal.</p></div>
<a class="btn btn-outline-primary" target="_blank" href="<?=e(url('mais-lidas'))?>"><i class="bi bi-box-arrow-up-right me-1"></i>Ver página pública</a>
</div>
<div class="row g-3 mb-4">
<?php foreach([
['Hoje',$summary['hoje'],'visualizações registradas'],
['7 dias',$summary['sete_dias'],'visualizações registradas'],
['30 dias',$summary['trinta_dias'],'visualizações registradas'],
['Total histórico',$summary['total'],'contador acumulado dos Posts'],
] as [$label,$value,$caption]):?>
<div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
<div class="text-secondary small"><?=e($label)?></div><div class="display-6 fw-semibold"><?=number_format((int)$value,0,',','.')?></div><div class="small text-secondary"><?=e($caption)?></div>
</div></div></div>
<?php endforeach;?>
</div>
<div class="card border-0 shadow-sm mb-4">
<div class="card-body"><div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
<div><div class="fw-semibold">Ranking</div><div class="small text-secondary"><?=e(NewsAnalyticsService::periodLabel($period))?></div></div>
<div class="btn-group"><a class="btn btn-sm <?=$period==='7'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('admin/noticias/mais-lidas.php?periodo=7'))?>">7 dias</a><a class="btn btn-sm <?=$period==='30'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('admin/noticias/mais-lidas.php?periodo=30'))?>">30 dias</a><a class="btn btn-sm <?=$period==='total'?'btn-primary':'btn-outline-primary'?>" href="<?=e(url('admin/noticias/mais-lidas.php?periodo=total'))?>">Todo período</a></div>
</div></div>
<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Posição</th><th>Notícia</th><th>Publicação</th><th class="text-end">Período</th><th class="text-end">Total</th><th></th></tr></thead><tbody>
<?php if(!$ranking):?><tr><td colspan="6" class="text-secondary py-4">Nenhuma visualização registrada neste período.</td></tr><?php endif;?>
<?php foreach($ranking as $index=>$post):?><tr><td class="fw-semibold">#<?=$index+1?></td><td><div class="fw-semibold"><?=e($post['titulo'])?></div><div class="small text-secondary"><?=e($post['comunidade_nome'] ?: 'Paroquial')?></div></td><td><?=e(formatDateBr((string)($post['publicado_em'] ?: $post['created_at'])))?></td><td class="text-end fw-semibold"><?=number_format((int)$post['visualizacoes_periodo'],0,',','.')?></td><td class="text-end"><?=number_format((int)$post['visualizacoes_total'],0,',','.')?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?=e(contentUrl('noticia',(string)$post['slug']))?>">Ver</a></td></tr><?php endforeach;?>
</tbody></table></div></div>
<div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><strong>Últimos 30 dias</strong></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Data</th><th class="text-end">Visualizações</th></tr></thead><tbody>
<?php if(!$daily):?><tr><td colspan="2" class="text-secondary py-3">O histórico diário começa a ser registrado a partir da v0.40.0.</td></tr><?php endif;?>
<?php foreach($daily as $row):?><tr><td><?=e((new DateTime((string)$row['data']))->format('d/m/Y'))?></td><td class="text-end"><?=number_format((int)$row['visualizacoes'],0,',','.')?></td></tr><?php endforeach;?>
</tbody></table></div></div>
<div class="alert alert-light border small mt-4 mb-0"><i class="bi bi-shield-check me-1"></i>As estatísticas são agregadas. Este módulo não grava IP, navegador ou outro dado pessoal do visitante.</div>
<?php require __DIR__ . '/../_footer.php'; ?>
