<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('permissoes.gerenciar');
$pdo = Database::connection();
$error = '';

$perfis = $pdo->query('SELECT id, nome, slug FROM perfis ORDER BY id')->fetchAll();
$perfilId = isset($_GET['perfil']) ? (int)$_GET['perfil'] : (int)($perfis[0]['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $perfilId = (int)($_POST['perfil_id'] ?? 0);
}

$perfilAtual = null;
foreach ($perfis as $perfil) {
    if ((int)$perfil['id'] === $perfilId) {
        $perfilAtual = $perfil;
        break;
    }
}
if (!$perfilAtual && $perfis) {
    $perfilAtual = $perfis[0];
    $perfilId = (int)$perfilAtual['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } elseif (!$perfilAtual) {
        $error = 'Perfil inválido.';
    } elseif ($perfilAtual['slug'] === 'administrador') {
        $error = 'O perfil Administrador possui acesso total permanente e não pode ser restringido.';
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['permissoes'] ?? [])))));
        $validIds = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id FROM permissoes WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :perfil_id')->execute(['perfil_id' => $perfilId]);
            if ($validIds) {
                $insert = $pdo->prepare('INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:perfil_id, :permissao_id)');
                foreach ($validIds as $permissaoId) {
                    $insert->execute(['perfil_id' => $perfilId, 'permissao_id' => $permissaoId]);
                }
            }
            $pdo->commit();
            logAction($pdo, 'permissoes.editar', 'perfis', $perfilId, implode(',', $validIds));
            Session::flash('success', 'Permissões do perfil atualizadas. As novas regras serão aplicadas no próximo acesso dos usuários desse perfil.');
            header('Location: ' . url('admin/perfis/index.php?perfil=' . $perfilId));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Não foi possível atualizar as permissões: ' . $e->getMessage();
        }
    }
}

$permissoes = $pdo->query('SELECT id, nome, slug, grupo, descricao FROM permissoes ORDER BY grupo, ordem, id')->fetchAll();
$selecionadas = [];
if ($perfilAtual && $perfilAtual['slug'] === 'administrador') {
    $selecionadas = array_map(fn($p) => (int)$p['id'], $permissoes);
} elseif ($perfilId) {
    $stmt = $pdo->prepare('SELECT permissao_id FROM perfil_permissoes WHERE perfil_id = :perfil_id');
    $stmt->execute(['perfil_id' => $perfilId]);
    $selecionadas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$grupos = [];
foreach ($permissoes as $permissao) {
    $grupos[$permissao['grupo']][] = $permissao;
}

$pageTitle = 'Perfis e Permissões';
require __DIR__ . '/../_header.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Perfis e Permissões</h1>
    <p class="text-secondary mb-0">Defina quais módulos cada perfil pode administrar.</p>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4" id="funcoes">
    <div class="col-lg-3">
        <div class="list-group shadow-sm">
            <?php foreach ($perfis as $perfil): ?>
                <a class="list-group-item list-group-item-action <?= (int)$perfil['id'] === $perfilId ? 'active' : '' ?>" href="<?= e(url('admin/perfis/index.php?perfil=' . (int)$perfil['id'])) ?>">
                    <?= e($perfil['nome']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-9">
        <form method="post" class="card border-0 shadow-sm" id="permissoes">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold"><?= e($perfilAtual['nome'] ?? '') ?></div>
                <?php if (($perfilAtual['slug'] ?? '') === 'administrador'): ?><div class="small text-secondary">Este perfil sempre possui acesso total.</div><?php endif; ?>
            </div>
            <div class="card-body p-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="perfil_id" value="<?= (int)$perfilId ?>">

                <?php foreach ($grupos as $grupo => $items): ?>
                    <div class="mb-4">
                        <h2 class="h6 border-bottom pb-2"><?= e($grupo) ?></h2>
                        <div class="row g-3">
                            <?php foreach ($items as $permissao): ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissoes[]" value="<?= (int)$permissao['id'] ?>" id="perm<?= (int)$permissao['id'] ?>" <?= in_array((int)$permissao['id'], $selecionadas, true) ? 'checked' : '' ?> <?= ($perfilAtual['slug'] ?? '') === 'administrador' ? 'disabled' : '' ?>>
                                        <label class="form-check-label" for="perm<?= (int)$permissao['id'] ?>">
                                            <span class="fw-semibold"><?= e($permissao['nome']) ?></span>
                                            <?php if ($permissao['descricao']): ?><span class="d-block small text-secondary"><?= e($permissao['descricao']) ?></span><?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (($perfilAtual['slug'] ?? '') !== 'administrador'): ?>
                    <button class="btn btn-primary">Salvar permissões</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
