<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../_pagination.php';
require_once __DIR__ . '/../_search.php';
Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');
$pdo = Database::connection();
// v0.33.1: pesquisa de posts + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = " WHERE (
        p.titulo LIKE :post_q1
        OR p.slug LIKE :post_q2
        OR COALESCE(p.resumo,'') LIKE :post_q3
        OR COALESCE(p.conteudo,'') LIKE :post_q4
        OR COALESCE(c.nome,'') LIKE :post_q5
        OR EXISTS (
            SELECT 1
            FROM post_categorias pcq
            INNER JOIN categorias cq ON cq.id=pcq.categoria_id
            WHERE pcq.post_id=p.id
              AND (cq.nome LIKE :post_q6 OR cq.slug LIKE :post_q7)
        )
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 7; $i++) $searchParams['post_q' . $i] = $like;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id" . $searchSql
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);

$listSql = "SELECT p.*, c.nome AS comunidade_nome,
        (SELECT GROUP_CONCAT(cat.nome ORDER BY pc.principal DESC, cat.nome SEPARATOR '||')
         FROM post_categorias pc
         INNER JOIN categorias cat ON cat.id=pc.categoria_id
         WHERE pc.post_id=p.id) AS categorias_nomes
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id"
     . $searchSql
     . " ORDER BY p.id DESC LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$posts = $listStmt->fetchAll();
$pageTitle = 'Notícias';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">Notícias</h1><p class="text-secondary mb-0">Conteúdo publicado no portal.</p></div><a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a></div>
<?php /* v0.33.1-search-form-posts */ ?>
<?= adminSearchHtml('admin/noticias/index.php', $search, [], 'Pesquisar notícias…', $totalItems) ?>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Título</th><th>Comunidade</th><th>Categoria</th><th>Status</th><th>Publicação</th><th>Visualizações</th><th></th></tr></thead><tbody>
<?php if (!$posts): ?><tr><td colspan="7" class="text-secondary">Nenhuma notícia cadastrada.</td></tr><?php endif; ?>
<?php foreach ($posts as $post): ?><tr>
<td class="fw-semibold"><?= e($post['titulo']) ?></td><td><?= e($post['comunidade_nome'] ?: 'Paroquial') ?></td><td><?php $cats = array_filter(explode('||', (string)($post['categorias_nomes'] ?? ''))); ?><?php if ($cats): ?><div class="d-flex flex-wrap gap-1"><?php foreach ($cats as $cat): ?><span class="badge text-bg-light border"><?= e($cat) ?></span><?php endforeach; ?></div><?php else: ?>-<?php endif; ?></td><td><span class="badge text-bg-secondary"><?= e($post['status']) ?></span></td><td><?= e(formatDateBr($post['publicado_em'])) ?></td><td><?= number_format((int)($post['visualizacoes'] ?? 0), 0, ',', '.') ?></td><td class="text-end"><div class="d-flex gap-1 justify-content-end"><?php if (!empty($post['slug'])): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(contentUrl('noticia', (string)$post['slug'])) ?>">Ver</a><?php endif; ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/noticias/form.php?id='.(int)$post['id'])) ?>">Editar</a></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php /* v0.33.0-pagination-render */ ?>
<?= adminPaginationHtml('admin/noticias/index.php', $pagination, ['q' => $search]) ?>
<?php require __DIR__ . '/../_footer.php'; ?>
