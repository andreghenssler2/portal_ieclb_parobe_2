<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('usuarios.gerenciar');
$pdo = Database::connection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$usuario = ['nome' => '', 'email' => '', 'perfil_id' => '', 'ativo' => 1];
$original = null;

if ($id) {
    $stmt = $pdo->prepare(
        'SELECT u.*, p.slug AS perfil_slug, p.nome AS perfil_nome
         FROM usuarios u
         INNER JOIN perfis p ON p.id = u.perfil_id
         WHERE u.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $original = $stmt->fetch();
    if (!$original) {
        http_response_code(404);
        exit('Usuário não encontrado.');
    }
    $usuario = $original;
}

$perfis = $pdo->query('SELECT id, nome, slug FROM perfis ORDER BY id')->fetchAll();
$perfilById = [];
foreach ($perfis as $perfil) {
    $perfilById[(int)$perfil['id']] = $perfil;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario['nome'] = trim((string)($_POST['nome'] ?? ''));
    $usuario['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $usuario['perfil_id'] = (int)($_POST['perfil_id'] ?? 0);
    $usuario['ativo'] = isset($_POST['ativo']) ? 1 : 0;
    $senha = (string)($_POST['senha'] ?? '');
    $senhaConfirmacao = (string)($_POST['senha_confirmacao'] ?? '');

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } elseif ($usuario['nome'] === '' || $usuario['email'] === '') {
        $error = 'Nome e e-mail são obrigatórios.';
    } elseif (!filter_var($usuario['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido.';
    } elseif (!isset($perfilById[(int)$usuario['perfil_id']])) {
        $error = 'Selecione um perfil válido.';
    } elseif (!$id && $senha === '') {
        $error = 'Defina uma senha para o novo usuário.';
    } elseif ($senha !== '' && strlen($senha) < 8) {
        $error = 'A senha deve possuir pelo menos 8 caracteres.';
    } elseif ($senha !== $senhaConfirmacao) {
        $error = 'A confirmação da senha não confere.';
    } elseif ($id === Auth::id() && (int)$usuario['ativo'] !== 1) {
        $error = 'Você não pode desativar sua própria conta.';
    } elseif ($id === Auth::id() && $original && (int)$usuario['perfil_id'] !== (int)$original['perfil_id']) {
        $error = 'Para evitar perda de acesso, você não pode alterar o seu próprio perfil por esta tela.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email' . ($id ? ' AND id <> :id' : '') . ' LIMIT 1');
        $params = ['email' => $usuario['email']];
        if ($id) $params['id'] = $id;
        $stmt->execute($params);
        if ($stmt->fetch()) {
            $error = 'Já existe um usuário cadastrado com este e-mail.';
        }
    }

    if ($error === '' && $id && $original && $original['perfil_slug'] === 'administrador' && (int)$original['ativo'] === 1) {
        $newProfile = $perfilById[(int)$usuario['perfil_id']]['slug'] ?? '';
        if ($newProfile !== 'administrador' || (int)$usuario['ativo'] !== 1) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM usuarios u
                 INNER JOIN perfis p ON p.id = u.perfil_id
                 WHERE u.ativo = 1 AND p.slug = 'administrador' AND u.id <> :id"
            );
            $stmt->execute(['id' => $id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $error = 'Não é possível remover ou desativar o último administrador ativo.';
            }
        }
    }

    if ($error === '') {
        try {
            if ($id) {
                $sql = 'UPDATE usuarios SET nome = :nome, email = :email, perfil_id = :perfil_id, ativo = :ativo';
                $params = [
                    'nome' => $usuario['nome'],
                    'email' => $usuario['email'],
                    'perfil_id' => $usuario['perfil_id'],
                    'ativo' => $usuario['ativo'],
                    'id' => $id,
                ];
                if ($senha !== '') {
                    $sql .= ', senha = :senha';
                    $params['senha'] = password_hash($senha, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id = :id';
                $pdo->prepare($sql)->execute($params);
                logAction($pdo, 'usuario.editar', 'usuarios', $id);
                if ($id === Auth::id()) Auth::refresh();
                Session::flash('success', 'Usuário atualizado com sucesso.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO usuarios (perfil_id, nome, email, senha, ativo)
                     VALUES (:perfil_id, :nome, :email, :senha, :ativo)'
                );
                $stmt->execute([
                    'perfil_id' => $usuario['perfil_id'],
                    'nome' => $usuario['nome'],
                    'email' => $usuario['email'],
                    'senha' => password_hash($senha, PASSWORD_DEFAULT),
                    'ativo' => $usuario['ativo'],
                ]);
                $id = (int)$pdo->lastInsertId();
                logAction($pdo, 'usuario.criar', 'usuarios', $id);
                Session::flash('success', 'Usuário criado com sucesso.');
            }
            header('Location: ' . url('admin/usuarios/index.php'));
            exit;
        } catch (Throwable $e) {
            $error = 'Não foi possível salvar o usuário: ' . $e->getMessage();
        }
    }
}

$pageTitle = $id ? 'Editar usuário' : 'Novo usuário';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="h3 mb-1"><?= e($pageTitle) ?></h1><p class="text-secondary mb-0">Defina os dados de acesso e o perfil administrativo.</p></div>
</div>
<?php if ($id): ?>
<div class="mb-4">
    <a
        class="btn btn-outline-primary"
        href="<?= e(url('admin/usuarios/atividade.php?id=' . (int)$id)) ?>"
    >
        <i class="bi bi-clock-history me-1"></i>
        Histórico de atividade
    </a>
</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nome</label>
                <input class="form-control" name="nome" value="<?= e((string)$usuario['nome']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">E-mail</label>
                <input type="email" class="form-control" name="email" value="<?= e((string)$usuario['email']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Perfil</label>
                <select class="form-select" name="perfil_id" required <?= $id === Auth::id() ? 'disabled' : '' ?>>
                    <option value="">Selecione</option>
                    <?php foreach ($perfis as $perfil): ?>
                        <option value="<?= (int)$perfil['id'] ?>" <?= (string)$usuario['perfil_id'] === (string)$perfil['id'] ? 'selected' : '' ?>><?= e($perfil['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id === Auth::id()): ?>
                    <input type="hidden" name="perfil_id" value="<?= (int)$usuario['perfil_id'] ?>">
                    <div class="form-text">Seu próprio perfil não pode ser alterado por esta tela.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="ativo" id="ativo" <?= (int)$usuario['ativo'] === 1 ? 'checked' : '' ?> <?= $id === Auth::id() ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="ativo">Usuário ativo</label>
                    <?php if ($id === Auth::id()): ?><input type="hidden" name="ativo" value="1"><?php endif; ?>
                </div>
            </div>
            <div class="col-12"><hr><h2 class="h6">Senha</h2><p class="small text-secondary mb-0"><?= $id ? 'Deixe em branco para manter a senha atual.' : 'Informe uma senha inicial para o usuário.' ?></p></div>
            <div class="col-md-6">
                <label class="form-label"><?= $id ? 'Nova senha' : 'Senha' ?></label>
                <input type="password" class="form-control" name="senha" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Confirmar senha</label>
                <input type="password" class="form-control" name="senha_confirmacao" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Salvar</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('admin/usuarios/index.php')) ?>">Cancelar</a>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../_footer.php'; ?>
