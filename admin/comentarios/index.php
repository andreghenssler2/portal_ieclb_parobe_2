<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('comentarios.gerenciar');
$pdo = Database::connection();

$allowedStatuses = ['todos', 'pendente', 'aprovado', 'spam', 'lixeira'];
$status = (string)($_GET['status'] ?? 'pendente');
if (!in_array($status, $allowedStatuses, true)) $status = 'pendente';
$search = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
        header('Location: ' . url('admin/comentarios/index.php')); exit;
    }

    $commentId = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($commentId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT id,status FROM comentarios WHERE id=:id LIMIT 1');
            $stmt->execute(['id' => $commentId]);
            $comment = $stmt->fetch();
            if (!$comment) throw new RuntimeException('Comentário não encontrado.');

            $newStatus = null;
            $message = '';
            if ($action === 'approve') { $newStatus = 'aprovado'; $message = 'Comentário aprovado.'; }
            elseif ($action === 'pending') { $newStatus = 'pendente'; $message = 'Comentário enviado para moderação.'; }
            elseif ($action === 'spam') { $newStatus = 'spam'; $message = 'Comentário marcado como spam.'; }
            elseif ($action === 'trash') { $newStatus = 'lixeira'; $message = 'Comentário enviado para a lixeira.'; }
            elseif ($action === 'restore') { $newStatus = 'pendente'; $message = 'Comentário restaurado para moderação.'; }
            elseif ($action === 'delete') {
                if ((string)$comment['status'] !== 'lixeira') throw new RuntimeException('Envie o comentário para a lixeira antes de excluir permanentemente.');
                $pdo->prepare('DELETE FROM comentarios WHERE id=:id')->execute(['id' => $commentId]);
                logAction($pdo, 'comentario.excluir', 'comentarios', $commentId);
                Session::flash('success', 'Comentário excluído permanentemente.');
                header('Location: ' . url('admin/comentarios/index.php?status=lixeira')); exit;
            }

            if ($newStatus !== null) {
                $upd = $pdo->prepare('UPDATE comentarios SET status=:status, moderado_por=:moderador, moderado_em=NOW() WHERE id=:id');
                $upd->execute(['status' => $newStatus, 'moderador' => Auth::id(), 'id' => $commentId]);
                logAction($pdo, 'comentario.' . $newStatus, 'comentarios', $commentId);
                Session::flash('success', $message);
            }
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
    $return = 'admin/comentarios/index.php?status=' . rawurlencode($status);
    if ($search !== '') $return .= '&q=' . rawurlencode($search);
    header('Location: ' . url($return)); exit;
}

$counts = ['todos' => 0, 'pendente' => 0, 'aprovado' => 0, 'spam' => 0, 'lixeira' => 0];
try {
    $counts['todos'] = (int)$pdo->query('SELECT COUNT(*) FROM comentarios')->fetchColumn();
    foreach ($pdo->query('SELECT status,COUNT(*) total FROM comentarios GROUP BY status')->fetchAll() as $row) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
} catch (Throwable $e) {}

$where = [];
$params = [];
if ($status !== 'todos') { $where[] = 'co.status=:status'; $params['status'] = $status; }
if ($search !== '') {
    $where[] = '(co.autor_nome LIKE :q OR co.autor_email LIKE :q OR co.conteudo LIKE :q OR p.titulo LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$sql = "SELECT co.*,p.titulo AS post_titulo,p.slug AS post_slug,u.nome AS moderador_nome
        FROM comentarios co
        INNER JOIN posts p ON p.id=co.post_id
        LEFT JOIN usuarios u ON u.id=co.moderado_por";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY CASE co.status WHEN 'pendente' THEN 0 ELSE 1 END, co.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $comments = $stmt->fetchAll();

$labels = ['todos'=>'Todos','pendente'=>'Pendentes','aprovado'=>'Aprovados','spam'=>'Spam','lixeira'=>'Lixeira'];
$pageTitle = 'Comentários';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h1 class="h3 mb-1">Comentários</h1><p class="text-secondary mb-0">Modere os comentários enviados nas notícias.</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/discussao.php')) ?>"><i class="bi bi-gear me-1"></i>Configurações de discussão</a>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($labels as $key=>$label): ?>
            <a class="btn btn-sm <?= $status===$key?'btn-primary':'btn-outline-secondary' ?>" href="<?= e(url('admin/comentarios/index.php?status=' . $key)) ?>"><?= e($label) ?> <span class="badge <?= $status===$key?'text-bg-light':'text-bg-secondary' ?> ms-1"><?= (int)$counts[$key] ?></span></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="row g-2"><input type="hidden" name="status" value="<?= e($status) ?>"><div class="col-md-8 col-lg-5"><input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar autor, e-mail, comentário ou notícia"></div><div class="col-auto"><button class="btn btn-outline-primary">Buscar</button></div><?php if($search!==''):?><div class="col-auto"><a class="btn btn-link" href="<?=e(url('admin/comentarios/index.php?status='.$status))?>">Limpar</a></div><?php endif;?></form>
</div></div>

<div class="card border-0 shadow-sm overflow-hidden">
    <div class="table-responsive">
        <table class="table align-middle mb-0 comments-admin-table">
            <thead><tr><th>Autor</th><th>Comentário</th><th>Em resposta a</th><th>Enviado em</th></tr></thead>
            <tbody>
            <?php if (!$comments): ?><tr><td colspan="4" class="text-secondary py-4">Nenhum comentário encontrado.</td></tr><?php endif; ?>
            <?php foreach ($comments as $comment): ?>
                <tr>
                    <td class="comments-author-cell">
                        <div class="fw-semibold"><?= e($comment['autor_nome']) ?></div>
                        <a class="small text-decoration-none" href="mailto:<?= e($comment['autor_email']) ?>"><?= e($comment['autor_email']) ?></a>
                        <?php if($comment['ip']): ?><div class="small text-secondary mt-1">IP <?= e($comment['ip']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <div class="comment-status-line mb-2"><span class="badge <?= $comment['status']==='aprovado'?'text-bg-success':($comment['status']==='pendente'?'text-bg-warning':($comment['status']==='spam'?'text-bg-danger':'text-bg-secondary')) ?>"><?= e($labels[$comment['status']] ?? $comment['status']) ?></span></div>
                        <div class="comment-admin-text"><?= nl2br(e($comment['conteudo'])) ?></div>
                        <div class="comment-row-actions mt-2 d-flex flex-wrap gap-2">
                            <?php if($comment['status']!=='aprovado'): ?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="approve"><button class="btn btn-sm btn-link text-success p-0">Aprovar</button></form><?php endif; ?>
                            <?php if($comment['status']!=='pendente' && $comment['status']!=='lixeira'): ?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="pending"><button class="btn btn-sm btn-link p-0">Pendente</button></form><?php endif; ?>
                            <?php if($comment['status']!=='spam' && $comment['status']!=='lixeira'): ?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="spam"><button class="btn btn-sm btn-link text-warning p-0">Spam</button></form><?php endif; ?>
                            <?php if($comment['status']!=='lixeira'): ?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="trash"><button class="btn btn-sm btn-link text-danger p-0">Lixeira</button></form><?php else: ?><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-link p-0">Restaurar</button></form><form method="post" class="d-inline" onsubmit="return confirm('Excluir este comentário permanentemente?')"><?=Csrf::field()?><input type="hidden" name="id" value="<?=(int)$comment['id']?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-link text-danger p-0">Excluir permanentemente</button></form><?php endif; ?>
                        </div>
                    </td>
                    <td><a class="text-decoration-none" target="_blank" href="<?= e(contentUrl('noticia',(string)$comment['post_slug'])) ?>"><?= e($comment['post_titulo']) ?></a></td>
                    <td class="text-nowrap"><div><?= e(formatDateBr($comment['created_at'])) ?></div><?php if($comment['moderador_nome']):?><div class="small text-secondary">Moderado por <?=e($comment['moderador_nome'])?></div><?php endif;?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
