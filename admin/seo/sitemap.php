<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();
$defaults = [
    'seo_sitemap_ativo' => '1',
    'seo_sitemap_posts' => '1',
    'seo_sitemap_paginas' => '1',
    'seo_sitemap_eventos' => '1',
    'seo_sitemap_galerias' => '1',
    'seo_sitemap_formularios' => '0',
    'seo_sitemap_ultima_geracao' => '',
];
$settings = array_merge($defaults, siteConfigAll($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        foreach (['seo_sitemap_ativo','seo_sitemap_posts','seo_sitemap_paginas','seo_sitemap_eventos','seo_sitemap_galerias','seo_sitemap_formularios'] as $key) {
            $settings[$key] = isset($_POST[$key]) ? '1' : '0';
            saveSiteConfig($pdo, $key, $settings[$key], 'booleano');
        }
        $settings['seo_sitemap_ultima_geracao'] = date('Y-m-d H:i:s');
        saveSiteConfig($pdo, 'seo_sitemap_ultima_geracao', $settings['seo_sitemap_ultima_geracao'], 'datahora');
        logAction($pdo, 'seo.sitemap.atualizar', 'configuracoes', null, 'Configurações de Sitemap atualizadas');
        Session::flash('success', 'Sitemap atualizado. Como ele é dinâmico, novos conteúdos publicados entram automaticamente.');
        header('Location: ' . url('admin/seo/sitemap.php')); exit;
    }
}

$counts = [];
foreach ([
    'Posts' => "SELECT COUNT(*) FROM posts WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Páginas' => "SELECT COUNT(*) FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Eventos' => "SELECT COUNT(*) FROM eventos WHERE status='publicado' AND seo_noindex=0",
    'Galerias' => "SELECT COUNT(*) FROM galerias WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
] as $label=>$sql) { try { $counts[$label]=(int)$pdo->query($sql)->fetchColumn(); } catch(Throwable $e) { $counts[$label]=0; } }
$pageTitle = 'SEO - Sitemap';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-4">
 <div><h1 class="h3 mb-1">SEO · Sitemap</h1><p class="text-secondary mb-0">O sitemap é gerado dinamicamente a partir do conteúdo publicado.</p></div>
 <div class="d-flex gap-2"><a class="btn btn-outline-primary" target="_blank" href="<?= e(url('sitemap.xml')) ?>">Abrir sitemap.xml</a><a class="btn btn-outline-secondary" target="_blank" href="<?= e(url('robots.txt')) ?>">Abrir robots.txt</a></div>
</div>
<form method="post"><div class="row g-4"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
<?= Csrf::field() ?>
<div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" name="seo_sitemap_ativo" id="sitemapActive" <?= $settings['seo_sitemap_ativo']==='1'?'checked':'' ?>><label class="form-check-label fw-semibold" for="sitemapActive">Ativar sitemap XML</label></div>
<div class="row g-3">
<?php foreach ([['seo_sitemap_posts','Posts / Notícias'],['seo_sitemap_paginas','Páginas'],['seo_sitemap_eventos','Eventos e Cultos'],['seo_sitemap_galerias','Galerias'],['seo_sitemap_formularios','Formulários públicos']] as [$key,$label]): ?>
<div class="col-md-6"><div class="border rounded p-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="<?= e($key) ?>" id="<?= e($key) ?>" <?= $settings[$key]==='1'?'checked':'' ?>><label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label></div></div></div>
<?php endforeach; ?>
</div>
<div class="mt-4"><button class="btn btn-primary">Salvar e atualizar sitemap</button></div>
</div></div></div>
<div class="col-xl-4"><div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Resumo</div><div class="card-body">
<?php foreach($counts as $label=>$count): ?><div class="d-flex justify-content-between border-bottom py-2"><span><?= e($label) ?></span><strong><?= $count ?></strong></div><?php endforeach; ?>
<div class="small text-secondary mt-3">Última atualização manual: <?= e($settings['seo_sitemap_ultima_geracao'] ? formatDateBr($settings['seo_sitemap_ultima_geracao']) : 'Ainda não registrada') ?></div>
</div></div></div></div></form>
<?php require __DIR__ . '/../_footer.php'; ?>
