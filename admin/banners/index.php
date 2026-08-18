<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('banners.gerenciar');
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $action === 'toggle') {
            $stmt = $pdo->prepare('UPDATE banners SET ativo = IF(ativo=1,0,1) WHERE id=:id');
            $stmt->execute(['id' => $id]);
            logAction($pdo, 'banner.alternar', 'banners', $id);
            header('Location: ' . url('admin/banners/index.php'));
            exit;
        }
        if ($id > 0 && $action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM banners WHERE id=:id');
            $stmt->execute(['id' => $id]);
            logAction($pdo, 'banner.excluir', 'banners', $id);
            Session::flash('success', 'Banner excluído.');
            header('Location: ' . url('admin/banners/index.php'));
            exit;
        }
    }
}

$banners = $pdo->query(
    "SELECT b.*, m.caminho AS imagem_caminho, m.alt_text AS imagem_alt
     FROM banners b
     INNER JOIN midias m ON m.id=b.imagem_id
     ORDER BY b.ordem ASC, b.id DESC"
)->fetchAll();

$pageTitle = 'Banners';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Banners da Home</h1>
        <p class="text-secondary mb-0">Controle os destaques visuais exibidos na página inicial.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/banners/form.php')) ?>">Novo banner</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3">
<?php if (!$banners): ?><div class="col-12"><div class="alert alert-light border">Nenhum banner cadastrado.</div></div><?php endif; ?>
<?php foreach ($banners as $banner):
    $now = time();
    $start = $banner['data_inicio'] ? strtotime($banner['data_inicio']) : null;
    $end = $banner['data_fim'] ? strtotime($banner['data_fim']) : null;
    $isCurrent = (int)$banner['ativo'] === 1 && (!$start || $start <= $now) && (!$end || $end >= $now);
?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm overflow-hidden h-100">
            <img src="<?= e(mediaUrl($banner['imagem_caminho'])) ?>" class="banner-admin-thumb" alt="<?= e($banner['imagem_alt'] ?: $banner['titulo']) ?>">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between gap-3 mb-2">
                    <h2 class="h5 mb-0"><?= e($banner['titulo'] ?: 'Banner sem título') ?></h2>
                    <span class="badge text-bg-<?= $isCurrent ? 'success' : ((int)$banner['ativo'] === 1 ? 'warning' : 'secondary') ?>"><?= $isCurrent ? 'Em exibição' : ((int)$banner['ativo'] === 1 ? 'Agendado/inativo no período' : 'Desativado') ?></span>
                </div>
                <?php if ($banner['subtitulo']): ?><p class="text-secondary mb-2"><?= e($banner['subtitulo']) ?></p><?php endif; ?>
                <div class="small text-secondary">Ordem: <?= (int)$banner['ordem'] ?><?php if ($banner['data_inicio']): ?> · Início: <?= e(formatDateBr($banner['data_inicio'])) ?><?php endif; ?><?php if ($banner['data_fim']): ?> · Fim: <?= e(formatDateBr($banner['data_fim'])) ?><?php endif; ?></div>
            </div>
            <div class="card-footer bg-white border-0 d-flex flex-wrap gap-2 p-4 pt-0">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/banners/form.php?id=' . (int)$banner['id'])) ?>">Editar</a>
                <form method="post" class="d-inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$banner['id'] ?>"><input type="hidden" name="action" value="toggle">
                    <button class="btn btn-sm btn-outline-primary"><?= (int)$banner['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Excluir este banner?');">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$banner['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-sm btn-outline-danger">Excluir</button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
