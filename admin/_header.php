<?php
Auth::requireLogin();
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Painel') ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('public/css/admin.css')) ?>">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="<?= e(url('admin/index.php')) ?>">IECLB Parobé</a>
        <div class="d-flex align-items-center gap-2 text-white small">
            <a class="text-white text-decoration-none" href="<?= e(url('admin/minha-conta.php')) ?>" title="Minha conta">
                <?= e($user['nome'] ?? '') ?> · <?= e($user['perfil_nome'] ?? '') ?>
            </a>
            <a class="btn btn-outline-light btn-sm" href="<?= e(url('admin/logout.php')) ?>">Sair</a>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <div class="row">
        <aside class="col-md-3 col-lg-2 p-0 bg-white border-end min-vh-100">
            <div class="list-group list-group-flush pt-3">
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/index.php')) ?>">Dashboard</a>
                <?php if (Auth::can('noticias.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/noticias/index.php')) ?>">Notícias</a>
                <?php endif; ?>
                <?php if (Auth::can('paginas.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/paginas/index.php')) ?>">Páginas</a>
                <?php endif; ?>
                <?php if (Auth::can('eventos.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/eventos/index.php')) ?>">Eventos e Cultos</a>
                <?php endif; ?>
                <?php if (Auth::can('midias.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/midias/index.php')) ?>">Mídia</a>
                <?php endif; ?>
                <?php if (Auth::can('comunidades.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/comunidades/index.php')) ?>">Comunidades</a>
                <?php endif; ?>
                <?php if (Auth::can('usuarios.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/usuarios/index.php')) ?>">Usuários</a>
                <?php endif; ?>
                <?php if (Auth::can('permissoes.gerenciar')): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/perfis/index.php')) ?>">Perfis e Permissões</a>
                <?php endif; ?>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/minha-conta.php')) ?>">Minha conta</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url()) ?>" target="_blank">Ver portal</a>
            </div>
            <div class="px-3 py-3 small text-secondary border-top mt-3">
                Portal v<?= e(defined('APP_VERSION') ? (string)APP_VERSION : '0.5.0') ?>
            </div>
        </aside>
        <main class="col-md-9 col-lg-10 p-4">
            <?php if ($msg = Session::flash('success')): ?>
                <div class="alert alert-success"><?= e($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = Session::flash('error')): ?>
                <div class="alert alert-danger"><?= e($msg) ?></div>
            <?php endif; ?>
