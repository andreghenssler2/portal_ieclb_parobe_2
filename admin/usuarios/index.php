<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('usuarios.gerenciar');
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'toggle' && $id > 0) {
            $stmt = $pdo->prepare(
                'SELECT u.id, u.ativo, p.slug AS perfil_slug
                 FROM usuarios u
                 INNER JOIN perfis p ON p.id = u.perfil_id
                 WHERE u.id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $target = $stmt->fetch();

            if (!$target) {
                $error = 'Usuário não encontrado.';
            } elseif ($id === Auth::id() && (int)$target['ativo'] === 1) {
                $error = 'Você não pode desativar sua própria conta.';
            } elseif ($target['perfil_slug'] === 'administrador' && (int)$target['ativo'] === 1) {
                $count = (int)$pdo->query(
                    "SELECT COUNT(*)
                     FROM usuarios u
                     INNER JOIN perfis p ON p.id = u.perfil_id
                     WHERE u.ativo = 1 AND p.slug = 'administrador'"
                )->fetchColumn();
                if ($count <= 1) {
                    $error = 'Não é possível desativar o último administrador ativo.';
                }
            }

            if ($error === '' && $target) {
                $newStatus = (int)$target['ativo'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE usuarios SET ativo = :ativo WHERE id = :id')
                    ->execute(['ativo' => $newStatus, 'id' => $id]);
                logAction($pdo, $newStatus ? 'usuario.ativar' : 'usuario.desativar', 'usuarios', $id);
                Session::flash('success', $newStatus ? 'Usuário ativado.' : 'Usuário desativado.');
                header('Location: ' . url('admin/usuarios/index.php'));
                exit;
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE u.nome LIKE :q OR u.email LIKE :q OR p.nome LIKE :q';
    $params['q'] = '%' . $q . '%';
}

$stmt = $pdo->prepare(
    "SELECT u.id, u.nome, u.email, u.ativo, u.ultimo_login, u.created_at,
            p.nome AS perfil_nome, p.slug AS perfil_slug
     FROM usuarios u
     INNER JOIN perfis p ON p.id = u.perfil_id
     $where
     ORDER BY u.ativo DESC, u.nome ASC"
);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$pageTitle = 'Usuários';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Usuários</h1>
        <p class="text-secondary mb-0">Gerencie as contas que acessam o painel administrativo.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/usuarios/form.php')) ?>">Novo usuário</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-md-8 col-lg-5">
                <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, e-mail ou perfil">
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary">Buscar</button></div>
            <?php if ($q !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="<?= e(url('admin/usuarios/index.php')) ?>">Limpar</a></div><?php endif; ?>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Usuário</th><th>Perfil</th><th>Status</th><th>Último acesso</th><th></th></tr></thead>
            <tbody>
            <?php if (!$usuarios): ?><tr><td colspan="5" class="text-secondary">Nenhum usuário encontrado.</td></tr><?php endif; ?>
            <?php foreach ($usuarios as $usuario): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($usuario['nome']) ?></div>
                        <div class="small text-secondary"><?= e($usuario['email']) ?></div>
                    </td>
                    <td><?= e($usuario['perfil_nome']) ?></td>
                    <td>
                        <?php if ((int)$usuario['ativo'] === 1): ?><span class="badge text-bg-success">Ativo</span>
                        <?php else: ?><span class="badge text-bg-secondary">Inativo</span><?php endif; ?>
                    </td>
                    <td><?= $usuario['ultimo_login'] ? e(formatDateBr($usuario['ultimo_login'])) : '<span class="text-secondary">Nunca acessou</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/usuarios/form.php?id=' . (int)$usuario['id'])) ?>">Editar</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Confirma esta alteração de status?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
                            <button class="btn btn-sm <?= (int)$usuario['ativo'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" <?= (int)$usuario['id'] === Auth::id() && (int)$usuario['ativo'] === 1 ? 'disabled' : '' ?>>
                                <?= (int)$usuario['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
