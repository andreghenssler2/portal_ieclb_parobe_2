<?php

require_once __DIR__ . '/../bootstrap.php';

if (Auth::check()) {
    header('Location: ' . url('admin/index.php'));
    exit;
}

if (Auth::twoFactorPending()) {
    header('Location: ' . url('admin/login-2fa.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } elseif (
        Auth::attempt(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['senha'] ?? '')
        )
    ) {
        if (Auth::twoFactorPending()) {
            header('Location: ' . url('admin/login-2fa.php'));
        } else {
            header('Location: ' . url('admin/index.php'));
        }

        exit;
    } else {
        $error = Auth::lastError() ?: 'E-mail ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Portal IECLB Parobé</h1>
                    <p class="text-secondary mb-4">Acesso administrativo</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post" autocomplete="on">
                        <?= Csrf::field() ?>

                        <div class="mb-3">
                            <label class="form-label">E-mail</label>
                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                autocomplete="username"
                                required
                                autofocus
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Senha</label>
                            <input
                                type="password"
                                name="senha"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button class="btn btn-primary w-100">
                            Entrar
                        </button>
                    </form>
                </div>
            </div>

            <div class="text-center small text-secondary mt-3">
                Segurança do painel · v<?= e(defined('APP_VERSION') ? (string)APP_VERSION : '') ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
