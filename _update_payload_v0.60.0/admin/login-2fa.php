<?php

require_once __DIR__ . '/../bootstrap.php';

if (Auth::check()) {
    header('Location: ' . url('admin/index.php'));
    exit;
}

if (!Auth::twoFactorPending()) {
    Session::flash(
        'error',
        Auth::lastError() ?: 'Entre novamente para continuar.'
    );

    header('Location: ' . url('admin/login.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? 'verify');

        if ($action === 'cancel') {
            Auth::cancelTwoFactor();
            header('Location: ' . url('admin/login.php'));
            exit;
        }

        $code = trim((string)($_POST['codigo'] ?? ''));

        if ($code === '') {
            $error = 'Informe o código do aplicativo ou um código de recuperação.';
        } elseif (Auth::completeTwoFactor($code)) {
            header('Location: ' . url('admin/index.php'));
            exit;
        } else {
            $error = Auth::lastError() ?: 'Código não aceito.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificação em dois fatores - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="text-primary small fw-semibold text-uppercase mb-2">
                        Segunda etapa
                    </div>

                    <h1 class="h4 mb-2">
                        Verificação em dois fatores
                    </h1>

                    <p class="text-secondary mb-4">
                        Digite o código de 6 dígitos do seu aplicativo autenticador.
                        Você também pode usar um código de recuperação.
                    </p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="verify">

                        <div class="mb-3">
                            <label class="form-label">
                                Código
                            </label>

                            <input
                                type="text"
                                name="codigo"
                                class="form-control form-control-lg text-center"
                                placeholder="123456"
                                autocomplete="one-time-code"
                                inputmode="text"
                                maxlength="20"
                                required
                                autofocus
                            >

                            <div class="form-text">
                                Códigos do autenticador mudam a cada 30 segundos.
                            </div>
                        </div>

                        <button class="btn btn-primary w-100">
                            Verificar e entrar
                        </button>
                    </form>

                    <form method="post" class="mt-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="cancel">

                        <button
                            type="submit"
                            class="btn btn-link text-secondary w-100 text-decoration-none"
                        >
                            Voltar e entrar com outra conta
                        </button>
                    </form>
                </div>
            </div>

            <div class="text-center small text-secondary mt-3">
                O segundo fator nunca é enviado por e-mail.
            </div>
        </div>
    </div>
</div>
</body>
</html>
