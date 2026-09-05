<?php
require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo = Database::connection();
$id = (int)Auth::id();
$error = '';

$stmt = $pdo->prepare(
    'SELECT
        u.id,
        u.nome,
        u.email,
        u.ultimo_login,
        u.totp_enabled_at,
        p.nome AS perfil_nome
     FROM usuarios u
     INNER JOIN perfis p ON p.id = u.perfil_id
     WHERE u.id = :id
     LIMIT 1'
);

$stmt->execute([
    'id' => $id,
]);

$usuario = $stmt->fetch();

if (!$usuario) {
    Auth::logout();
    header('Location: ' . url('admin/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim((string)($_POST['nome'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $senhaAtual = (string)($_POST['senha_atual'] ?? '');
    $novaSenha = (string)($_POST['nova_senha'] ?? '');
    $confirmacao = (string)($_POST['nova_senha_confirmacao'] ?? '');

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } elseif (
        $nome === ''
        || !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $error = 'Informe nome e e-mail válidos.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM usuarios
             WHERE email = :email
               AND id <> :id
             LIMIT 1'
        );

        $stmt->execute([
            'email' => $email,
            'id' => $id,
        ]);

        if ($stmt->fetch()) {
            $error =
                'Este e-mail já está sendo utilizado por outro usuário.';
        }
    }

    if ($error === '' && $novaSenha !== '') {
        if (strlen($novaSenha) < 8) {
            $error =
                'A nova senha deve possuir pelo menos 8 caracteres.';
        } elseif ($novaSenha !== $confirmacao) {
            $error =
                'A confirmação da nova senha não confere.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT senha
                 FROM usuarios
                 WHERE id = :id'
            );

            $stmt->execute([
                'id' => $id,
            ]);

            $hash = (string)$stmt->fetchColumn();

            if (
                $senhaAtual === ''
                || !password_verify(
                    $senhaAtual,
                    $hash
                )
            ) {
                $error =
                    'Informe corretamente a senha atual para definir uma nova senha.';
            }
        }
    }

    if ($error === '') {
        $sql =
            'UPDATE usuarios
             SET nome = :nome,
                 email = :email';

        $params = [
            'nome' => $nome,
            'email' => $email,
            'id' => $id,
        ];

        if ($novaSenha !== '') {
            $sql .= ', senha = :senha';

            $params['senha'] = password_hash(
                $novaSenha,
                PASSWORD_DEFAULT
            );
        }

        $sql .= ' WHERE id = :id';

        $pdo->prepare($sql)->execute($params);
        if (
            $novaSenha !== ''
            && class_exists('SessionSecurityService')
        ) {
            try {
                SessionSecurityService::revokeOtherSessions(
                    $pdo,
                    $id,
                    $id,
                    'senha_alterada_encerrar_sessoes'
                );
            } catch (Throwable $ignored) {
            }
        }

        logAction(
            $pdo,
            'conta.editar',
            'usuarios',
            $id,
            $novaSenha !== ''
                ? 'Dados e senha atualizados'
                : 'Dados atualizados'
        );

        Auth::refresh();

        Session::flash(
            'success',
            'Sua conta foi atualizada.'
        );

        header(
            'Location: '
            . url('admin/minha-conta.php')
        );

        exit;
    }

    $usuario['nome'] = $nome;
    $usuario['email'] = $email;
}

$twoFactorEnabled = TwoFactorService::isEnabled(
    $pdo,
    $id
);

$recoveryRemaining = $twoFactorEnabled
    ? TwoFactorService::recoveryCodesRemaining(
        $pdo,
        $id
    )
    : 0;

$pageTitle = 'Minha conta';

require __DIR__ . '/_header.php';
?>

<div class="mb-4">
    <h1 class="h3 mb-1">Minha conta</h1>
    <p class="text-secondary mb-0">
        Atualize seus dados pessoais, senha e proteção do acesso.
    </p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <?= Csrf::field() ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome</label>
                        <input
                            class="form-control"
                            name="nome"
                            value="<?= e($usuario['nome']) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            name="email"
                            value="<?= e($usuario['email']) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Perfil</label>
                        <input
                            class="form-control"
                            value="<?= e($usuario['perfil_nome']) ?>"
                            disabled
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Último acesso</label>
                        <input
                            class="form-control"
                            value="<?= e(
                                $usuario['ultimo_login']
                                    ? formatDateBr($usuario['ultimo_login'])
                                    : 'Primeiro acesso'
                            ) ?>"
                            disabled
                        >
                    </div>

                    <div class="col-12">
                        <hr>
                        <h2 class="h6">Alterar senha</h2>
                        <p class="small text-secondary mb-0">
                            Preencha somente se desejar trocar a senha atual.
                        </p>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Senha atual</label>
                        <input
                            type="password"
                            class="form-control"
                            name="senha_atual"
                            autocomplete="current-password"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Nova senha</label>
                        <input
                            type="password"
                            class="form-control"
                            name="nova_senha"
                            minlength="8"
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Confirmar nova senha</label>
                        <input
                            type="password"
                            class="form-control"
                            name="nova_senha_confirmacao"
                            minlength="8"
                            autocomplete="new-password"
                        >
                    </div>
                </div>

                <div class="mt-4">
                    <button class="btn btn-primary">
                        Salvar alterações
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <section class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div class="fs-2 <?= $twoFactorEnabled ? 'text-success' : 'text-secondary' ?>">
                        <i class="bi <?= $twoFactorEnabled ? 'bi-shield-check' : 'bi-shield-lock' ?>"></i>
                    </div>

                    <div>
                        <h2 class="h5 mb-1">
                            Autenticação em dois fatores
                        </h2>

                        <?php if ($twoFactorEnabled): ?>
                            <div class="badge text-bg-success mb-2">
                                Ativa
                            </div>

                            <p class="text-secondary">
                                Seu acesso exige senha e código do autenticador.
                                Restam <?= (int)$recoveryRemaining ?> código(s)
                                de recuperação.
                            </p>
                        <?php else: ?>
                            <div class="badge text-bg-secondary mb-2">
                                Desativada
                            </div>

                            <p class="text-secondary">
                                Proteja sua conta com um código temporário
                                além da senha.
                            </p>
                        <?php endif; ?>

                        <a
                            class="btn <?= $twoFactorEnabled ? 'btn-outline-success' : 'btn-primary' ?>"
                            href="<?= e(url('admin/minha-conta-2fa.php')) ?>"
                        >
                            <?= $twoFactorEnabled ? 'Gerenciar 2FA' : 'Ativar 2FA' ?>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
