<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('galerias.gerenciar');
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('SELECT titulo FROM galerias WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $titulo = (string)($stmt->fetchColumn() ?: 'Galeria');
                $delete = $pdo->prepare('DELETE FROM galerias WHERE id = :id');
                $delete->execute(['id' => $id]);
                logAction($pdo, 'galeria.excluir', 'galerias', $id, $titulo);
                Session::flash('success', 'Galeria excluída com sucesso.');
                header('Location: ' . url('admin/galerias/index.php'));
                exit;
            } catch (Throwable $e) {
                $error = 'Não foi possível excluir a galeria: ' . $e->getMessage();
            }
        }
    }
}

$galerias = $pdo->query(
    "SELECT g.*, u.nome AS autor_nome, m.caminho AS capa_caminho,
            (SELECT COUNT(*) FROM galeria_midias gm WHERE gm.galeria_id = g.id) AS total_fotos
     FROM galerias g
     LEFT JOIN usuarios u ON u.id = g.autor_id
     LEFT JOIN midias m ON m.id = g.imagem_capa_id
     ORDER BY g.id DESC"
)->fetchAll();

$pageTitle = 'Galerias';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Galerias de Fotos</h1>
        <p class="text-secondary mb-0">Crie álbuns usando imagens já existentes na Biblioteca de Mídia.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/galerias/form.php')) ?>">Nova galeria</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:88px">Capa</th>
                    <th>Galeria</th>
                    <th>Status</th>
                    <th>Fotos</th>
                    <th>Publicação</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$galerias): ?>
                <tr><td colspan="6" class="text-center text-secondary py-5">Nenhuma galeria cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($galerias as $g): ?>
                <tr>
                    <td>
                        <?php if ($g['capa_caminho']): ?>
                            <img src="<?= e(mediaUrl($g['capa_caminho'])) ?>" class="rounded gallery-admin-cover" alt="">
                        <?php else: ?>
                            <div class="rounded bg-light border gallery-admin-cover d-flex align-items-center justify-content-center small text-secondary">Sem capa</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($g['titulo']) ?></div>
                        <div class="small text-secondary">/galeria/<?= e($g['slug']) ?></div>
                    </td>
                    <td><span class="badge text-bg-<?= $g['status'] === 'publicado' ? 'success' : ($g['status'] === 'rascunho' ? 'secondary' : 'dark') ?>"><?= e(ucfirst($g['status'])) ?></span></td>
                    <td><?= (int)$g['total_fotos'] ?></td>
                    <td class="small text-secondary"><?= e($g['publicado_em'] ? formatDateBr($g['publicado_em']) : '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($g['status'] === 'publicado'): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= e(contentUrl('galeria', (string)$g['slug'])) ?>">Ver</a><?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/galerias/form.php?id=' . (int)$g['id'])) ?>">Editar</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta galeria? As imagens continuarão na Biblioteca de Mídia.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
