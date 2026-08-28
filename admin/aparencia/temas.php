<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('aparencia.gerenciar');
$pdo = Database::connection();
$error = '';
$themes = installedThemes();
$active = activeThemeSlug($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $slug = trim((string)($_POST['tema'] ?? ''));
        $themes = installedThemes();
        if (!isset($themes[$slug])) {
            $error = 'Tema selecionado não está instalado ou possui manifesto inválido.';
        } else {
            saveSiteConfig($pdo, 'active_theme', $slug, 'texto');
            logAction($pdo, 'aparencia.tema_ativar', 'tema', null, 'Tema ativado: ' . $slug);
            Session::flash('success', 'Tema "' . $themes[$slug]['name'] . '" ativado com sucesso.');
            header('Location: ' . url('admin/aparencia/temas.php'));
            exit;
        }
    }
}

$pageTitle = 'Temas';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Temas</h1><p class="text-secondary mb-0">Escolha a identidade visual pública do portal.</p></div>
    <a class="btn btn-outline-secondary" href="<?= e(url()) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver portal</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4">
<?php foreach ($themes as $slug => $theme): $isActive = $slug === $active; $colors = array_slice(array_values(array_filter($theme['colors'], 'is_string')), 0, 5); ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 overflow-hidden <?= $isActive ? 'ring-active-theme' : '' ?>">
            <div class="theme-preview-strip d-flex">
                <?php if ($colors): foreach ($colors as $color): ?><span style="background:<?= e($color) ?>"></span><?php endforeach; else: ?><span class="bg-dark"></span><span class="bg-light"></span><?php endif; ?>
            </div>
            <div class="card-body p-4">
                <div class="d-flex justify-content-between gap-3 align-items-start mb-2">
                    <h2 class="h5 mb-0"><?= e($theme['name']) ?></h2>
                    <?php if ($isActive): ?><span class="badge text-bg-success">Ativo</span><?php endif; ?>
                </div>
                <div class="small text-secondary mb-3">Versão <?= e($theme['version']) ?><?= $theme['author'] ? ' · ' . e($theme['author']) : '' ?></div>
                <p class="text-secondary"><?= e($theme['description'] ?: 'Tema instalado no portal.') ?></p>
                <?php if (!$isActive): ?>
                    <form method="post" class="mt-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="tema" value="<?= e($slug) ?>">
                        <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Ativar tema</button>
                    </form>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-outline-primary" href="<?= e(url('admin/aparencia/personalizar.php')) ?>">Personalizar</a><?php if (Auth::can('tema_editor.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/aparencia/editor-temas.php')) ?>"><i class="bi bi-code-slash me-1"></i>Editor de Temas</a><?php endif; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$themes): ?><div class="col-12"><div class="alert alert-warning">Nenhum tema válido foi encontrado em <code>theme/</code>.</div></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
