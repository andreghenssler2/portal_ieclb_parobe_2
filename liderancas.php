<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$q = trim((string)($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q,0,120) : substr($q,0,120);
$type = strtolower(trim((string)($_GET['tipo'] ?? '')));
$communityId = max(0,(int)($_GET['comunidade'] ?? 0));
$page = max(1,(int)($_GET['pagina'] ?? 1));

$list = LeadershipService::publicList($pdo,$q,$type,$communityId,$page);
$communities = LeadershipService::communities($pdo);

$metaTitle = 'Equipe, Pastores e Lideranças';
$metaDescription = 'Conheça pastores, presbitério, lideranças e equipe da IECLB Parobé.';
$metaNoindex = ($q!=='' || $type!=='' || $communityId>0 || $page>1);
$canonicalUrl = url('liderancas');

function leadershipPublicUrl(int $page,string $q,string $type,int $communityId): string
{
    $params=[];
    if($q!=='')$params['q']=$q;
    if($type!=='')$params['tipo']=$type;
    if($communityId>0)$params['comunidade']=$communityId;
    if($page>1)$params['pagina']=$page;
    return url('liderancas'.($params?'?'.http_build_query($params):''));
}

require themeFile($pdo,'header.php');
?>
<section class="container py-5">
    <div class="text-center mx-auto mb-5" style="max-width:780px">
        <h1 class="display-5 fw-semibold mb-3">Equipe, Pastores e Lideranças</h1>
        <p class="lead text-secondary mb-0">Conheça pessoas que servem na vida comunitária e no ministério da Paróquia.</p>
    </div>

    <form method="get" action="<?=e(url('liderancas'))?>" class="card border-0 shadow-sm mb-4"><div class="card-body p-3 p-md-4"><div class="row g-2">
        <div class="col-lg-5"><input class="form-control" type="search" name="q" value="<?=e($q)?>" placeholder="Pesquisar por nome ou função"></div>
        <div class="col-md-4 col-lg-3"><select class="form-select" name="tipo"><option value="">Todos os tipos</option><?php foreach(LeadershipService::typeLabels() as $value=>$label):?><option value="<?=e($value)?>" <?=$type===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
        <div class="col-md-5 col-lg-3"><select class="form-select" name="comunidade"><option value="0">Todas as comunidades</option><?php foreach($communities as $community):?><option value="<?=(int)$community['id']?>" <?=$communityId===(int)$community['id']?'selected':''?>><?=e($community['nome'])?></option><?php endforeach;?></select></div>
        <div class="col-md-3 col-lg-1"><button class="btn btn-primary w-100"><i class="bi bi-search"></i></button></div>
    </div></div></form>

    <?php if($q!==''||$type!==''||$communityId>0):?><div class="d-flex justify-content-between align-items-center mb-3"><span class="text-secondary"><?=$list['total']?> resultado(s)</span><a class="small" href="<?=e(url('liderancas'))?>">Limpar filtros</a></div><?php endif;?>

    <?php if(!$list['items']):?><div class="alert alert-light border">Nenhuma liderança encontrada.</div><?php else:?><div class="row g-4">
    <?php foreach($list['items'] as $item):?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
            <article class="card h-100 border-0 shadow-sm overflow-hidden">
                <?php if($item['foto_caminho']):?><img src="<?=e(mediaUrl((string)$item['foto_caminho']))?>" class="card-img-top" alt="<?=e($item['foto_alt'] ?: $item['foto_titulo'] ?: $item['nome'])?>" style="height:280px;object-fit:cover" loading="lazy"><?php else:?><div class="d-flex align-items-center justify-content-center bg-body-tertiary text-secondary" style="height:280px"><i class="bi bi-person-circle" style="font-size:5rem"></i></div><?php endif;?>
                <div class="card-body p-4">
                    <span class="badge text-bg-light border mb-2"><?=e(LeadershipService::typeLabel((string)$item['tipo']))?></span>
                    <h2 class="h5 mb-1"><a class="stretched-link text-decoration-none" href="<?=e(contentUrl('lideranca',(string)$item['slug']))?>"><?=e($item['nome'])?></a></h2>
                    <?php if($item['funcao']):?><div class="text-secondary mb-2"><?=e($item['funcao'])?></div><?php endif;?>
                    <?php if($item['resumo']):?><p class="small text-secondary mb-0"><?=e(portalExcerpt((string)$item['resumo'],140))?></p><?php endif;?>
                </div>
                <?php if($item['comunidade_nome']||$item['grupo_nome']):?><div class="card-footer bg-white border-0 px-4 pb-4 pt-0 small text-secondary"><?php if($item['comunidade_nome']):?><div><i class="bi bi-geo-alt me-1"></i><?=e($item['comunidade_nome'])?></div><?php endif;?><?php if($item['grupo_nome']):?><div><i class="bi bi-people me-1"></i><?=e($item['grupo_nome'])?></div><?php endif;?></div><?php endif;?>
            </article>
        </div>
    <?php endforeach;?>
    </div><?php endif;?>

    <?php if($list['pages']>1):?><nav class="mt-5" aria-label="Paginação das lideranças"><ul class="pagination justify-content-center">
        <li class="page-item <?=$list['page']<=1?'disabled':''?>"><a class="page-link" href="<?=e(leadershipPublicUrl(max(1,$list['page']-1),$q,$type,$communityId))?>">Anterior</a></li>
        <?php for($p=max(1,$list['page']-2);$p<=min($list['pages'],$list['page']+2);$p++):?><li class="page-item <?=$p===$list['page']?'active':''?>"><a class="page-link" href="<?=e(leadershipPublicUrl($p,$q,$type,$communityId))?>"><?=$p?></a></li><?php endfor;?>
        <li class="page-item <?=$list['page']>=$list['pages']?'disabled':''?>"><a class="page-link" href="<?=e(leadershipPublicUrl(min($list['pages'],$list['page']+1),$q,$type,$communityId))?>">Próxima</a></li>
    </ul></nav><?php endif;?>
</section>
<?php require themeFile($pdo,'footer.php'); ?>
