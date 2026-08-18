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
        <div class="d-flex align-items-center gap-3 text-white small">
            <span><?= e($user['nome'] ?? '') ?> · <?= e($user['perfil_nome'] ?? '') ?></span>
            <a class="btn btn-outline-light btn-sm" href="<?= e(url('admin/logout.php')) ?>">Sair</a>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <div class="row">
        <aside class="col-md-3 col-lg-2 p-0 bg-white border-end min-vh-100">
            <div class="list-group list-group-flush pt-3">
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/index.php')) ?>">Dashboard</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/noticias/index.php')) ?>">Notícias</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/paginas/index.php')) ?>">Páginas</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/eventos/index.php')) ?>">Eventos e Cultos</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/midias/index.php')) ?>">Mídia</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url('admin/comunidades/index.php')) ?>">Comunidades</a>
                <a class="list-group-item list-group-item-action" href="<?= e(url()) ?>" target="_blank">Ver portal</a>
            </div>
        </aside>
        <main class="col-md-9 col-lg-10 p-4">
            <?php if ($msg = Session::flash('success')): ?>
                <div class="alert alert-success"><?= e($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = Session::flash('error')): ?>
                <div class="alert alert-danger"><?= e($msg) ?></div>
            <?php endif; ?>
