<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$settings = siteConfigAll($pdo);
if (($settings['seo_sitemap_ativo'] ?? '1') !== '1') { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); exit('Sitemap desativado.'); }
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex, follow');

$urls = [];
$add = static function(string $loc, ?string $lastmod=null, string $changefreq='weekly', string $priority='0.5') use (&$urls): void {
    $urls[]=['loc'=>$loc,'lastmod'=>$lastmod,'changefreq'=>$changefreq,'priority'=>$priority];
};
$homeLastmod = null;
try { $lm = $pdo->query('SELECT MAX(updated_at) FROM configuracoes')->fetchColumn(); if ($lm) $homeLastmod = date('c', strtotime((string)$lm)); } catch (Throwable $e) {}
$add(url(), $homeLastmod, 'daily', '1.0');
$add(url('agenda.php'), null, 'daily', '0.8');
$add(url('comunidades.php'), null, 'monthly', '0.7');
$add(url('galerias.php'), null, 'weekly', '0.7');

try {
    if (($settings['seo_sitemap_posts'] ?? '1') === '1') {
        foreach ($pdo->query("SELECT slug,COALESCE(updated_at,publicado_em,created_at) AS lm FROM posts WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0 ORDER BY id DESC")->fetchAll() as $r) $add(contentUrl('noticia',$r['slug']), date('c',strtotime($r['lm'])), 'weekly','0.8');
    }
    if (($settings['seo_sitemap_paginas'] ?? '1') === '1') {
        foreach ($pdo->query("SELECT slug,COALESCE(updated_at,publicado_em,created_at) AS lm FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0 ORDER BY id DESC")->fetchAll() as $r) $add(contentUrl('pagina',$r['slug']), date('c',strtotime($r['lm'])), 'monthly','0.7');
    }
    if (($settings['seo_sitemap_eventos'] ?? '1') === '1') {
        foreach ($pdo->query("SELECT slug,COALESCE(updated_at,created_at) AS lm FROM eventos WHERE status='publicado' AND seo_noindex=0 ORDER BY data_inicio DESC")->fetchAll() as $r) $add(contentUrl('evento',$r['slug']), date('c',strtotime($r['lm'])), 'weekly','0.7');
    }
    if (($settings['seo_sitemap_galerias'] ?? '1') === '1') {
        foreach ($pdo->query("SELECT slug,COALESCE(updated_at,publicado_em,created_at) AS lm FROM galerias WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0 ORDER BY id DESC")->fetchAll() as $r) $add(contentUrl('galeria',$r['slug']), date('c',strtotime($r['lm'])), 'weekly','0.6');
    }
    if (($settings['seo_sitemap_formularios'] ?? '0') === '1') {
        foreach ($pdo->query("SELECT slug,COALESCE(updated_at,publicado_em,created_at) AS lm FROM formularios WHERE status='publicado' AND ativo=1 AND (publicado_em IS NULL OR publicado_em<=NOW()) ORDER BY id DESC")->fetchAll() as $r) $add(contentUrl('formulario',$r['slug']), date('c',strtotime($r['lm'])), 'monthly','0.4');
    }
} catch (Throwable $e) { /* mantém ao menos URLs estáticas */ }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $item) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($item['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if ($item['lastmod']) echo '    <lastmod>' . htmlspecialchars($item['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
    echo '    <changefreq>' . $item['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $item['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
