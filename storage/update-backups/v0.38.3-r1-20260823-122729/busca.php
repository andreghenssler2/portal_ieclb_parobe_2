<?php
require_once __DIR__ . '/bootstrap.php';
$pdo=Database::connection();
$q=trim((string)($_GET['q'] ?? ''));
$q=mb_substr($q,0,120);
$results=[];
if(mb_strlen($q)>=2){
    $like='%'.$q.'%';
    $queries=[
        ['noticia', "SELECT titulo,slug,resumo,conteudo,COALESCE(publicado_em,created_at) dt FROM posts WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND (titulo LIKE :q OR resumo LIKE :q OR conteudo LIKE :q) ORDER BY dt DESC LIMIT 20"],
        ['pagina', "SELECT titulo,slug,resumo,conteudo,COALESCE(publicado_em,created_at) dt FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND (titulo LIKE :q OR resumo LIKE :q OR conteudo LIKE :q) ORDER BY dt DESC LIMIT 15"],
        ['evento', "SELECT titulo,slug,resumo,conteudo,data_inicio dt FROM eventos WHERE status='publicado' AND (titulo LIKE :q OR resumo LIKE :q OR conteudo LIKE :q OR local LIKE :q) ORDER BY data_inicio DESC LIMIT 15"],
                ['lideranca', "SELECT nome titulo,slug,resumo,biografia conteudo,updated_at dt FROM liderancas WHERE ativo=1 AND (nome LIKE :q OR funcao LIKE :q OR resumo LIKE :q OR biografia LIKE :q) ORDER BY ordem ASC,nome ASC LIMIT 15"],
        ['documento', "SELECT titulo,slug,descricao resumo,descricao conteudo,COALESCE(publicado_em,created_at) dt FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND (titulo LIKE :q OR descricao LIKE :q) ORDER BY dt DESC LIMIT 15"],
['galeria', "SELECT titulo,slug,descricao resumo,'' conteudo,COALESCE(publicado_em,created_at) dt FROM galerias WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND (titulo LIKE :q OR descricao LIKE :q) ORDER BY dt DESC LIMIT 10"],
    ];
    foreach($queries as [$type,$sql]){ try{$st=$pdo->prepare($sql);$st->execute(['q'=>$like]);foreach($st->fetchAll() as $r){$r['type']=$type;$results[]=$r;}}catch(Throwable $e){} }
    usort($results,static fn($a,$b)=>strcmp((string)$b['dt'],(string)$a['dt']));
}
$labels=['noticia'=>'Notícia','pagina'=>'Página','evento'=>'Evento','galeria'=>'Galeria',,'documento'=>'Documento',,'lideranca'=>'Liderança'];
$metaTitle=$q!==''?'Busca por '.$q:'Busca'; $metaDescription='Busca interna do Portal IECLB Parobé.'; $metaNoindex=true; $canonicalUrl=url('busca');
require themeFile($pdo,'header.php');
?>
<section class="container py-5"><div class="search-page mx-auto"><h1 class="h2 mb-4">Busca</h1><form class="input-group input-group-lg mb-4" method="get" action="<?= e(url('busca')) ?>"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar notícias, páginas, eventos, documentos, lideranças..." minlength="2" required><button class="btn btn-primary">Buscar</button></form>
<?php if($q!=='' && mb_strlen($q)<2): ?><div class="alert alert-warning">Digite pelo menos 2 caracteres.</div><?php elseif($q!==''): ?><p class="text-secondary mb-4"><?= count($results) ?> resultado(s) para <strong><?= e($q) ?></strong>.</p><?php if(!$results): ?><div class="alert alert-light border">Nenhum conteúdo encontrado.</div><?php endif; ?><div class="list-group list-group-flush search-results"><?php foreach($results as $r): ?><a class="list-group-item list-group-item-action px-0 py-3" href="<?= e(contentUrl((string)$r['type'],(string)$r['slug'])) ?>"><div class="d-flex justify-content-between gap-3"><div><span class="badge text-bg-light border mb-2"><?= e($labels[$r['type']]??$r['type']) ?></span><h2 class="h5 mb-1"><?= e($r['titulo']) ?></h2><p class="text-secondary mb-0"><?= e($r['resumo'] ?: portalExcerpt($r['conteudo'],180)) ?></p></div><?php if($r['dt']): ?><small class="text-secondary text-nowrap"><?= e(formatDateOnlyBr($r['dt'])) ?></small><?php endif; ?></div></a><?php endforeach; ?></div><?php endif; ?></div></section>
<?php require themeFile($pdo,'footer.php'); ?>
