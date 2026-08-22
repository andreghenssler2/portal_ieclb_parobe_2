<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('documentos.gerenciar');

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($action !== 'delete' || $id <= 0) {
                throw new RuntimeException('Ação inválida.');
            }
            $document = DocumentService::find($pdo, $id);
            if (!$document) {
                throw new RuntimeException('Documento não encontrado.');
            }
            DocumentService::delete($pdo, $id);
            logAction($pdo, 'documento.excluir', 'documentos', $id, (string)$document['titulo']);
            Session::flash('success', 'Documento excluído do catálogo. O arquivo original permanece na Biblioteca de Mídia.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
    header('Location: ' . url('admin/documentos/index.php'));
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$categoryId = max(0, (int)($_GET['categoria'] ?? 0));
$page = max(1, (int)($_GET['pagina'] ?? 1));

$list = DocumentService::adminList($pdo, $q, $status, $categoryId, $page, 50);
$categories = DocumentService::categories($pdo);

function documentAdminPageUrl(int $page, string $q, string $status, int $categoryId): string
{
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($status !== '') $params['status'] = $status;
    if ($categoryId > 0) $params['categoria'] = $categoryId;
    if ($page > 1) $params['pagina'] = $page;
    return url('admin/documentos/index.php' . ($params ? '?' . http_build_query($params) : ''));
}

$pageTitle = 'Documentos / Downloads';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Documentos / Downloads</h1>
        <p class="text-secondary mb-0">Publique arquivos da Biblioteca de Mídia em um catálogo público organizado.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/documentos/form.php')) ?>"><i class="bi bi-plus-lg me-1"></i>Novo documento</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-5">
                <label class="form-label small">Pesquisar</label>
                <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Título, descrição ou nome do arquivo">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small">Status</label>
                <select class="form-select" name="status">
                    <option value="">Todos</option>
                    <?php foreach (['rascunho'=>'Rascunho','publicado'=>'Publicado','arquivado'=>'Arquivado'] as $value=>$label): ?>
                        <option value="<?=e($value)?>" <?=$status===$value?'selected':''?>><?=e($label)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 col-lg-3">
                <label class="form-label small">Categoria</label>
                <select class="form-select" name="categoria">
                    <option value="0">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?=(int)$category['id']?>" <?=$categoryId===(int)$category['id']?'selected':''?>><?=e($category['nome'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-2 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1">Filtrar</button>
                <?php if ($q !== '' || $status !== '' || $categoryId > 0): ?><a class="btn btn-outline-secondary" href="<?=e(url('admin/documentos/index.php'))?>" title="Limpar">×</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
            <tr>
                <th>Título</th>
                <th>Arquivo</th>
                <th>Categoria</th>
                <th>Status</th>
                <th class="text-center">Downloads</th>
                <th>Publicação</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$list['items']): ?>
                <tr><td colspan="7" class="text-secondary py-4">Nenhum documento encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($list['items'] as $document): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?=e($document['titulo'])?></div>
                        <div class="small text-secondary"><code><?=e($document['slug'])?></code></div>
                    </td>
                    <td>
                        <?php if (!empty($document['midia_id'])): ?>
                            <span class="badge text-bg-light border"><?=e(DocumentService::fileLabel($document))?></span>
                            <span class="small text-secondary ms-1"><?=e($document['nome_original'] ?: 'arquivo')?></span>
                            <?php if (!empty($document['tamanho'])): ?><div class="small text-secondary"><?=e(formatBytes((int)$document['tamanho']))?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="badge text-bg-warning">Arquivo ausente</span>
                        <?php endif; ?>
                    </td>
                    <td><?=e($document['categoria_nome'] ?: 'Sem categoria')?></td>
                    <td><span class="badge text-bg-secondary"><?=e($document['status'])?></span></td>
                    <td class="text-center"><?=number_format((int)$document['downloads'], 0, ',', '.')?></td>
                    <td><?=e(formatDateBr($document['publicado_em']))?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($document['status']==='publicado'): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?=e(contentUrl('documento',(string)$document['slug']))?>">Ver</a><?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?=e(url('admin/documentos/form.php?id='.(int)$document['id']))?>">Editar</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este documento do catálogo? O arquivo da Biblioteca de Mídia não será apagado.');">
                            <?=Csrf::field()?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?=(int)$document['id']?>">
                            <button class="btn btn-sm btn-outline-danger">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($list['total'] > 0): ?>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="small text-secondary">
            Exibindo <?=$list['from']?>–<?=$list['to']?> de <?=$list['total']?> itens · 50 por página
        </div>
        <?php if ($list['pages'] > 1): ?>
        <nav aria-label="Paginação dos documentos"><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?=$list['page']<=1?'disabled':''?>"><a class="page-link" href="<?=e(documentAdminPageUrl(max(1,$list['page']-1),$q,$status,$categoryId))?>">Anterior</a></li>
            <?php
            $start=max(1,$list['page']-2); $end=min($list['pages'],$list['page']+2);
            if($start>1): ?><li class="page-item"><a class="page-link" href="<?=e(documentAdminPageUrl(1,$q,$status,$categoryId))?>">1</a></li><?php if($start>2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; endif;
            for($p=$start;$p<=$end;$p++): ?>
                <li class="page-item <?=$p===$list['page']?'active':''?>"><a class="page-link" href="<?=e(documentAdminPageUrl($p,$q,$status,$categoryId))?>"><?=$p?></a></li>
            <?php endfor;
            if($end<$list['pages']): if($end<$list['pages']-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?=e(documentAdminPageUrl($list['pages'],$q,$status,$categoryId))?>"><?=$list['pages']?></a></li><?php endif; ?>
            <li class="page-item <?=$list['page']>=$list['pages']?'disabled':''?>"><a class="page-link" href="<?=e(documentAdminPageUrl(min($list['pages'],$list['page']+1),$q,$status,$categoryId))?>">Próxima</a></li>
        </ul></nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
