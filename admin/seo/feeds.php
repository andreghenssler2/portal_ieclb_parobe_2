<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('seo.gerenciar');
$pdo = Database::connection();
$defaults = [
    'seo_feed_ativo' => '1',
    'seo_feed_limite' => '20',
    'seo_feed_conteudo' => 'resumo',
    'seo_feed_imagens' => '1',
    'seo_feed_autor' => '1',
    'seo_feed_eventos' => '1',
    'seo_feed_categorias' => '1',
    'seo_feed_tags' => '1',
];
$settings = array_merge($defaults, siteConfigAll($pdo));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $settings['seo_feed_ativo'] = isset($_POST['seo_feed_ativo']) ? '1' : '0';
            $settings['seo_feed_imagens'] = isset($_POST['seo_feed_imagens']) ? '1' : '0';
            $settings['seo_feed_autor'] = isset($_POST['seo_feed_autor']) ? '1' : '0';
            $settings['seo_feed_eventos'] = isset($_POST['seo_feed_eventos']) ? '1' : '0';
            $settings['seo_feed_categorias'] = isset($_POST['seo_feed_categorias']) ? '1' : '0';
            $settings['seo_feed_tags'] = isset($_POST['seo_feed_tags']) ? '1' : '0';
            $settings['seo_feed_limite'] = (string)max(5, min(100, (int)($_POST['seo_feed_limite'] ?? 20)));
            $settings['seo_feed_conteudo'] = in_array($_POST['seo_feed_conteudo'] ?? '', ['resumo','completo'], true) ? (string)$_POST['seo_feed_conteudo'] : 'resumo';
            foreach ($defaults as $key => $default) {
                $type = $key === 'seo_feed_limite' ? 'numero' : ($key === 'seo_feed_conteudo' ? 'texto' : 'booleano');
                saveSiteConfig($pdo, $key, $settings[$key], $type);
            }
            logAction($pdo, 'seo.feeds.atualizar', 'configuracoes', null, 'Configurações de feeds RSS atualizadas');
            Session::flash('success', 'Configurações dos feeds RSS atualizadas.');
            header('Location: ' . url('admin/seo/feeds.php')); exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$pageTitle = 'SEO - Feeds RSS';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">SEO · Feeds RSS</h1><p class="text-secondary mb-0">Configure os feeds de notícias, eventos, categorias e tags.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-primary" target="_blank" href="<?=e(rssFeedUrl('posts'))?>">Abrir feed</a><a class="btn btn-outline-secondary" target="_blank" href="<?=e(rssFeedUrl('eventos'))?>">Feed de eventos</a></div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<form method="post">
<?=Csrf::field()?>
<div class="row g-4">
<div class="col-xl-8">
<div class="card border-0 shadow-sm"><div class="card-body p-4">
<div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" name="seo_feed_ativo" id="feedActive" <?=$settings['seo_feed_ativo']==='1'?'checked':''?>><label class="form-check-label fw-semibold" for="feedActive">Ativar feeds RSS</label></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Quantidade de itens por feed</label><input class="form-control" type="number" min="5" max="100" name="seo_feed_limite" value="<?=e($settings['seo_feed_limite'])?>"></div>
<div class="col-md-6"><label class="form-label">Conteúdo dos itens</label><select class="form-select" name="seo_feed_conteudo"><option value="resumo" <?=$settings['seo_feed_conteudo']==='resumo'?'selected':''?>>Resumo</option><option value="completo" <?=$settings['seo_feed_conteudo']==='completo'?'selected':''?>>Conteúdo completo</option></select></div>
<div class="col-md-6"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="seo_feed_imagens" id="feedImages" <?=$settings['seo_feed_imagens']==='1'?'checked':''?>><label class="form-check-label" for="feedImages">Incluir imagem destacada</label></div></div>
<div class="col-md-6"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="seo_feed_autor" id="feedAuthor" <?=$settings['seo_feed_autor']==='1'?'checked':''?>><label class="form-check-label" for="feedAuthor">Incluir nome do autor</label></div></div>
</div>
<hr class="my-4">
<h2 class="h6">Feeds adicionais</h2>
<div class="row g-3">
<div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="seo_feed_eventos" id="feedEvents" <?=$settings['seo_feed_eventos']==='1'?'checked':''?>><label class="form-check-label" for="feedEvents">Eventos e cultos</label></div></div>
<div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="seo_feed_categorias" id="feedCategories" <?=$settings['seo_feed_categorias']==='1'?'checked':''?>><label class="form-check-label" for="feedCategories">Categorias</label></div></div>
<div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="seo_feed_tags" id="feedTags" <?=$settings['seo_feed_tags']==='1'?'checked':''?>><label class="form-check-label" for="feedTags">Tags</label></div></div>
</div>
<div class="mt-4"><button class="btn btn-primary">Salvar alterações</button></div>
</div></div>
</div>
<div class="col-xl-4">
<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Endereços</div><div class="card-body small">
<div class="mb-2"><code>/feed.xml</code><div class="text-secondary">Últimas notícias</div></div>
<div class="mb-2"><code>/eventos.feed.xml</code><div class="text-secondary">Próximos eventos e cultos</div></div>
<div class="mb-2"><code>/categoria/{slug}/feed.xml</code><div class="text-secondary">Notícias de uma categoria</div></div>
<div><code>/tag/{slug}/feed.xml</code><div class="text-secondary">Notícias de uma tag</div></div>
</div></div>
</div>
</div>
</form>
<?php require __DIR__ . '/../_footer.php'; ?>
