<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('liderancas.gerenciar');

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = max(0, (int)($_POST['id'] ?? 0));
        try {
            $item = LeadershipService::find($pdo, $id);
            if (!$item) throw new RuntimeException('Perfil não encontrado.');
            LeadershipService::delete($pdo, $id);
            logAction($pdo, 'lideranca.excluir', 'liderancas', $id, (string)$item['nome']);
            Session::flash('success', 'Perfil removido.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
    header('Location: ' . url('admin/liderancas/index.php'));
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$type = strtolower(trim((string)($_GET['tipo'] ?? '')));
$communityId = max(0, (int)($_GET['comunidade'] ?? 0));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$page = max(1, (int)($_GET['pagina'] ?? 1));

$list = LeadershipService::adminList($pdo, $q, $type, $communityId, $status, $page);
$communities = LeadershipService::communities($pdo);

function leadershipAdminUrl(int $page, string $q, string $type, int $communityId, string $status): string
{
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($type !== '') $params['tipo'] = $type;
    if ($communityId > 0) $params['comunidade'] = $communityId;
    if ($status !== '') $params['status'] = $status;
    if ($page > 1) $params['pagina'] = $page;
    return url('admin/liderancas/index.php' . ($params ? '?' . http_build_query($params) : ''));
}

$pageTitle = 'Equipe / Lideranças';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Equipe / Pastores & Lideranças</h1>
        <p class="text-secondary mb-0">Cadastre quem serve na paróquia, comunidades, grupos e ministérios.</p>
    </div>
    <a class="btn btn-primary" href="<?=e(url('admin/liderancas/form.php'))?>"><i class="bi bi-person-plus me-1"></i>Adicionar pessoa</a>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<form method="get" class="row g-2 align-items-end">
    <div class="col-lg-4"><label class="form-label small">Pesquisar</label><input class="form-control" type="search" name="q" value="<?=e($q)?>" placeholder="Nome, função, biografia ou e-mail"></div>
    <div class="col-md-4 col-lg-2"><label class="form-label small">Tipo</label><select class="form-select" name="tipo"><option value="">Todos</option><?php foreach(LeadershipService::typeLabels() as $value=>$label):?><option value="<?=e($value)?>" <?=$type===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
    <div class="col-md-4 col-lg-3"><label class="form-label small">Comunidade</label><select class="form-select" name="comunidade"><option value="0">Todas</option><?php foreach($communities as $community):?><option value="<?=(int)$community['id']?>" <?=$communityId===(int)$community['id']?'selected':''?>><?=e($community['nome'])?></option><?php endforeach;?></select></div>
    <div class="col-md-4 col-lg-2"><label class="form-label small">Status</label><select class="form-select" name="status"><option value="">Todos</option><option value="ativos" <?=$status==='ativos'?'selected':''?>>Ativos</option><option value="inativos" <?=$status==='inativos'?'selected':''?>>Inativos</option></select></div>
    <div class="col-lg-1 d-flex gap-1"><button class="btn btn-outline-primary flex-grow-1" title="Filtrar"><i class="bi bi-search"></i></button><?php if($q!==''||$type!==''||$communityId>0||$status!==''):?><a class="btn btn-outline-secondary" href="<?=e(url('admin/liderancas/index.php'))?>" title="Limpar">×</a><?php endif;?></div>
</form>
</div></div>

<div class="card border-0 shadow-sm">
<div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Pessoa</th><th>Tipo / Função</th><th>Vínculo</th><th>Status</th><th>Ordem</th><th></th></tr></thead>
<tbody>
<?php if(!$list['items']):?><tr><td colspan="6" class="text-secondary py-4">Nenhum perfil encontrado.</td></tr><?php endif;?>
<?php foreach($list['items'] as $item):?>
<tr>
    <td>
        <div class="d-flex align-items-center gap-3">
            <?php if($item['foto_caminho']):?><img src="<?=e(mediaUrl((string)$item['foto_caminho']))?>" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:50%"><?php else:?><div class="d-flex align-items-center justify-content-center bg-body-tertiary text-secondary" style="width:52px;height:52px;border-radius:50%"><i class="bi bi-person fs-4"></i></div><?php endif;?>
            <div><div class="fw-semibold"><?=e($item['nome'])?></div><div class="small text-secondary"><code><?=e($item['slug'])?></code></div></div>
        </div>
    </td>
    <td><div><?=e(LeadershipService::typeLabel((string)$item['tipo']))?></div><?php if($item['funcao']):?><div class="small text-secondary"><?=e($item['funcao'])?></div><?php endif;?></td>
    <td><?php if($item['comunidade_nome']):?><div><?=e($item['comunidade_nome'])?></div><?php endif;?><?php if($item['grupo_nome']):?><div class="small text-secondary"><?=e($item['grupo_nome'])?></div><?php endif;?><?php if(!$item['comunidade_nome']&&!$item['grupo_nome']):?><span class="text-secondary">Paroquial</span><?php endif;?></td>
    <td><?=$item['ativo']?'<span class="badge text-bg-success">Ativo</span>':'<span class="badge text-bg-secondary">Inativo</span>'?></td>
    <td><?=(int)$item['ordem']?></td>
    <td class="text-end text-nowrap">
        <?php if($item['ativo']):?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?=e(contentUrl('lideranca',(string)$item['slug']))?>">Ver</a><?php endif;?>
        <a class="btn btn-sm btn-outline-secondary" href="<?=e(url('admin/liderancas/form.php?id='.(int)$item['id']))?>">Editar</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este perfil de liderança?');"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button class="btn btn-sm btn-outline-danger">Excluir</button></form>
    </td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php if($list['total']>0):?>
<div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="small text-secondary">Exibindo <?=$list['from']?>–<?=$list['to']?> de <?=$list['total']?> itens · 50 por página</div>
    <?php if($list['pages']>1):?><nav><ul class="pagination pagination-sm mb-0">
        <li class="page-item <?=$list['page']<=1?'disabled':''?>"><a class="page-link" href="<?=e(leadershipAdminUrl(max(1,$list['page']-1),$q,$type,$communityId,$status))?>">Anterior</a></li>
        <?php $start=max(1,$list['page']-2);$end=min($list['pages'],$list['page']+2);for($p=$start;$p<=$end;$p++):?><li class="page-item <?=$p===$list['page']?'active':''?>"><a class="page-link" href="<?=e(leadershipAdminUrl($p,$q,$type,$communityId,$status))?>"><?=$p?></a></li><?php endfor;?>
        <li class="page-item <?=$list['page']>=$list['pages']?'disabled':''?>"><a class="page-link" href="<?=e(leadershipAdminUrl(min($list['pages'],$list['page']+1),$q,$type,$communityId,$status))?>">Próxima</a></li>
    </ul></nav><?php endif;?>
</div>
<?php endif;?>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
