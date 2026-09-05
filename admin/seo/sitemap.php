<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();
$defaults = [
    'seo_sitemap_ativo' => '1',
    'seo_sitemap_geral' => '1',
    'seo_sitemap_geral_home' => '1',
    'seo_sitemap_geral_agenda' => '1',
    'seo_sitemap_geral_comunidades' => '1',
    'seo_sitemap_geral_grupos' => '1',
    'seo_sitemap_geral_galerias' => '1',
    'seo_sitemap_geral_documentos' => '1',
    'seo_sitemap_geral_liderancas' => '1',
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
        foreach (['seo_sitemap_ativo','seo_sitemap_geral','seo_sitemap_geral_home','seo_sitemap_geral_agenda','seo_sitemap_geral_comunidades','seo_sitemap_geral_grupos','seo_sitemap_geral_galerias','seo_sitemap_geral_documentos','seo_sitemap_geral_liderancas','seo_sitemap_posts','seo_sitemap_paginas','seo_sitemap_eventos','seo_sitemap_galerias','seo_sitemap_comunidades','seo_sitemap_grupos','seo_sitemap_tags','seo_sitemap_categorias','seo_sitemap_documentos','seo_sitemap_formularios','seo_sitemap_imagens','seo_sitemap_liderancas'] as $key) {
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

/* PORTAL_GERAL_SITEMAP_R15R2 */
$generalSitemapItems = [
    [
        'key' => 'seo_sitemap_geral_home',
        'label' => 'Página inicial',
        'path' => '/',
        'description' => 'Página inicial do Portal.',
    ],
    [
        'key' => 'seo_sitemap_geral_agenda',
        'label' => 'Agenda',
        'path' => '/agenda',
        'description' => 'Calendário público de cultos, festas, atividades e reuniões.',
    ],
    [
        'key' => 'seo_sitemap_geral_comunidades',
        'label' => 'Comunidades',
        'path' => '/comunidades',
        'description' => 'Página que lista as comunidades da Paróquia.',
    ],
    [
        'key' => 'seo_sitemap_geral_grupos',
        'label' => 'Grupos / Ministérios',
        'path' => '/grupos',
        'description' => 'Página pública de grupos, ministérios e departamentos.',
    ],
    [
        'key' => 'seo_sitemap_geral_galerias',
        'label' => 'Galerias',
        'path' => '/galerias',
        'description' => 'Página pública que lista as galerias.',
    ],
    [
        'key' => 'seo_sitemap_geral_documentos',
        'label' => 'Documentos',
        'path' => '/documentos',
        'description' => 'Página pública de documentos e downloads.',
    ],
    [
        'key' => 'seo_sitemap_geral_liderancas',
        'label' => 'Lideranças',
        'path' => '/liderancas',
        'description' => 'Página pública de pastores, presbitério, lideranças e equipe.',
    ],
];

$generalSitemapCount = 0;

foreach ($generalSitemapItems as $generalItem) {
    if (($settings[$generalItem['key']] ?? '1') === '1') {
        $generalSitemapCount++;
    }
}
$subSitemaps = [
    ['key'=>'seo_sitemap_geral','label'=>'Geral','file'=>'geral.sitemaps.xml','count'=>$generalSitemapCount,'description'=>'Páginas institucionais escolhidas abaixo.'],
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

<style id="PORTAL_SITEMAP_TOGGLE_R14R3">
.portal-sitemap-toggle-btn{
    min-width:92px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}
@media(max-width:575.98px){
    .portal-sitemap-toggle-btn{
        min-width:86px;
    }
}
</style>
<style id="PORTAL_GERAL_SITEMAP_R15R2_STYLE">
.portal-general-sitemap-toggle{
    min-width:92px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}
</style>
<form method="post">
    <?= Csrf::field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                        <div>
                            <div class="fw-semibold">Sitemap XML</div>
                            <div class="small text-secondary">Ativa ou desativa o índice principal e os sub-sitemaps.</div>
                        </div>

                        <div>
                            <input
                                class="btn-check js-sitemap-toggle"
                                type="checkbox"
                                name="seo_sitemap_ativo"
                                id="sitemapActive"
                                autocomplete="off"
                                <?= $settings['seo_sitemap_ativo']==='1'?'checked':'' ?>
                            >
                            <label
                                class="btn btn-sm portal-sitemap-toggle-btn <?= $settings['seo_sitemap_ativo']==='1' ? 'btn-success' : 'btn-outline-secondary' ?>"
                                for="sitemapActive"
                                data-r14r3
                            >
                                <i class="bi <?= $settings['seo_sitemap_ativo']==='1' ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                <span><?= $settings['seo_sitemap_ativo']==='1' ? 'Ativo' : 'Inativo' ?></span>
                            </label>
                        </div>
                    </div>
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
                        <div class="fw-semibold">Incluir imagens encontradas nos conteúdos</div>

                        <div>
                            <input
                                class="btn-check js-sitemap-toggle"
                                type="checkbox"
                                name="seo_sitemap_imagens"
                                id="sitemapImages"
                                autocomplete="off"
                                <?= $settings['seo_sitemap_imagens']==='1'?'checked':'' ?>
                            >
                            <label
                                class="btn btn-sm portal-sitemap-toggle-btn <?= $settings['seo_sitemap_imagens']==='1' ? 'btn-success' : 'btn-outline-secondary' ?>"
                                for="sitemapImages"
                                data-r14r3
                            >
                                <i class="bi <?= $settings['seo_sitemap_imagens']==='1' ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                <span><?= $settings['seo_sitemap_imagens']==='1' ? 'Ativo' : 'Inativo' ?></span>
                            </label>
                        </div>
                    </div>
                    <p class="small text-secondary mb-0">Inclui imagem destacada, imagens inseridas pelo editor e, nas galerias, as fotos vinculadas ao álbum.</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold">Conteúdo de <code>/geral.sitemaps.xml</code></div>
                        <div class="small text-secondary">Escolha quais páginas institucionais devem aparecer no sitemap Geral.</div>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= (int)$generalSitemapCount ?> selecionada<?= $generalSitemapCount === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php foreach ($generalSitemapItems as $generalItem): ?>
                        <?php $generalEnabled = ($settings[$generalItem['key']] ?? '1') === '1'; ?>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 border-bottom">
                            <div>
                                <label
                                    class="fw-semibold"
                                    for="<?= e($generalItem['key']) ?>"
                                >
                                    <?= e($generalItem['label']) ?>
                                </label>

                                <div class="small text-secondary">
                                    <?= e($generalItem['description']) ?>
                                </div>

                                <code class="small"><?= e($generalItem['path']) ?></code>
                            </div>

                            <div class="flex-shrink-0">
                                <input
                                    class="btn-check js-general-sitemap-toggle"
                                    type="checkbox"
                                    name="<?= e($generalItem['key']) ?>"
                                    id="<?= e($generalItem['key']) ?>"
                                    autocomplete="off"
                                    <?= $generalEnabled ? 'checked' : '' ?>
                                >

                                <label
                                    class="btn btn-sm portal-general-sitemap-toggle <?= $generalEnabled ? 'btn-success' : 'btn-outline-secondary' ?>"
                                    for="<?= e($generalItem['key']) ?>"
                                >
                                    <i class="bi <?= $generalEnabled ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                    <span><?= $generalEnabled ? 'Ativo' : 'Inativo' ?></span>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card-footer bg-white small text-secondary">
                    Essas opções afetam somente <code>/geral.sitemaps.xml</code>. Os sitemaps específicos continuam sendo configurados separadamente.
                </div>
            </div>
<div class="card-header bg-white fw-semibold">Sub-sitemaps</div>
                <div class="card-body p-0">
                    <?php foreach ($subSitemaps as $item): ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 border-bottom">
                            <div class="d-flex align-items-start gap-3">
                                                                <div class="mt-1 flex-shrink-0">
                                    <input
                                        class="btn-check js-sitemap-toggle"
                                        type="checkbox"
                                        name="<?=e($item['key'])?>"
                                        id="<?=e($item['key'])?>"
                                        autocomplete="off"
                                        <?= $settings[$item['key']]==='1'?'checked':'' ?>
                                    >

                                    <label
                                        class="btn btn-sm portal-sitemap-toggle-btn <?= $settings[$item['key']]==='1' ? 'btn-success' : 'btn-outline-secondary' ?>"
                                        for="<?=e($item['key'])?>"
                                    >
                                        <i class="bi <?= $settings[$item['key']]==='1' ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>
                                        <span><?= $settings[$item['key']]==='1' ? 'Ativo' : 'Inativo' ?></span>
                                    </label>
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
<script>
(() => {
    const syncSitemapToggleR14R3 = (input) => {
        const label = document.querySelector(
            `label[for="${CSS.escape(input.id)}"].portal-sitemap-toggle-btn`
        );

        if (!label) {
            return;
        }

        const active = input.checked;
        const text = label.querySelector('span');
        const icon = label.querySelector('i');

        label.classList.toggle('btn-success', active);
        label.classList.toggle('btn-outline-secondary', !active);

        if (text) {
            text.textContent = active ? 'Ativo' : 'Inativo';
        }

        if (icon) {
            icon.classList.toggle('bi-check-circle', active);
            icon.classList.toggle('bi-x-circle', !active);
        }
    };

    document.querySelectorAll('.js-sitemap-toggle').forEach((input) => {
        syncSitemapToggleR14R3(input);

        input.addEventListener('change', () => {
            syncSitemapToggleR14R3(input);
        });
    });
})();
</script>
<script>
(() => {
    const syncGeneralSitemapToggleR15R2 = (input) => {
        const label = document.querySelector(
            `label[for="${CSS.escape(input.id)}"].portal-general-sitemap-toggle`
        );

        if (!label) {
            return;
        }

        const active = input.checked;
        const text = label.querySelector('span');
        const icon = label.querySelector('i');

        label.classList.toggle('btn-success', active);
        label.classList.toggle('btn-outline-secondary', !active);

        if (text) {
            text.textContent = active ? 'Ativo' : 'Inativo';
        }

        if (icon) {
            icon.classList.toggle('bi-check-circle', active);
            icon.classList.toggle('bi-x-circle', !active);
        }
    };

    document.querySelectorAll('.js-general-sitemap-toggle').forEach((input) => {
        syncGeneralSitemapToggleR15R2(input);

        input.addEventListener('change', () => {
            syncGeneralSitemapToggleR15R2(input);
        });
    });
})();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
