<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
$settings = siteConfigAll($pdo);
if (($settings['seo_feed_ativo'] ?? '1') !== '1') {
    http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); exit('Feed RSS desativado.');
}
$limit = max(5, min(100, (int)($settings['seo_feed_limite'] ?? 20)));
$full = ($settings['seo_feed_conteudo'] ?? 'resumo') === 'completo';
$includeImages = ($settings['seo_feed_imagens'] ?? '1') === '1';
$includeAuthor = ($settings['seo_feed_autor'] ?? '1') === '1';
$siteName = trim((string)($settings['site_nome'] ?? '')) ?: 'IECLB Parobé';
$path = trim(currentRelativePath(), '/');
$segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn($v)=>$v!==''));
$type = 'posts'; $context = null;
if ($path === 'eventos.feed.xml') $type='eventos';
elseif (count($segments)===3 && strtolower($segments[0])==='categoria' && strtolower($segments[2])==='feed.xml') { $type='categoria'; $context=strtolower(rawurldecode($segments[1])); }
elseif (count($segments)===3 && strtolower($segments[0])==='tag' && strtolower($segments[2])==='feed.xml') { $type='tag'; $context=strtolower(rawurldecode($segments[1])); }
elseif (!in_array($path, ['feed.xml','rss.xml'], true)) { http_response_code(404); exit; }
if ($type==='eventos' && ($settings['seo_feed_eventos'] ?? '1')!=='1') { http_response_code(404); exit; }
if ($type==='categoria' && ($settings['seo_feed_categorias'] ?? '1')!=='1') { http_response_code(404); exit; }
if ($type==='tag' && ($settings['seo_feed_tags'] ?? '1')!=='1') { http_response_code(404); exit; }

function rssXml(string $v): string { return htmlspecialchars($v, ENT_XML1|ENT_QUOTES, 'UTF-8'); }
function rssCdata(string $v): string { return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $v) . ']]>'; }
function rssCleanHtml(string $html): string { return trim((string)(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is','',$html) ?? $html)); }
function rssDate(?string $v): string { try { return (new DateTime((string)$v))->format(DateTimeInterface::RSS); } catch(Throwable $e) { return date(DateTimeInterface::RSS); } }

$title = $siteName . ' - Notícias'; $description = 'Últimas notícias do ' . $siteName; $channelLink = url(); $self = rssFeedUrl('posts'); $items=[];
if ($type==='eventos') {
    $title=$siteName.' - Eventos e Cultos'; $description='Próximos eventos e cultos do '.$siteName; $channelLink=url('agenda'); $self=rssFeedUrl('eventos');
    $sql="SELECT e.titulo,e.slug,e.resumo,e.descricao,e.data_inicio,e.created_at,e.updated_at,e.local,c.nome comunidade_nome,ec.nome categoria_nome,m.caminho imagem,m.mime_type,m.tamanho FROM eventos e LEFT JOIN comunidades c ON c.id=e.comunidade_id LEFT JOIN evento_categorias ec ON ec.id=e.categoria_evento_id LEFT JOIN midias m ON m.id=e.imagem_capa_id WHERE e.status='publicado' AND e.seo_noindex=0 AND e.data_inicio>=NOW() ORDER BY e.data_inicio ASC LIMIT {$limit}";
    $items=$pdo->query($sql)->fetchAll();
} else {
    $where="p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0"; $params=[];
    if ($type==='categoria') {
        if (!$context || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$context)) { http_response_code(404); exit; }
        $st=$pdo->prepare('SELECT id,nome,slug FROM categorias WHERE slug=:slug LIMIT 1'); $st->execute(['slug'=>$context]); $cat=$st->fetch(); if(!$cat){http_response_code(404);exit;}
        $where.=' AND EXISTS (SELECT 1 FROM post_categorias pcx WHERE pcx.post_id=p.id AND pcx.categoria_id=:context_id)'; $params['context_id']=$cat['id']; $title=$siteName.' - '.$cat['nome']; $description='Notícias da categoria '.$cat['nome']; $channelLink=categoryUrl((string)$cat['slug']); $self=rssFeedUrl('categoria',(string)$cat['slug']);
    } elseif ($type==='tag') {
        if (!$context || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$context)) { http_response_code(404); exit; }
        $st=$pdo->prepare('SELECT id,nome,slug FROM tags WHERE slug=:slug LIMIT 1'); $st->execute(['slug'=>$context]); $tag=$st->fetch(); if(!$tag){http_response_code(404);exit;}
        $where.=' AND EXISTS (SELECT 1 FROM post_tags ptx WHERE ptx.post_id=p.id AND ptx.tag_id=:context_id)'; $params['context_id']=$tag['id']; $title=$siteName.' - #'.$tag['nome']; $description='Notícias marcadas com '.$tag['nome']; $channelLink=tagUrl((string)$tag['slug']); $self=rssFeedUrl('tag',(string)$tag['slug']);
    }
    $sql="SELECT p.titulo,p.slug,p.resumo,p.conteudo,p.publicado_em,p.created_at,p.updated_at,(SELECT GROUP_CONCAT(c2.nome ORDER BY pc2.principal DESC,c2.nome SEPARATOR '||') FROM post_categorias pc2 INNER JOIN categorias c2 ON c2.id=pc2.categoria_id WHERE pc2.post_id=p.id) categorias_csv,u.nome autor_nome,m.caminho imagem,m.mime_type,m.tamanho,(SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR '||') FROM post_tags pt INNER JOIN tags t ON t.id=pt.tag_id WHERE pt.post_id=p.id) tags_csv FROM posts p LEFT JOIN usuarios u ON u.id=p.autor_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE {$where} ORDER BY COALESCE(p.publicado_em,p.created_at) DESC LIMIT {$limit}";
    $st=$pdo->prepare($sql); $st->execute($params); $items=$st->fetchAll();
}

header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Robots-Tag: noindex, follow');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
echo '<title>'.rssXml($title)."</title>\n<link>".rssXml($channelLink)."</link>\n<description>".rssXml($description)."</description>\n<language>pt-BR</language>\n";
echo '<atom:link href="'.rssXml($self).'" rel="self" type="application/rss+xml" />' . "\n";
echo '<lastBuildDate>'.rssDate($items[0]['updated_at'] ?? $items[0]['publicado_em'] ?? $items[0]['created_at'] ?? null)."</lastBuildDate>\n";
foreach($items as $item) {
    if ($type==='eventos') {
        $link=contentUrl('evento',(string)$item['slug']); $pub=$item['updated_at']?:$item['created_at'];
        $summary=trim((string)$item['resumo']) ?: portalExcerpt((string)$item['descricao'],220);
        $content=$full ? rssCleanHtml((string)$item['descricao']) : '<p>'.e($summary).'</p>';
        $when='Data: '.formatDateBr((string)$item['data_inicio']); if($item['local']) $when.=' · '.$item['local'];
        $content='<p><strong>'.e($when).'</strong></p>'.$content;
        $categories=array_filter([(string)($item['categoria_nome']??''),(string)($item['comunidade_nome']??'')]);
    } else {
        $link=contentUrl('noticia',(string)$item['slug']); $pub=$item['publicado_em']?:$item['created_at'];
        $summary=trim((string)$item['resumo']) ?: portalExcerpt((string)$item['conteudo'],220);
        $content=$full ? rssCleanHtml((string)$item['conteudo']) : '<p>'.e($summary).'</p>';
        $postCats = isset($item['categorias_csv']) && $item['categorias_csv'] !== null ? explode('||', (string)$item['categorias_csv']) : [];
        $categories=array_filter(array_merge($postCats, isset($item['tags_csv'])&&$item['tags_csv']!==null ? explode('||',(string)$item['tags_csv']) : []));
    }
    if($includeImages && !empty($item['imagem'])) { $img=mediaUrl((string)$item['imagem']); $content='<p><img src="'.e($img).'" alt="'.e((string)$item['titulo']).'"></p>'.$content; }
    echo "<item>\n<title>".rssXml((string)$item['titulo'])."</title>\n<link>".rssXml($link)."</link>\n<guid isPermaLink=\"true\">".rssXml($link)."</guid>\n<pubDate>".rssDate((string)$pub)."</pubDate>\n";
    echo '<description>'.rssCdata($summary)."</description>\n<content:encoded>".rssCdata($content)."</content:encoded>\n";
    if($includeAuthor && $type!=='eventos' && !empty($item['autor_nome'])) echo '<dc:creator>'.rssCdata((string)$item['autor_nome'])."</dc:creator>\n";
    foreach($categories as $catName) echo '<category>'.rssCdata((string)$catName)."</category>\n";
    if($includeImages && !empty($item['imagem'])) echo '<enclosure url="'.rssXml(mediaUrl((string)$item['imagem'])).'" length="'.max(0,(int)($item['tamanho']??0)).'" type="'.rssXml((string)($item['mime_type']??'image/jpeg')).'" />' . "\n";
    echo "</item>\n";
}
echo "</channel>\n</rss>\n";
