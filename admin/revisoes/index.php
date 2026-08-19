<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();

$type = (string)($_GET['tipo'] ?? '');
$contentId = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['post', 'pagina'], true) || $contentId <= 0) {
    http_response_code(400);
    exit('Parâmetros de revisão inválidos.');
}

if ($type === 'post') {
    Auth::requirePermission('noticias.gerenciar');
    $stmt = $pdo->prepare('SELECT id, titulo, status FROM posts WHERE id = :id LIMIT 1');
    $backUrl = url('admin/noticias/form.php?id=' . $contentId);
    $trashUrl = url('admin/noticias/index.php?status=lixeira');
    $entityLabel = 'Notícia';
} else {
    Auth::requirePermission('paginas.gerenciar');
    $stmt = $pdo->prepare('SELECT id, titulo, status FROM paginas WHERE id = :id LIMIT 1');
    $backUrl = url('admin/paginas/form.php?id=' . $contentId);
    $trashUrl = url('admin/paginas/index.php?status=lixeira');
    $entityLabel = 'Página';
}
$stmt->execute(['id' => $contentId]);
$content = $stmt->fetch();
if (!$content) {
    http_response_code(404);
    exit($entityLabel . ' não encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $revisionId = (int)($_POST['revision_id'] ?? 0);
        try {
            if ($action !== 'restore' || $revisionId <= 0) {
                throw new RuntimeException('Ação de revisão inválida.');
            }
            if ((string)$content['status'] === 'lixeira') {
                throw new RuntimeException('Restaure o conteúdo da Lixeira antes de restaurar uma revisão.');
            }
            RevisionService::restore($pdo, $revisionId, $type, $contentId, Auth::id());
            logAction($pdo, 'revisao.restaurar', $type === 'post' ? 'posts' : 'paginas', $contentId, 'Revisão #' . $revisionId);
            Session::flash('success', 'Revisão restaurada. A versão que estava ativa também foi salva no histórico.');
            header('Location: ' . url('admin/revisoes/index.php?tipo=' . rawurlencode($type) . '&id=' . $contentId));
            exit;
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }
}

$revisions = RevisionService::list($pdo, $type, $contentId);
$pageTitle = 'Revisões - ' . $content['titulo'];
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Revisões</h1>
        <p class="text-secondary mb-0"><?= e($entityLabel) ?>: <strong><?= e((string)$content['titulo']) ?></strong></p>
    </div>
    <div class="d-flex gap-2">
        <?php if ((string)$content['status'] === 'lixeira'): ?>
            <a class="btn btn-outline-secondary" href="<?= e($trashUrl) ?>">Ir para a Lixeira</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>">Voltar para edição</a>
        <?php endif; ?>
    </div>
</div>

<?php if ((string)$content['status'] === 'lixeira'): ?>
    <div class="alert alert-warning">Este conteúdo está na Lixeira. Restaure-o antes de aplicar uma revisão anterior.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Histórico de versões</span>
        <span class="badge text-bg-secondary"><?= count($revisions) ?></span>
    </div>
    <div class="list-group list-group-flush">
        <?php if (!$revisions): ?>
            <div class="list-group-item py-4 text-secondary">Ainda não existem revisões. Uma revisão é criada automaticamente antes de cada alteração.</div>
        <?php endif; ?>
        <?php foreach ($revisions as $revision):
            $record = (array)($revision['snapshot']['record'] ?? []);
            $title = trim((string)($record['titulo'] ?? '')) ?: '(sem título)';
            $status = (string)($record['status'] ?? 'rascunho');
            $excerpt = portalExcerpt((string)($record['conteudo'] ?? ''), 220);
        ?>
            <div class="list-group-item p-3 p-lg-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="fw-semibold">Revisão #<?= (int)$revision['id'] ?></span>
                            <span class="badge text-bg-light border"><?= e($status) ?></span>
                        </div>
                        <div class="mb-1"><?= e($title) ?></div>
                        <?php if ($excerpt !== ''): ?><div class="small text-secondary mb-2"><?= e($excerpt) ?></div><?php endif; ?>
                        <div class="small text-secondary">
                            <?= e(formatDateBr((string)$revision['created_at'])) ?>
                            · <?= e((string)($revision['autor_nome'] ?: 'Usuário não disponível')) ?>
                        </div>
                    </div>
                    <div class="align-self-lg-center">
                        <form method="post" onsubmit="return confirm('Restaurar esta revisão? A versão atual será preservada no histórico.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>">
                            <button class="btn btn-outline-primary" <?= (string)$content['status'] === 'lixeira' ? 'disabled' : '' ?>>Restaurar</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
