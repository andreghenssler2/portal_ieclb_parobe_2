<?php
Auth::requireLogin();
$user = Auth::user();

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$adminMarker = '/admin/';
$adminPos = strpos($scriptName, $adminMarker);
$currentAdminPath = $adminPos !== false ? ltrim(substr($scriptName, $adminPos + strlen($adminMarker)), '/') : '';

$isPath = static fn(string $path): bool => $currentAdminPath === ltrim($path, '/');
$startsPath = static fn(string $path): bool => str_starts_with($currentAdminPath, trim($path, '/') . '/');

$revisionType = (string)($_GET['tipo'] ?? '');
$postOpen = $startsPath('noticias') || $startsPath('categorias') || $startsPath('tags') || $startsPath('comentarios') || ($startsPath('revisoes') && $revisionType === 'post');
$mediaOpen = $startsPath('midias') || $startsPath('galerias');
$pagesOpen = $startsPath('paginas') || ($startsPath('revisoes') && $revisionType === 'pagina');
$eventsOpen = $startsPath('eventos');
$formsOpen = $startsPath('formularios');
$usersOpen = $startsPath('usuarios') || $startsPath('perfis');
$appearanceOpen = $startsPath('aparencia') || $startsPath('menus') || $startsPath('banners');
$seoOpen = $startsPath('seo');
$configOpen = $startsPath('configuracoes');
$auditOpen = $startsPath('auditoria');
$toolsOpen = $startsPath('ferramentas');
$communitiesOpen = $startsPath('comunidades');
$groupsOpen = $startsPath('grupos');
$leadershipOpen = $startsPath('liderancas');
$documentsOpen = $startsPath('documentos');
$newsletterOpen = $startsPath('newsletter');
$accountOpen = $isPath('minha-conta.php');
$pendingCenterOpen = $isPath('pendencias.php');

$canAppearance = Auth::can('home.gerenciar') || Auth::can('aparencia.gerenciar') || Auth::can('tema_editor.gerenciar') || Auth::can('menus.gerenciar') || Auth::can('banners.gerenciar') || Auth::can('configuracoes.gerenciar');
$pendingComments = 0;
if (Auth::can('comentarios.gerenciar')) {
    try { $pendingComments = (int)Database::connection()->query("SELECT COUNT(*) FROM comentarios co INNER JOIN posts p ON p.id=co.post_id WHERE co.status='pendente' AND p.status <> 'lixeira'")->fetchColumn(); } catch (Throwable $e) {}
}

$canSeePendingCenter =
    Auth::can('noticias.gerenciar')
    || Auth::can('noticias.revisar')
    || Auth::can('noticias.publicar')
    || Auth::can('comentarios.gerenciar')
    || Auth::can('formularios.gerenciar')
    || Auth::can('auditoria.visualizar')
    || Auth::isAdmin();

$adminPendingOverview = [
    'total' => 0,
    'items' => [],
];

if ($canSeePendingCenter) {
    try {
        $adminPendingOverview =
            AdminPendingService::overview(
                Database::connection()
            );
    } catch (Throwable $ignored) {
        $adminPendingOverview = [
            'total' => 0,
            'items' => [],
        ];
    }
}

$adminPendingTotal =
    (int)($adminPendingOverview['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Painel') ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php /* v0.64.0 - CSS administrativo consolidado */ ?>
    <link
        rel="stylesheet"
        href="<?= e(url('public/css/admin-v64.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.64.0'))) ?>"
    >
<!-- Bootstrap JS precisa estar disponível antes dos scripts específicos das páginas. -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="<?= e(url('public/js/admin-menu-v34.js')) ?>"></script>
</head>
<body class="admin-body">
<nav class="navbar navbar-dark admin-topbar sticky-top">
    <div class="container-fluid px-3 px-lg-4">
        <div class="d-flex align-items-center gap-2">
            <button class="admin-menu-trigger d-lg-none" type="button" id="adminMobileMenuToggle" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Abrir menu">
                <i class="bi bi-list fs-3"></i>
            </button>
            <button class="admin-menu-trigger d-none d-lg-inline-flex" type="button" id="adminDesktopMenuToggle" aria-expanded="true" aria-label="Recolher menu administrativo" title="Recolher menu">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand fw-semibold mb-0" href="<?= e(url('admin/index.php')) ?>">
                <span class="admin-brand-mark">IE</span>
                <span>IECLB Parobé</span>
            </a>
        </div>

        <?php if ($canSeePendingCenter): ?>
            <a
                class="btn btn-sm admin-user-button position-relative me-2"
                href="<?= e(url('admin/pendencias.php')) ?>"
                title="Central de Pendências"
                aria-label="Central de Pendências<?= $adminPendingTotal > 0 ? ': ' . (int)$adminPendingTotal . ' item(s)' : '' ?>"
            >
                <i class="bi bi-bell"></i>

                <?php if ($adminPendingTotal > 0): ?>
                    <span
                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger"
                    >
                        <?= $adminPendingTotal > 99 ? '99+' : (int)$adminPendingTotal ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        <button
            type="button"
            class="btn btn-sm admin-user-button admin-global-search-open me-2"
            data-admin-global-search-open
            title="Busca global (Ctrl+K)"
            aria-label="Abrir busca global"
        >
            <i class="bi bi-search"></i>
            <span class="d-none d-xl-inline ms-1">Buscar</span>
            <span class="d-none d-xxl-inline admin-global-search-shortcut ms-2">Ctrl K</span>
        </button>
        <button
            type="button"
            class="btn btn-sm admin-user-button position-relative me-2"
            data-admin-shortcuts-open
            title="Favoritos e recentes"
            aria-label="Abrir favoritos e acessos recentes"
        >
            <i class="bi bi-star"></i>
            <span class="d-none d-xl-inline ms-1">Atalhos</span>
        </button>
        <div class="dropdown">
            <button class="btn btn-sm admin-user-button dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-1"></i>
                <span class="d-none d-sm-inline"><?= e($user['nome'] ?? '') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><h6 class="dropdown-header"><?= e($user['perfil_nome'] ?? '') ?></h6></li>
                <li><a class="dropdown-item" href="<?= e(url('admin/minha-conta.php')) ?>"><i class="bi bi-person me-2"></i>Minha conta</a></li>
                <li><a class="dropdown-item" href="<?= e(url()) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-2"></i>Ver portal</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= e(url('admin/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="admin-shell d-lg-flex">
    <aside class="offcanvas-lg offcanvas-start admin-sidebar" tabindex="-1" id="adminSidebar" aria-labelledby="adminSidebarLabel">
        <div class="offcanvas-header d-lg-none border-bottom">
            <h5 class="offcanvas-title" id="adminSidebarLabel">Menu administrativo</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Fechar"></button>
        </div>
        <div class="offcanvas-body p-0 d-flex flex-column">
            <nav class="admin-nav flex-grow-1 py-3" aria-label="Navegação administrativa">
                <a class="admin-nav-link <?= $isPath('index.php') ? 'active' : '' ?>" href="<?= e(url('admin/index.php')) ?>">
                    <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                </a>

                <?php if ($canSeePendingCenter): ?>
                    <a
                        class="admin-nav-link <?= $pendingCenterOpen ? 'active' : '' ?>"
                        href="<?= e(url('admin/pendencias.php')) ?>"
                    >
                        <i class="bi bi-bell"></i>
                        <span>Pendências</span>

                        <?php if ($adminPendingTotal > 0): ?>
                            <span class="badge text-bg-danger ms-auto">
                                <?= $adminPendingTotal > 99 ? '99+' : (int)$adminPendingTotal ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if (Auth::can('noticias.gerenciar') || Auth::can('comentarios.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $postOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuPosts" aria-expanded="<?= $postOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-file-earmark-text"></i><span>Posts / Notícias</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $postOpen ? 'show' : '' ?>" id="menuPosts">
                        <?php if (Auth::can('noticias.gerenciar')): ?>
                            <a class="<?= $isPath('noticias/index.php') && (($_GET['status'] ?? '') !== 'lixeira') ? 'active' : '' ?>" href="<?= e(url('admin/noticias/index.php')) ?>">Todos os Posts</a>
                            <?php if (
                                Auth::can('noticias.gerenciar')
                                || Auth::can('noticias.revisar')
                                || Auth::can('noticias.publicar')
                                || Auth::isAdmin()
                            ): ?>
                                <a
                                    class="<?= $isPath('noticias/revisao.php') ? 'active' : '' ?>"
                                    href="<?= e(url('admin/noticias/revisao.php')) ?>"
                                >Fila de revisão</a>
                            <?php endif; ?>
                            <a class="<?= $isPath('noticias/index.php') && (($_GET['status'] ?? '') === 'lixeira') ? 'active' : '' ?>" href="<?= e(url('admin/noticias/index.php?status=lixeira')) ?>">Lixeira</a>
                            <a class="<?= $isPath('noticias/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/noticias/form.php')) ?>">Adicionar Novo</a>
                            <a class="<?= $isPath('noticias/mais-lidas.php') ? 'active' : '' ?>" href="<?= e(url('admin/noticias/mais-lidas.php')) ?>">Mais Lidas</a>
                            <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=posts')) ?>">Importar do WordPress</a><?php endif; ?>
                            <a class="<?= $startsPath('categorias') ? 'active' : '' ?>" href="<?= e(url('admin/categorias/index.php')) ?>">Categorias</a>
                            <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=categories')) ?>">Importar Categorias</a><?php endif; ?>
                            <a class="<?= $startsPath('tags') ? 'active' : '' ?>" href="<?= e(url('admin/tags/index.php')) ?>">Tags</a>
                            <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=tags')) ?>">Importar Tags</a><?php endif; ?>
                        <?php endif; ?>
                        <?php if (Auth::can('comentarios.gerenciar')): ?>
                            <a class="<?= $startsPath('comentarios') ? 'active' : '' ?>" href="<?= e(url('admin/comentarios/index.php')) ?>">Comentários<?php if($pendingComments>0): ?><span class="badge text-bg-warning ms-auto"><?= (int)$pendingComments ?></span><?php endif; ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('midias.gerenciar') || Auth::can('galerias.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $mediaOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuMidia" aria-expanded="<?= $mediaOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-images"></i><span>Mídia</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $mediaOpen ? 'show' : '' ?>" id="menuMidia">
                        <?php if (Auth::can('midias.gerenciar')): ?>
                            <a class="<?= $isPath('midias/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/midias/index.php')) ?>">Biblioteca</a>
<a href="<?= e(url('admin/midias/index.php#adicionar-novo')) ?>">Adicionar Novo</a>
                            <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=media')) ?>">Importar do WordPress</a><?php endif; ?>
                        <?php endif; ?>
                        <?php if (Auth::can('galerias.gerenciar')): ?>
                            <a class="<?= $startsPath('galerias') ? 'active' : '' ?>" href="<?= e(url('admin/galerias/index.php')) ?>">Galerias</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('paginas.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $pagesOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuPaginas" aria-expanded="<?= $pagesOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-files"></i><span>Páginas</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $pagesOpen ? 'show' : '' ?>" id="menuPaginas">
                        <a class="<?= $isPath('paginas/index.php') && (($_GET['status'] ?? '') !== 'lixeira') ? 'active' : '' ?>" href="<?= e(url('admin/paginas/index.php')) ?>">Todas as Páginas</a>
                        <a class="<?= $isPath('paginas/index.php') && (($_GET['status'] ?? '') === 'lixeira') ? 'active' : '' ?>" href="<?= e(url('admin/paginas/index.php?status=lixeira')) ?>">Lixeira</a>
                        <a class="<?= $isPath('paginas/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/paginas/form.php')) ?>">Adicionar Nova</a>
                        <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=pages')) ?>">Importar do WordPress</a><?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('paginas.gerenciar') || Auth::can('noticias.gerenciar')): ?>
                    <a
                        class="admin-nav-link <?= $startsPath('padroes-conteudo') ? 'active' : '' ?>"
                        href="<?= e(url('admin/padroes-conteudo/index.php')) ?>"
                    >
                        <i class="bi bi-grid-3x3-gap"></i><span>Padrões de conteúdo</span>
                    </a>
                <?php endif; ?>
                <?php if (Auth::can('eventos.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $eventsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuEventos" aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-calendar-event"></i><span>Eventos</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $eventsOpen ? 'show' : '' ?>" id="menuEventos">
                        <a class="<?= $isPath('eventos/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/eventos/index.php')) ?>">Todos os Eventos</a>
                        <a class="<?= $isPath('eventos/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/eventos/form.php')) ?>">Adicionar Novo</a>
                        <?php if (Auth::can('wordpress.importar')): ?><a href="<?= e(url('admin/ferramentas/wordpress.php?modulo=events')) ?>">Importar do WordPress</a><?php endif; ?>
                        <a class="<?= $isPath('eventos/categorias.php') ? 'active' : '' ?>" href="<?= e(url('admin/eventos/categorias.php')) ?>">Categorias</a>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('formularios.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $formsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuFormularios" aria-expanded="<?= $formsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-ui-checks-grid"></i><span>Formulários</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $formsOpen ? 'show' : '' ?>" id="menuFormularios">
                        <a class="<?= $isPath('formularios/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/formularios/index.php')) ?>">Todos os Formulários</a>
                        <a class="<?= $isPath('formularios/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/formularios/form.php')) ?>">Adicionar Novo</a>
                        <a class="<?= $isPath('formularios/respostas.php') || $isPath('formularios/resposta.php') ? 'active' : '' ?>" href="<?= e(url('admin/formularios/respostas.php')) ?>">Respostas</a>
                    </div>
                <?php endif; ?>

                <div class="admin-nav-separator"></div>

                <?php if (Auth::can('seo.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $seoOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuSeo" aria-expanded="<?= $seoOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-graph-up-arrow"></i><span>SEO</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $seoOpen ? 'show' : '' ?>" id="menuSeo">
                        <a class="<?= $isPath('seo/geral.php') ? 'active' : '' ?>" href="<?= e(url('admin/seo/geral.php')) ?>">Geral</a>
                        <a class="<?= $isPath('seo/social.php') ? 'active' : '' ?>" href="<?= e(url('admin/seo/social.php')) ?>">Social</a>
                        <a class="<?= $isPath('seo/sitemap.php') ? 'active' : '' ?>" href="<?= e(url('admin/seo/sitemap.php')) ?>">Sitemap</a>
                        <a class="<?= $isPath('seo/feeds.php') ? 'active' : '' ?>" href="<?= e(url('admin/seo/feeds.php')) ?>">Feeds RSS</a>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('usuarios.gerenciar') || Auth::can('permissoes.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $usersOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuUsuarios" aria-expanded="<?= $usersOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-people"></i><span>Usuários</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $usersOpen ? 'show' : '' ?>" id="menuUsuarios">
                        <?php if (Auth::can('usuarios.gerenciar')): ?>
                            <a class="<?= $isPath('usuarios/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/usuarios/index.php')) ?>">Todos os Usuários</a>
                            <a class="<?= $isPath('usuarios/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/usuarios/form.php')) ?>">Adicionar Novo</a>
                        <?php endif; ?>
                        <a href="<?= e(url('admin/minha-conta.php')) ?>">Seu Perfil</a>
                        <?php if (Auth::can('permissoes.gerenciar')): ?>
                            <a class="<?= $startsPath('perfis') ? 'active' : '' ?>" href="<?= e(url('admin/perfis/index.php#funcoes')) ?>">Funções</a>
                            <a class="<?= $startsPath('perfis') ? 'active' : '' ?>" href="<?= e(url('admin/perfis/index.php#permissoes')) ?>">Permissões</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canAppearance): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $appearanceOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuAparencia" aria-expanded="<?= $appearanceOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-palette"></i><span>Aparência</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $appearanceOpen ? 'show' : '' ?>" id="menuAparencia">
                        <?php if (Auth::can('home.gerenciar')): ?>
                            <a class="<?= $isPath('aparencia/home.php') ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/home.php')) ?>">Página Inicial</a>
                        <?php endif; ?>
                        <?php if (Auth::can('aparencia.gerenciar')): ?>
                            <a class="<?= $isPath('aparencia/temas.php') ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/temas.php')) ?>">Temas</a>
                            <a class="<?= $isPath('aparencia/personalizar.php') ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/personalizar.php')) ?>">Personalizar</a>
                            <a class="<?= $isPath('aparencia/widgets.php') ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/widgets.php')) ?>">Widgets</a>
                        <?php endif; ?>
                        <?php if (Auth::can('menus.gerenciar')): ?>
                            <a class="<?= $startsPath('menus') ? 'active' : '' ?>" href="<?= e(url('admin/menus/index.php')) ?>">Menus</a>
                        <?php endif; ?>
                        <?php if (Auth::can('banners.gerenciar')): ?>
                            <a class="<?= $startsPath('banners') ? 'active' : '' ?>" href="<?= e(url('admin/banners/index.php')) ?>">Banners</a>
                        <?php endif; ?>
                        <?php if (Auth::can('tema_editor.gerenciar')): ?>
                            <a class="<?= $isPath('aparencia/editor-temas.php') ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/editor-temas.php')) ?>">Editor de Temas</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('comunidades.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $communitiesOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuComunidades" aria-expanded="<?= $communitiesOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-buildings"></i><span>Comunidades</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $communitiesOpen ? 'show' : '' ?>" id="menuComunidades">
                        <a class="<?= $isPath('comunidades/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/comunidades/index.php')) ?>">Todas as Comunidades</a>
                        <a class="<?= $isPath('comunidades/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/comunidades/form.php')) ?>">Adicionar Nova</a>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('grupos.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $groupsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuGrupos" aria-expanded="<?= $groupsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-people-fill"></i><span>Grupos / Ministérios</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $groupsOpen ? 'show' : '' ?>" id="menuGrupos">
                        <a class="<?= $isPath('grupos/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/grupos/index.php')) ?>">Todos os Grupos</a>
                        <a class="<?= $isPath('grupos/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/grupos/form.php')) ?>">Adicionar Novo</a>
                    </div>
                <?php endif; ?>

                
                
                <?php if (Auth::can('liderancas.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $leadershipOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuLiderancas" aria-expanded="<?= $leadershipOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-people"></i><span>Equipe / Lideranças</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $leadershipOpen ? 'show' : '' ?>" id="menuLiderancas">
                        <a class="<?= $isPath('liderancas/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/liderancas/index.php')) ?>">Todas as Pessoas</a>
                        <a class="<?= $isPath('liderancas/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/liderancas/form.php')) ?>">Adicionar Nova</a>
                    </div>
                <?php endif; ?>
<?php if (Auth::can('documentos.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $documentsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuDocumentos" aria-expanded="<?= $documentsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-file-earmark-arrow-down"></i><span>Documentos</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $documentsOpen ? 'show' : '' ?>" id="menuDocumentos">
                        <a class="<?= $isPath('documentos/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/documentos/index.php')) ?>">Todos os Documentos</a>
                        <a class="<?= $isPath('documentos/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/documentos/form.php')) ?>">Adicionar Novo</a>
                        <a class="<?= $isPath('documentos/categorias.php') ? 'active' : '' ?>" href="<?= e(url('admin/documentos/categorias.php')) ?>">Categorias</a>
                    </div>
                <?php endif; ?>
<?php if (Auth::can('newsletter.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $newsletterOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuNewsletter" aria-expanded="<?= $newsletterOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-envelope-paper"></i><span>Newsletter</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $newsletterOpen ? 'show' : '' ?>" id="menuNewsletter">
                        <a class="<?= $isPath('newsletter/assinantes.php') ? 'active' : '' ?>" href="<?= e(url('admin/newsletter/assinantes.php')) ?>">Assinantes</a>
                        <a class="<?= ($isPath('newsletter/campanhas.php') || $isPath('newsletter/campanha-form.php') || $isPath('newsletter/enviar.php')) ? 'active' : '' ?>" href="<?= e(url('admin/newsletter/campanhas.php')) ?>">Campanhas</a>
                        <a class="<?= $isPath('newsletter/configuracoes.php') ? 'active' : '' ?>" href="<?= e(url('admin/newsletter/configuracoes.php')) ?>">Configurações</a>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('auditoria.visualizar')): ?>
                    <a class="admin-nav-link <?= $auditOpen ? 'active' : '' ?>" href="<?= e(url('admin/auditoria/index.php')) ?>">
                        <i class="bi bi-clipboard2-pulse"></i><span>Auditoria</span>
                    </a>
                <?php endif; ?>

                <?php if (Auth::can('tarefas.gerenciar') || Auth::can('backups.gerenciar') || Auth::can('manutencao.gerenciar') || Auth::can('wordpress.importar') || Auth::can('saude.visualizar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $toolsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuFerramentas" aria-expanded="<?= $toolsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-tools"></i><span>Ferramentas</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $toolsOpen ? 'show' : '' ?>" id="menuFerramentas">
                        <?php if (Auth::can('tarefas.gerenciar')): ?>
                            <a class="<?= $isPath('ferramentas/tarefas-agendadas.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>">Tarefas Agendadas</a>
                        <?php endif; ?>
                        <?php if (Auth::can('backups.gerenciar')): ?>
                            <a class="<?= $isPath('ferramentas/backups.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/backups.php')) ?>">Backups</a>
                        <?php endif; ?>
                        <?php if (Auth::can('manutencao.gerenciar')): ?>
                            <a class="<?= $isPath('ferramentas/manutencao.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/manutencao.php')) ?>">Manutenção</a>
                            <a class="<?= $isPath('ferramentas/limpeza.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/limpeza.php')) ?>">Limpeza</a>
                        <?php endif; ?>
                        <?php if (Auth::can('wordpress.importar')): ?>
                            <a class="<?= $isPath('ferramentas/wordpress.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/wordpress.php')) ?>">Importar WordPress</a>
                        <?php endif; ?>
                        <?php if (Auth::can('saude.visualizar')): ?>
                                                        <a
                                class="<?= $isPath('ferramentas/diagnostico.php') ? 'active' : '' ?>"
                                href="<?= e(url('admin/ferramentas/diagnostico.php')) ?>"
                            >Central de Diagnóstico</a>
<a class="<?= $isPath('ferramentas/saude.php') ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/saude.php')) ?>">Saúde do Portal</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('configuracoes.gerenciar') || Auth::can('seguranca.gerenciar') || Auth::can('email.gerenciar') || Auth::can('performance.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $configOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuConfiguracoes" aria-expanded="<?= $configOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-gear"></i><span>Configurações</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $configOpen ? 'show' : '' ?>" id="menuConfiguracoes">
                        <?php if (Auth::can('configuracoes.gerenciar')): ?>
                            <a class="<?= $isPath('configuracoes/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/index.php')) ?>">Geral</a>
                            <a class="<?= $isPath('configuracoes/escrita.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/escrita.php')) ?>">Escrita</a>
                            <a class="<?= $isPath('configuracoes/discussao.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/discussao.php')) ?>">Discussão</a>
                            <a class="<?= $isPath('configuracoes/leitura.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/leitura.php')) ?>">Leitura</a>
                            <a class="<?= $isPath('configuracoes/midia.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/midia.php')) ?>">Mídia</a>
                            <a class="<?= $isPath('configuracoes/links-permanentes.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/links-permanentes.php')) ?>">Links Permanentes</a>
                            <a class="<?= $isPath('configuracoes/privacidade.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/privacidade.php')) ?>">Privacidade</a>
                        <?php endif; ?>
                        <?php if (Auth::can('performance.gerenciar')): ?>
                            <a class="<?= $isPath('configuracoes/performance.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/performance.php')) ?>">Performance</a>
                        <?php endif; ?>
                        <?php if (Auth::can('email.gerenciar')): ?>
                            <a class="<?= $isPath('configuracoes/email.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/email.php')) ?>">E-mail</a>
                        <?php endif; ?>
                        <?php if (Auth::can('seguranca.gerenciar')): ?>
                            <a class="<?= $isPath('configuracoes/seguranca.php') ? 'active' : '' ?>" href="<?= e(url('admin/configuracoes/seguranca.php')) ?>">Segurança</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </nav>

            <div class="admin-sidebar-footer border-top py-2">
                <a class="admin-nav-link <?= $accountOpen ? 'active' : '' ?>" href="<?= e(url('admin/minha-conta.php')) ?>">
                    <i class="bi bi-person-circle"></i><span>Minha conta</span>
                </a>
                <a class="admin-nav-link" href="<?= e(url()) ?>" target="_blank">
                    <i class="bi bi-box-arrow-up-right"></i><span>Ver portal</span>
                </a>
                <div class="admin-version px-3 pt-2">Portal v<?= e(defined('APP_VERSION') ? (string)APP_VERSION : '0.28.0') ?></div>
            </div>
        </div>
    </aside>

    <main class="admin-main flex-grow-1">
        <div class="admin-content container-fluid">
            <?php if ($msg = Session::flash('success')): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>
            <?php if ($msg = Session::flash('error')): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endif; ?>
