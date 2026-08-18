<?php
$menuPaginas = [];
try {
    $menuPdo = $pdo ?? Database::connection();
    $menuPaginas = $menuPdo->query(
        "SELECT titulo, slug
         FROM paginas
         WHERE status = 'publicado'
           AND exibir_menu = 1
           AND (publicado_em IS NULL OR publicado_em <= NOW())
         ORDER BY ordem ASC, titulo ASC"
    )->fetchAll();
} catch (Throwable $e) {
    // Mantém o portal acessível mesmo antes da migração da v0.3.0 ser executada.
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($metaTitle ?? APP_NAME) ?></title>
    <meta name="description" content="<?= e($metaDescription ?? 'Portal da IECLB Parobé') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('public/css/site.css')) ?>">
</head>
<body>
<header class="border-bottom bg-white">
    <nav class="navbar navbar-expand-lg container py-3">
        <a class="navbar-brand fw-bold" href="<?= e(url()) ?>">IECLB Parobé</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu"><span class="navbar-toggler-icon"></span></button>
        <div id="menu" class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="<?= e(url()) ?>">Início</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('agenda.php')) ?>">Agenda</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('comunidades.php')) ?>">Comunidades</a></li>
                <?php foreach ($menuPaginas as $menuPagina): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('pagina.php?slug=' . urlencode($menuPagina['slug']))) ?>"><?= e($menuPagina['titulo']) ?></a></li>
                <?php endforeach; ?>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('admin/login.php')) ?>">Área administrativa</a></li>
            </ul>
        </div>
    </nav>
</header>
<main>
