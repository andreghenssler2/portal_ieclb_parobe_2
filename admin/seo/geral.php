<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();

$defaults = [
    'seo_titulo' => 'IECLB Parobé',
    'seo_descricao' => 'Portal da IECLB Parobé',
    'seo_keywords' => 'IECLB, Parobé, igreja luterana, cultos, eventos',
    'seo_title_separator' => '-',
    'seo_append_site_name' => '1',
    'seo_robots_index' => '1',
    'seo_robots_follow' => '1',
];
$settings = array_merge($defaults, siteConfigAll($pdo));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['seo_titulo','seo_descricao','seo_keywords','seo_title_separator'] as $key) {
        $settings[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $settings['seo_append_site_name'] = isset($_POST['seo_append_site_name']) ? '1' : '0';
    $settings['seo_robots_index'] = isset($_POST['seo_robots_index']) ? '1' : '0';
    $settings['seo_robots_follow'] = isset($_POST['seo_robots_follow']) ? '1' : '0';

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            if ($settings['seo_titulo'] === '') throw new RuntimeException('Informe o título padrão do portal.');
            if (mb_strlen($settings['seo_titulo']) > 180) throw new RuntimeException('O título padrão deve ter no máximo 180 caracteres.');
            if (mb_strlen($settings['seo_descricao']) > 320) throw new RuntimeException('A descrição padrão deve ter no máximo 320 caracteres.');
            if (!in_array($settings['seo_title_separator'], ['-','|','•','–','—'], true)) $settings['seo_title_separator'] = '-';

            foreach ($defaults as $key => $_) saveSiteConfig($pdo, $key, $settings[$key], 'texto');
            logAction($pdo, 'seo.geral.atualizar', 'configuracoes', null, 'Configurações gerais de SEO atualizadas');
            Session::flash('success', 'Configurações gerais de SEO atualizadas.');
            header('Location: ' . url('admin/seo/geral.php')); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$pageTitle = 'SEO - Geral';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">SEO · Geral</h1><p class="text-secondary mb-0">Defina títulos, descrições e regras globais de indexação.</p></div>
    <a class="btn btn-outline-secondary" target="_blank" href="<?= e(url()) ?>">Ver portal</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body p-4">
    <?= Csrf::field() ?>
    <div class="row g-3">
        <div class="col-lg-8"><label class="form-label">Título padrão</label><input class="form-control" name="seo_titulo" maxlength="180" value="<?= e($settings['seo_titulo']) ?>" required><div class="form-text">Usado na Home e como nome do site nos títulos internos.</div></div>
        <div class="col-lg-4"><label class="form-label">Separador de título</label><select class="form-select" name="seo_title_separator"><?php foreach (['-','|','•','–','—'] as $sep): ?><option value="<?= e($sep) ?>" <?= $settings['seo_title_separator']===$sep?'selected':'' ?>><?= e($sep) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Meta description padrão</label><textarea class="form-control" name="seo_descricao" rows="3" maxlength="320"><?= e($settings['seo_descricao']) ?></textarea></div>
        <div class="col-12"><label class="form-label">Palavras-chave padrão</label><input class="form-control" name="seo_keywords" value="<?= e($settings['seo_keywords']) ?>" placeholder="IECLB, Parobé, cultos, eventos"></div>
        <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="seo_append_site_name" id="appendSite" <?= $settings['seo_append_site_name']==='1'?'checked':'' ?>><label class="form-check-label" for="appendSite">Adicionar nome do portal aos títulos internos</label></div></div>
        <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="seo_robots_index" id="robotsIndex" <?= $settings['seo_robots_index']==='1'?'checked':'' ?>><label class="form-check-label" for="robotsIndex">Permitir indexação por buscadores</label></div></div>
        <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="seo_robots_follow" id="robotsFollow" <?= $settings['seo_robots_follow']==='1'?'checked':'' ?>><label class="form-check-label" for="robotsFollow">Permitir seguir links</label></div></div>
    </div>
    <div class="alert alert-light border mt-4 mb-0"><strong>SEO individual:</strong> Notícias, Páginas, Eventos e Galerias agora possuem campos próprios de título, descrição e não indexação.</div>
    <div class="mt-4"><button class="btn btn-primary px-4">Salvar alterações</button></div>
</div></form>
<?php require __DIR__ . '/../_footer.php'; ?>
