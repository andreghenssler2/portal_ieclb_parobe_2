<?php

require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo = Database::connection();
$userId = (int)Auth::id();
$error = '';

$stmt = $pdo->prepare(
    'SELECT id, nome, email, senha, totp_enabled_at
     FROM usuarios
     WHERE id=:id
     LIMIT 1'
);
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    Auth::logout();
    header('Location: ' . url('admin/login.php'));
    exit;
}

$enabled = TwoFactorService::isEnabled($pdo, $userId);
$remainingCodes = $enabled
    ? TwoFactorService::recoveryCodesRemaining($pdo, $userId)
    : 0;

$setup = $_SESSION['_2fa_setup'] ?? null;

if (
    !$enabled
    && (
        !is_array($setup)
        || (int)($setup['user_id'] ?? 0) !== $userId
        || (time() - (int)($setup['created_at'] ?? 0)) > 900
        || empty($setup['secret'])
    )
) {
    $setup = [
        'user_id' => $userId,
        'secret' => TwoFactorService::generateSecret(),
        'created_at' => time(),
    ];

    $_SESSION['_2fa_setup'] = $setup;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $currentPassword = (string)($_POST['senha_atual'] ?? '');
        $code = trim((string)($_POST['codigo'] ?? ''));

        if (
            $currentPassword === ''
            || !password_verify(
                $currentPassword,
                (string)$user['senha']
            )
        ) {
            $error = 'Informe corretamente sua senha atual.';
        } elseif ($action === 'enable') {
            if ($enabled) {
                $error = 'O 2FA já está ativo.';
            } elseif (!is_array($setup) || empty($setup['secret'])) {
                $error = 'A configuração expirou. Atualize a página e tente novamente.';
            } elseif ($code === '') {
                $error = 'Informe o código gerado pelo aplicativo autenticador.';
            } else {
                try {
                    $codes = TwoFactorService::enable(
                        $pdo,
                        $userId,
                        (string)$setup['secret'],
                        $code
                    );

                    unset($_SESSION['_2fa_setup']);

                    $_SESSION['_2fa_recovery_codes'] = $codes;

                    logAction(
                        $pdo,
                        'conta.2fa.ativar',
                        'usuarios',
                        $userId,
                        'Autenticação em dois fatores ativada.'
                    );

                    Session::flash(
                        'success',
                        'Autenticação em dois fatores ativada.'
                    );

                    header(
                        'Location: '
                        . url('admin/minha-conta-2fa.php')
                    );

                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'disable') {
            if (!$enabled) {
                $error = 'O 2FA não está ativo.';
            } elseif ($code === '') {
                $error = 'Informe o código do autenticador ou um código de recuperação.';
            } elseif (
                !TwoFactorService::verifyUserCode(
                    $pdo,
                    $userId,
                    $code
                )
            ) {
                $error = 'O código informado não foi aceito.';
            } else {
                TwoFactorService::disable(
                    $pdo,
                    $userId
                );

                logAction(
                    $pdo,
                    'conta.2fa.desativar',
                    'usuarios',
                    $userId,
                    'Autenticação em dois fatores desativada.',
                    'warning'
                );

                Session::flash(
                    'success',
                    'Autenticação em dois fatores desativada.'
                );

                header(
                    'Location: '
                    . url('admin/minha-conta-2fa.php')
                );

                exit;
            }
        } elseif ($action === 'recovery') {
            if (!$enabled) {
                $error = 'Ative o 2FA antes de gerar códigos de recuperação.';
            } elseif ($code === '') {
                $error = 'Informe o código atual do autenticador.';
            } elseif (
                !TwoFactorService::verifyUserTotp(
                    $pdo,
                    $userId,
                    $code
                )
            ) {
                $error = 'O código atual do autenticador não foi aceito.';
            } else {
                try {
                    $codes = TwoFactorService::regenerateRecoveryCodes(
                        $pdo,
                        $userId
                    );

                    $_SESSION['_2fa_recovery_codes'] = $codes;

                    logAction(
                        $pdo,
                        'conta.2fa.recuperacao.regenerar',
                        'usuarios',
                        $userId,
                        'Novos códigos de recuperação do 2FA foram gerados.'
                    );

                    Session::flash(
                        'success',
                        'Novos códigos de recuperação foram gerados. Os antigos foram invalidados.'
                    );

                    header(
                        'Location: '
                        . url('admin/minha-conta-2fa.php')
                    );

                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        } else {
            $error = 'Ação inválida.';
        }
    }
}

$enabled = TwoFactorService::isEnabled($pdo, $userId);
$remainingCodes = $enabled
    ? TwoFactorService::recoveryCodesRemaining($pdo, $userId)
    : 0;

$recoveryCodes = $_SESSION['_2fa_recovery_codes'] ?? [];
unset($_SESSION['_2fa_recovery_codes']);

$pageTitle = 'Autenticação em dois fatores';

require __DIR__ . '/_header.php';

$issuer = siteConfig(
    $pdo,
    'site_nome',
    defined('APP_NAME') ? APP_NAME : 'Portal IECLB Parobé'
);

$secret = !$enabled && is_array($setup)
    ? (string)($setup['secret'] ?? '')
    : '';

$otpauthUri = $secret !== ''
    ? TwoFactorService::otpauthUri(
        $secret,
        (string)$user['email'],
        $issuer
    )
    : '';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Autenticação em dois fatores</h1>
        <p class="text-secondary mb-0">
            Adicione uma segunda etapa de segurança ao seu acesso administrativo.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/minha-conta.php')) ?>"
    >
        Voltar para Minha conta
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($recoveryCodes): ?>
    <div class="alert alert-warning shadow-sm">
        <h2 class="h5">Salve seus códigos de recuperação agora</h2>

        <p class="mb-3">
            Cada código funciona apenas uma vez. Guarde-os em um local seguro
            fora do portal. Eles não serão exibidos novamente.
        </p>

        <div class="row g-2">
            <?php foreach ($recoveryCodes as $recoveryCode): ?>
                <div class="col-sm-6 col-lg-4">
                    <code class="d-block bg-white border rounded p-2 text-dark">
                        <?= e((string)$recoveryCode) ?>
                    </code>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$enabled): ?>

    <div class="row g-4">
        <div class="col-xl-7">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <div class="fs-2 text-primary">
                            <i class="bi bi-shield-lock"></i>
                        </div>

                        <div>
                            <h2 class="h5 mb-1">1. Configure seu aplicativo</h2>
                            <p class="text-secondary mb-0">
                                Adicione uma conta TOTP no Google Authenticator,
                                Microsoft Authenticator, 2FAS ou aplicativo compatível.
                            </p>
                        </div>
                    </div>

                    <label class="form-label fw-semibold">
                        Chave manual
                    </label>

                    <div class="input-group mb-3">
                        <input
                            class="form-control font-monospace"
                            id="twoFactorSecret"
                            value="<?= e($secret) ?>"
                            readonly
                        >

                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            onclick="navigator.clipboard?.writeText(document.getElementById('twoFactorSecret').value)"
                        >
                            Copiar
                        </button>
                    </div>

                    <div class="small text-secondary mb-3">
                        Tipo: <strong>Baseado em tempo (TOTP)</strong> ·
                        6 dígitos · 30 segundos
                    </div>

                    <?php if ($otpauthUri !== ''): ?>
                        <a
                            class="btn btn-outline-primary"
                            href="<?= e($otpauthUri) ?>"
                        >
                            Abrir no aplicativo autenticador
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">
                        2. Confirme a ativação
                    </h2>

                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="enable">

                        <div class="mb-3">
                            <label class="form-label">
                                Sua senha atual
                            </label>

                            <input
                                type="password"
                                name="senha_atual"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Código de 6 dígitos
                            </label>

                            <input
                                type="text"
                                name="codigo"
                                class="form-control"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                required
                            >
                        </div>

                        <button class="btn btn-primary w-100">
                            Ativar autenticação em dois fatores
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>

<?php else: ?>

    <div class="alert alert-success d-flex align-items-start gap-3">
        <i class="bi bi-shield-check fs-3"></i>
        <div>
            <div class="fw-semibold">
                Autenticação em dois fatores ativa
            </div>
            <div>
                Seu login exige senha + código do autenticador.
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5">Códigos de recuperação</h2>

                    <p class="text-secondary">
                        Restam <strong><?= (int)$remainingCodes ?></strong>
                        código(s) de recuperação não utilizados.
                    </p>

                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="recovery">

                        <div class="mb-3">
                            <label class="form-label">
                                Sua senha atual
                            </label>

                            <input
                                type="password"
                                name="senha_atual"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Código atual do autenticador
                            </label>

                            <input
                                type="text"
                                name="codigo"
                                class="form-control"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                required
                            >
                        </div>

                        <button class="btn btn-outline-primary">
                            Gerar novos códigos
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="card border-danger shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5 text-danger">
                        Desativar 2FA
                    </h2>

                    <p class="text-secondary">
                        A conta voltará a exigir somente e-mail e senha.
                    </p>

                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="disable">

                        <div class="mb-3">
                            <label class="form-label">
                                Sua senha atual
                            </label>

                            <input
                                type="password"
                                name="senha_atual"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Código do autenticador ou recuperação
                            </label>

                            <input
                                type="text"
                                name="codigo"
                                class="form-control"
                                autocomplete="one-time-code"
                                maxlength="20"
                                required
                            >
                        </div>

                        <button
                            class="btn btn-outline-danger"
                            onclick="return confirm('Desativar a autenticação em dois fatores desta conta?')"
                        >
                            Desativar autenticação em dois fatores
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
