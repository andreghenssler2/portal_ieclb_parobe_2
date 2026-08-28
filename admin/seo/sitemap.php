<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();
$defaults = [
    'seo_sitemap_ativo' => '1',
    'seo_sitemap_geral' => '1',
    'seo_sitemap_posts' => '1',
    'seo_sitemap_paginas' => '1',
    'seo_sitemap_eventos' => '1',
    'seo_sitemap_galerias' => '1',
    'seo_sitemap_comunidades' => '1',
    'seo_sitemap_grupos' => '1',
    'seo_sitemap_tags' => '1',
    'seo_sitemap_categorias' => '1',
    'seo_sitemap_liderancas' => '1',
    'seo_sitemap_documentos' => '1',
    'seo_sitemap_formularios' => '0',
    'seo_sitemap_imagens' => '1',
    'seo_sitemap_ultima_geracao' => '',
];
$settings = array_merge($defaults, siteConfigAll($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        foreach (['seo_sitemap_ativo','seo_sitemap_geral','seo_sitemap_posts','seo_sitemap_paginas','seo_sitemap_eventos','seo_sitemap_galerias','seo_sitemap_comunidades','seo_sitemap_grupos','seo_sitemap_tags','seo_sitemap_categorias','seo_sitemap_documentos','seo_sitemap_formularios','seo_sitemap_imagens','seo_sitemap_liderancas'] as $key) {
            $settings[$key] = isset($_POST[$key]) ? '1' : '0';
            saveSiteConfig($pdo, $key, $settings[$key], 'booleano');
        }
        $settings['seo_sitemap_ultima_geracao'] = date('Y-m-d H:i:s');
        saveSiteConfig($pdo, 'seo_sitemap_ultima_geracao', $settings['seo_sitemap_ultima_geracao'], 'datahora');
        logAction($pdo, 'seo.sitemap.atualizar', 'configuracoes', null, 'Configurações do índice e sub-sitemaps atualizadas');
        Session::flash('success', 'Sitemaps atualizados. O índice e os sub-sitemaps são dinâmicos; novos conteúdos publicados entram automaticamente.');
        header('Location: ' . url('admin/seo/sitemap.php'));
        exit;
    }
}

$counts = [];
foreach ([
    'Posts' => "SELECT COUNT(*) FROM posts WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Páginas' => "SELECT COUNT(*) FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Eventos' => "SELECT COUNT(*) FROM eventos WHERE status='publicado' AND seo_noindex=0",
    'Galerias' => "SELECT COUNT(*) FROM galerias WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Comunidades' => "SELECT COUNT(*) FROM comunidades WHERE ativa=1 AND seo_noindex=0",
    'Grupos' => "SELECT COUNT(*) FROM grupos WHERE ativo=1 AND seo_noindex=0",
    'Categorias' => "SELECT COUNT(DISTINCT c.id) FROM categorias c INNER JOIN post_categorias pc ON pc.categoria_id=c.id INNER JOIN posts p ON p.id=pc.post_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0",
    'Tags' => "SELECT COUNT(DISTINCT t.id) FROM tags t INNER JOIN post_tags pt ON pt.tag_id=t.id INNER JOIN posts p ON p.id=pt.post_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0",
    'Lideranças' => "SELECT COUNT(*) FROM liderancas WHERE ativo=1 AND seo_noindex=0",
    'Documentos' => "SELECT COUNT(*) FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0",
    'Formulários' => "SELECT COUNT(*) FROM formularios WHERE status='publicado' AND ativo=1 AND (publicado_em IS NULL OR publicado_em<=NOW())",
] as $label => $sql) {
    try {
        $counts[$label] = (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        $counts[$label] = 0;
    }
}

$subSitemaps = [
    ['key'=>'seo_sitemap_geral','label'=>'Geral','file'=>'geral.sitemaps.xml','count'=>5,'description'=>'Home, Agenda, Comunidades, Galerias, Lideranças e Documentos.'],
    ['key'=>'seo_sitemap_posts','label'=>'Posts / Notícias','file'=>'posts.sitemaps.xml','count'=>$counts['Posts'],'description'=>'Notícias publicadas e indexáveis.'],
    ['key'=>'seo_sitemap_paginas','label'=>'Páginas','file'=>'paginas.sitemaps.xml','count'=>$counts['Páginas'],'description'=>'Páginas institucionais publicadas e indexáveis.'],
    ['key'=>'seo_sitemap_eventos','label'=>'Eventos e Cultos','file'=>'eventos.sitemaps.xml','count'=>$counts['Eventos'],'description'=>'Eventos e cultos publicados.'],
    ['key'=>'seo_sitemap_galerias','label'=>'Galerias','file'=>'galerias.sitemaps.xml','count'=>$counts['Galerias'],'description'=>'Galerias publicadas, incluindo suas fotos.'],
    ['key'=>'seo_sitemap_comunidades','label'=>'Comunidades','file'=>'comunidades.sitemaps.xml','count'=>$counts['Comunidades'],'description'=>'Páginas públicas das comunidades, com imagem de capa e imagens do conteúdo.'],
    ['key'=>'seo_sitemap_grupos','label'=>'Grupos / Ministérios','file'=>'grupos.sitemaps.xml','count'=>$counts['Grupos'],'description'=>'Grupos, ministérios e departamentos ativos, com imagens do conteúdo.'],
    ['key'=>'seo_sitemap_categorias','label'=>'Categorias','file'=>'categorias.sitemaps.xml','count'=>$counts['Categorias'],'description'=>'Categorias que possuem notícias publicadas.'],
    ['key'=>'seo_sitemap_tags','label'=>'Tags','file'=>'tags.sitemaps.xml','count'=>$counts['Tags'],'description'=>'Arquivos de tags que possuem notícias publicadas.'],
    ['key'=>'seo_sitemap_liderancas','label'=>'Equipe / Lideranças','file'=>'liderancas.sitemaps.xml','count'=>$counts['Lideranças'] ?? 0,'description'=>'Pastores, presbitério, lideranças e equipe com perfil público.'],
    ['key'=>'seo_sitemap_documentos','label'=>'Documentos / Downloads','file'=>'documentos.sitemaps.xml','count'=>$counts['Documentos'] ?? 0,'description'=>'Documentos publicados e indexáveis.'],
    ['key'=>'seo_sitemap_formularios','label'=>'Formulários públicos','file'=>'formularios.sitemaps.xml','count'=>$counts['Formulários'],'description'=>'Formulários publicados e ativos.'],
];

$pageTitle = 'SEO - Sitemap';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">SEO · Sitemaps</h1>
        <p class="text-secondary mb-0">O <code>sitemap.xml</code> agora é um índice que agrupa sitemaps separados por tipo de conteúdo.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" target="_blank" href="<?= e(url('sitemap.xml')) ?>">Abrir sitemap.xml</a>
        <a class="btn btn-outline-secondary" target="_blank" href="<?= e(url('robots.txt')) ?>">Abrir robots.txt</a>
    </div>
</div>

<?php if ($msg=Session::flash('success')): ?><div class="alert alert-success"><?=e($msg)?></div><?php endif; ?>
<?php if ($msg=Session::flash('error')): ?><div class="alert alert-danger"><?=e($msg)?></div><?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="seo_sitemap_ativo" id="sitemapActive" <?= $settings['seo_sitemap_ativo']==='1'?'checked':'' ?>>
                        <label class="form-check-label fw-semibold" for="sitemapActive">Ativar Sitemap XML</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="seo_sitemap_imagens" id="sitemapImages" <?= $settings['seo_sitemap_imagens']==='1'?'checked':'' ?>>
                        <label class="form-check-label fw-semibold" for="sitemapImages">Incluir imagens encontradas nos conteúdos</label>
                    </div>
                    <p class="small text-secondary mb-0">Inclui imagem destacada, imagens inseridas pelo editor e, nas galerias, as fotos vinculadas ao álbum.</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Sub-sitemaps</div>
                <div class="card-body p-0">
                    <?php foreach ($subSitemaps as $item): ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 border-bottom">
                            <div class="d-flex align-items-start gap-3">
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox" name="<?=e($item['key'])?>" id="<?=e($item['key'])?>" <?= $settings[$item['key']]==='1'?'checked':'' ?>>
                                </div>
                                <div>
                                    <label class="fw-semibold" for="<?=e($item['key'])?>"><?=e($item['label'])?></label>
                                    <div class="small text-secondary"><?=e($item['description'])?></div>
                                    <code class="small">/<?=e($item['file'])?></code>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge text-bg-light border"><?= (int)$item['count'] ?> URLs</span>
                                <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?=e(url($item['file']))?>">Abrir</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-white p-3">
                    <button class="btn btn-primary">Salvar configurações</button>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Estrutura</div>
                <div class="card-body small">
                    <div><code>/sitemap.xml</code> <span class="text-secondary">→ índice principal</span></div>
                    <hr>
                    <?php foreach($subSitemaps as $item): ?>
                        <div class="d-flex justify-content-between gap-2 py-1"><code>/<?=e($item['file'])?></code><span><?= $settings[$item['key']]==='1' ? 'Ativo' : 'Inativo' ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Resumo</div>
                <div class="card-body">
                    <?php foreach($counts as $label=>$count): ?><div class="d-flex justify-content-between border-bottom py-2"><span><?=e($label)?></span><strong><?=$count?></strong></div><?php endforeach; ?>
                    <div class="small text-secondary mt-3">Última atualização manual: <?=e($settings['seo_sitemap_ultima_geracao'] ? formatDateBr($settings['seo_sitemap_ultima_geracao']) : 'Ainda não registrada')?></div>
                </div>
            </div>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../_footer.php'; ?>
