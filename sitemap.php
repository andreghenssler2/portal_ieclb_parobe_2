<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
$settings = siteConfigAll($pdo);
/* PORTAL_GERAL_SITEMAP_DB_REFRESH_R15R3
 * As opções do Geral são lidas diretamente do banco para evitar qualquer
 * valor antigo mantido no cache de configurações da requisição.
 */
$generalSitemapSettingKeys = [
    'seo_sitemap_geral_home',
    'seo_sitemap_geral_agenda',
    'seo_sitemap_geral_comunidades',
    'seo_sitemap_geral_grupos',
    'seo_sitemap_geral_galerias',
    'seo_sitemap_geral_documentos',
    'seo_sitemap_geral_liderancas',
];

try {
    $placeholders = implode(
        ',',
        array_fill(0, count($generalSitemapSettingKeys), '?')
    );

    $generalSettingsStmt = $pdo->prepare(
        "SELECT chave,valor
         FROM configuracoes
         WHERE chave IN ({$placeholders})"
    );

    $generalSettingsStmt->execute(
        $generalSitemapSettingKeys
    );

    foreach ($generalSettingsStmt->fetchAll() ?: [] as $generalSettingRow) {
        $generalSettingKey =
            (string)($generalSettingRow['chave'] ?? '');

        if (
            $generalSettingKey !== ''
            && in_array(
                $generalSettingKey,
                $generalSitemapSettingKeys,
                true
            )
        ) {
            $settings[$generalSettingKey] =
                (string)($generalSettingRow['valor'] ?? '0');
        }
    }
} catch (Throwable $ignored) {
    // Mantém os defaults já carregados se o banco estiver em migração.
}

if (($settings['seo_sitemap_ativo'] ?? '1') !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sitemap desativado.');
}

header('Content-Type: application/xml; charset=UTF-8');
/* PORTAL_SITEMAP_NOCACHE_R15R3 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, follow');

/** Escape seguro para XML. */
function sitemapXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Converte uma data do banco para W3C/ISO 8601. */
function sitemapDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    try {
        return (new DateTime($value))->format(DateTimeInterface::ATOM);
    } catch (Throwable $e) {
        return null;
    }
}

/** Normaliza URL de imagem encontrada no conteúdo HTML. */
function sitemapImageUrl(string $src): ?string
{
    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '' || preg_match('#^(?:data|blob|javascript):#i', $src)) {
        return null;
    }
    if (str_starts_with($src, '//')) {
        $scheme = (string)(parse_url(BASE_URL, PHP_URL_SCHEME) ?: 'https');
        return $scheme . ':' . $src;
    }
    if (preg_match('#^https?://#i', $src)) {
        return $src;
    }
    return mediaUrl(ltrim($src, '/'));
}

/** Extrai imagens <img src="..."> do HTML do editor. */
function sitemapImagesFromHtml(?string $html): array
{
    if ($html === null || trim($html) === '') {
        return [];
    }
    $images = [];
    if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches)) {
        foreach ($matches[2] as $src) {
            $normalized = sitemapImageUrl((string)$src);
            if ($normalized !== null) {
                $images[$normalized] = $normalized;
            }
            if (count($images) >= 1000) {
                break;
            }
        }
    }
    return array_values($images);
}

/** Une e remove duplicatas de URLs de imagens, respeitando o limite por página. */
function sitemapMergeImages(array ...$groups): array
{
    $out = [];
    foreach ($groups as $group) {
        foreach ($group as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            $normalized = sitemapImageUrl($image);
            if ($normalized === null) {
                continue;
            }
            $out[$normalized] = $normalized;
            if (count($out) >= 1000) {
                break 2;
            }
        }
    }
    return array_values($out);
}

function sitemapCover(?string $path): array
{
    return $path ? [mediaUrl((string)$path)] : [];
}

/** Emite um registro <url>, incluindo extensão de imagens quando habilitada. */
function sitemapEmitUrl(
    string $loc,
    ?string $lastmod = null,
    string $changefreq = 'weekly',
    string $priority = '0.5',
    array $images = [],
    bool $includeImages = true
): void {
    echo "  <url>\n";
    echo '    <loc>' . sitemapXml($loc) . "</loc>\n";
    if ($lastmod) {
        echo '    <lastmod>' . sitemapXml($lastmod) . "</lastmod>\n";
    }
    echo '    <changefreq>' . sitemapXml($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sitemapXml($priority) . "</priority>\n";
    if ($includeImages) {
        $seen = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image === '' || isset($seen[$image])) {
                continue;
            }
            $seen[$image] = true;
            echo "    <image:image>\n";
            echo '      <image:loc>' . sitemapXml($image) . "</image:loc>\n";
            echo "    </image:image>\n";
            if (count($seen) >= 1000) {
                break;
            }
        }
    }
    echo "  </url>\n";
}

function sitemapBeginUrlset(): void
{
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
}

function sitemapEndUrlset(): void
{
    echo "</urlset>\n";
}

function sitemapMaxDate(PDO $pdo, string $sql): ?string
{
    try {
        $value = $pdo->query($sql)->fetchColumn();
        return sitemapDate($value !== false ? (string)$value : null);
    } catch (Throwable $e) {
        return null;
    }
}

$includeImages = ($settings['seo_sitemap_imagens'] ?? '1') === '1';
$requestFile = strtolower(basename((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/sitemap.xml'), PHP_URL_PATH) ?: '/sitemap.xml')));

// Compatibilidade com nomes no singular e com .sitemap.xml.
$aliases = [
    'post.sitemaps.xml' => 'posts.sitemaps.xml',
    'post.sitemap.xml' => 'posts.sitemaps.xml',
    'posts.sitemap.xml' => 'posts.sitemaps.xml',
    'pagina.sitemaps.xml' => 'paginas.sitemaps.xml',
    'pagina.sitemap.xml' => 'paginas.sitemaps.xml',
    'paginas.sitemap.xml' => 'paginas.sitemaps.xml',
    'evento.sitemaps.xml' => 'eventos.sitemaps.xml',
    'evento.sitemap.xml' => 'eventos.sitemaps.xml',
    'eventos.sitemap.xml' => 'eventos.sitemaps.xml',
    'galeria.sitemaps.xml' => 'galerias.sitemaps.xml',
    'galeria.sitemap.xml' => 'galerias.sitemaps.xml',
    'galerias.sitemap.xml' => 'galerias.sitemaps.xml',
    'comunidade.sitemaps.xml' => 'comunidades.sitemaps.xml',
    'comunidade.sitemap.xml' => 'comunidades.sitemaps.xml',
    'comunidades.sitemap.xml' => 'comunidades.sitemaps.xml',
    'grupo.sitemaps.xml' => 'grupos.sitemaps.xml',
    'grupo.sitemap.xml' => 'grupos.sitemaps.xml',
    'grupos.sitemap.xml' => 'grupos.sitemaps.xml',
    'categoria.sitemaps.xml' => 'categorias.sitemaps.xml',
    'categoria.sitemap.xml' => 'categorias.sitemaps.xml',
    'categorias.sitemap.xml' => 'categorias.sitemaps.xml',
    'tag.sitemaps.xml' => 'tags.sitemaps.xml',
    'tag.sitemap.xml' => 'tags.sitemaps.xml',
    'tags.sitemap.xml' => 'tags.sitemaps.xml',
    'lideranca.sitemaps.xml' => 'liderancas.sitemaps.xml',
    'lideranca.sitemap.xml' => 'liderancas.sitemaps.xml',
    'liderancas.sitemap.xml' => 'liderancas.sitemaps.xml',
    'documento.sitemaps.xml' => 'documentos.sitemaps.xml',
    'documento.sitemap.xml' => 'documentos.sitemaps.xml',
    'documentos.sitemap.xml' => 'documentos.sitemaps.xml',
    'formulario.sitemaps.xml' => 'formularios.sitemaps.xml',
    'formulario.sitemap.xml' => 'formularios.sitemaps.xml',
    'formularios.sitemap.xml' => 'formularios.sitemaps.xml',
    'geral.sitemap.xml' => 'geral.sitemaps.xml',
];
$requestFile = $aliases[$requestFile] ?? $requestFile;

$groups = [
    'geral.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_geral'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, 'SELECT MAX(updated_at) FROM configuracoes'),
    ],
    'posts.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_posts'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM posts WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0"),
    ],
    'paginas.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_paginas'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0"),
    ],
    'eventos.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_eventos'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,created_at)) FROM eventos WHERE status='publicado' AND seo_noindex=0"),
    ],
    'galerias.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_galerias'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM galerias WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0"),
    ],
    'comunidades.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_comunidades'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(updated_at) FROM comunidades WHERE ativa=1 AND seo_noindex=0"),
    ],
    'grupos.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_grupos'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(updated_at) FROM grupos WHERE ativo=1 AND seo_noindex=0"),
    ],
    'categorias.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_categorias'] ?? '1') === '1' && ($settings['seo_sitemap_posts'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(p.updated_at,p.publicado_em,p.created_at)) FROM categorias c INNER JOIN post_categorias pc ON pc.categoria_id=c.id INNER JOIN posts p ON p.id=pc.post_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0"),
    ],
    'tags.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_tags'] ?? '1') === '1' && ($settings['seo_sitemap_posts'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(p.updated_at,p.publicado_em,p.created_at)) FROM tags t INNER JOIN post_tags pt ON pt.tag_id=t.id INNER JOIN posts p ON p.id=pt.post_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0"),
    ],
    'liderancas.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_liderancas'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(updated_at) FROM liderancas WHERE ativo=1 AND seo_noindex=0"),
    ],
    'documentos.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_documentos'] ?? '1') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0"),
    ],
    'formularios.sitemaps.xml' => [
        'enabled' => ($settings['seo_sitemap_formularios'] ?? '0') === '1',
        'lastmod' => sitemapMaxDate($pdo, "SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM formularios WHERE status='publicado' AND ativo=1 AND (publicado_em IS NULL OR publicado_em<=NOW())"),
    ],
];

if ($requestFile === 'sitemap.xml' || $requestFile === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($groups as $file => $meta) {
        if (!$meta['enabled']) {
            continue;
        }
        echo "  <sitemap>\n";
        echo '    <loc>' . sitemapXml(url($file)) . "</loc>\n";
        if ($meta['lastmod']) {
            echo '    <lastmod>' . sitemapXml((string)$meta['lastmod']) . "</lastmod>\n";
        }
        echo "  </sitemap>\n";
    }
    echo "</sitemapindex>\n";
    exit;
}

if (!isset($groups[$requestFile]) || !$groups[$requestFile]['enabled']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sitemap não encontrado ou desativado.');
}

sitemapBeginUrlset();

try {
    if ($requestFile === 'geral.sitemaps.xml') {
        $logoImages = [];
        $logoId = (int)($settings['site_logo_id'] ?? 0);
        if ($includeImages && $logoId > 0) {
            $stmt = $pdo->prepare("SELECT caminho FROM midias WHERE id=:id AND mime_type LIKE 'image/%' LIMIT 1");
            $stmt->execute(['id' => $logoId]);
            $logoPath = $stmt->fetchColumn();
            if ($logoPath) {
                $logoImages[] = mediaUrl((string)$logoPath);
            }
        }
                /* PORTAL_GERAL_SITEMAP_EMIT_R15R2 */
        if (($settings['seo_sitemap_geral_home'] ?? '1') === '1') {
            sitemapEmitUrl(url(), $groups[$requestFile]['lastmod'], 'daily', '1.0', $logoImages, $includeImages);
        }
        if (($settings['seo_sitemap_geral_agenda'] ?? '1') === '1') {
            sitemapEmitUrl(url('agenda'), null, 'daily', '0.8', [], $includeImages);
        }
        if (($settings['seo_sitemap_geral_comunidades'] ?? '1') === '1') {
            sitemapEmitUrl(url('comunidades'), null, 'monthly', '0.7', [], $includeImages);
        }
        if (($settings['seo_sitemap_geral_grupos'] ?? '1') === '1') {
            sitemapEmitUrl(url('grupos'), null, 'monthly', '0.7', [], $includeImages);
        }
        if (($settings['seo_sitemap_geral_galerias'] ?? '1') === '1') {
            sitemapEmitUrl(url('galerias'), null, 'weekly', '0.7', [], $includeImages);
        }
        if (($settings['seo_sitemap_geral_documentos'] ?? '1') === '1') {
            sitemapEmitUrl(url('documentos'), null, 'weekly', '0.7', [], $includeImages);
        }
        if (($settings['seo_sitemap_geral_liderancas'] ?? '1') === '1') {
            sitemapEmitUrl(url('liderancas'), null, 'monthly', '0.7', [], $includeImages);
        }
    }

    if ($requestFile === 'posts.sitemaps.xml') {
        $sql = "SELECT p.slug,p.titulo,p.conteudo,COALESCE(p.updated_at,p.publicado_em,p.created_at) lm,m.caminho cover_path
                FROM posts p LEFT JOIN midias m ON m.id=p.imagem_capa_id
                WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0
                ORDER BY p.id DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $images = sitemapMergeImages(sitemapCover($row['cover_path'] ?? null), sitemapImagesFromHtml((string)($row['conteudo'] ?? '')));
            sitemapEmitUrl(contentUrl('noticia', (string)$row['slug']), sitemapDate((string)$row['lm']), 'weekly', '0.8', $images, $includeImages);
        }
    }

    if ($requestFile === 'paginas.sitemaps.xml') {
        $sql = "SELECT p.slug,p.titulo,p.conteudo,COALESCE(p.updated_at,p.publicado_em,p.created_at) lm,m.caminho cover_path
                FROM paginas p LEFT JOIN midias m ON m.id=p.imagem_capa_id
                WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0
                ORDER BY p.id DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $images = sitemapMergeImages(sitemapCover($row['cover_path'] ?? null), sitemapImagesFromHtml((string)($row['conteudo'] ?? '')));
            sitemapEmitUrl(contentUrl('pagina', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.7', $images, $includeImages);
        }
    }

    if ($requestFile === 'eventos.sitemaps.xml') {
        $sql = "SELECT e.slug,e.titulo,e.descricao,COALESCE(e.updated_at,e.created_at) lm,m.caminho cover_path
                FROM eventos e LEFT JOIN midias m ON m.id=e.imagem_capa_id
                WHERE e.status='publicado' AND e.seo_noindex=0
                ORDER BY e.data_inicio DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $images = sitemapMergeImages(sitemapCover($row['cover_path'] ?? null), sitemapImagesFromHtml((string)($row['descricao'] ?? '')));
            sitemapEmitUrl(contentUrl('evento', (string)$row['slug']), sitemapDate((string)$row['lm']), 'weekly', '0.7', $images, $includeImages);
        }
    }

    if ($requestFile === 'galerias.sitemaps.xml') {
        $sql = "SELECT g.id,g.slug,g.titulo,COALESCE(g.updated_at,g.publicado_em,g.created_at) lm,m.caminho cover_path
                FROM galerias g LEFT JOIN midias m ON m.id=g.imagem_capa_id
                WHERE g.status='publicado' AND (g.publicado_em IS NULL OR g.publicado_em<=NOW()) AND g.seo_noindex=0
                ORDER BY g.id DESC";
        $rows = $pdo->query($sql)->fetchAll();
        $photoStmt = $pdo->prepare("SELECT m.caminho FROM galeria_midias gm INNER JOIN midias m ON m.id=gm.midia_id WHERE gm.galeria_id=:id AND m.mime_type LIKE 'image/%' ORDER BY gm.ordem,gm.id LIMIT 1000");
        foreach ($rows as $row) {
            $photoStmt->execute(['id' => $row['id']]);
            $galleryImages = array_map(static fn(array $r): string => mediaUrl((string)$r['caminho']), $photoStmt->fetchAll());
            $images = sitemapMergeImages(sitemapCover($row['cover_path'] ?? null), $galleryImages);
            sitemapEmitUrl(contentUrl('galeria', (string)$row['slug']), sitemapDate((string)$row['lm']), 'weekly', '0.6', $images, $includeImages);
        }
    }


    if ($requestFile === 'comunidades.sitemaps.xml') {
        $sql = "SELECT c.slug,c.nome,c.conteudo,c.imagem,COALESCE(c.updated_at,c.created_at) lm,m.caminho cover_path
                FROM comunidades c LEFT JOIN midias m ON m.id=c.imagem_capa_id
                WHERE c.ativa=1 AND c.seo_noindex=0
                ORDER BY c.ordem,c.nome";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $cover = $row['cover_path'] ?? null;
            if (!$cover && !empty($row['imagem'])) $cover = (string)$row['imagem'];
            $images = sitemapMergeImages(sitemapCover($cover), sitemapImagesFromHtml((string)($row['conteudo'] ?? '')));
            sitemapEmitUrl(contentUrl('comunidade', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.7', $images, $includeImages);
        }
    }


    if ($requestFile === 'grupos.sitemaps.xml') {
        $sql = "SELECT g.slug,g.nome,g.conteudo,COALESCE(g.updated_at,g.created_at) lm,m.caminho cover_path FROM grupos g LEFT JOIN midias m ON m.id=g.imagem_capa_id WHERE g.ativo=1 AND g.seo_noindex=0 ORDER BY g.ordem,g.nome";
        foreach($pdo->query($sql)->fetchAll() as $row){
            $images=sitemapMergeImages(sitemapCover($row['cover_path']??null),sitemapImagesFromHtml((string)($row['conteudo']??'')));
            sitemapEmitUrl(contentUrl('grupo',(string)$row['slug']),sitemapDate((string)$row['lm']),'monthly','0.6',$images,$includeImages);
        }
    }


    if ($requestFile === 'categorias.sitemaps.xml') {
        $sql = "SELECT c.id,c.slug,MAX(COALESCE(p.updated_at,p.publicado_em,p.created_at)) lm FROM categorias c INNER JOIN post_categorias pc ON pc.categoria_id=c.id INNER JOIN posts p ON p.id=pc.post_id WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0 GROUP BY c.id,c.slug HAVING COUNT(p.id)>0 ORDER BY c.nome";
        $rows = $pdo->query($sql)->fetchAll();
        $categoryImages = $pdo->prepare("SELECT DISTINCT m.caminho FROM post_categorias pc INNER JOIN posts p ON p.id=pc.post_id INNER JOIN midias m ON m.id=p.imagem_capa_id WHERE pc.categoria_id=:categoria_id AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0 AND m.mime_type LIKE 'image/%' ORDER BY p.id DESC LIMIT 1000");
        foreach ($rows as $row) {
            $images = [];
            if ($includeImages) {
                $categoryImages->execute(['categoria_id' => $row['id']]);
                $images = array_map(static fn(array $r): string => mediaUrl((string)$r['caminho']), $categoryImages->fetchAll());
            }
            sitemapEmitUrl(categoryUrl((string)$row['slug']), sitemapDate((string)($row['lm'] ?? '')), 'weekly', '0.5', $images, $includeImages);
        }
    }

    if ($requestFile === 'tags.sitemaps.xml') {
        $sql = "SELECT t.id,t.slug,MAX(COALESCE(p.updated_at,p.publicado_em,p.created_at)) lm
                FROM tags t
                INNER JOIN post_tags pt ON pt.tag_id=t.id
                INNER JOIN posts p ON p.id=pt.post_id
                WHERE p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0
                GROUP BY t.id,t.slug HAVING COUNT(p.id)>0 ORDER BY t.nome";
        $rows = $pdo->query($sql)->fetchAll();
        $tagImages = $pdo->prepare("SELECT DISTINCT m.caminho FROM post_tags pt INNER JOIN posts p ON p.id=pt.post_id INNER JOIN midias m ON m.id=p.imagem_capa_id WHERE pt.tag_id=:tag_id AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em<=NOW()) AND p.seo_noindex=0 AND m.mime_type LIKE 'image/%' ORDER BY p.id DESC LIMIT 1000");
        foreach ($rows as $row) {
            $images = [];
            if ($includeImages) {
                $tagImages->execute(['tag_id' => $row['id']]);
                $images = array_map(static fn(array $r): string => mediaUrl((string)$r['caminho']), $tagImages->fetchAll());
            }
            sitemapEmitUrl(tagUrl((string)$row['slug']), sitemapDate((string)($row['lm'] ?? '')), 'weekly', '0.5', $images, $includeImages);
        }
    }

    if ($requestFile === 'liderancas.sitemaps.xml') {
        $sql = "SELECT l.slug,l.updated_at lm,m.caminho foto
                FROM liderancas l
                LEFT JOIN midias m ON m.id=l.foto_id
                WHERE l.ativo=1 AND l.seo_noindex=0
                ORDER BY l.ordem ASC,l.nome ASC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $images = !empty($row['foto']) ? [mediaUrl((string)$row['foto'])] : [];
            sitemapEmitUrl(contentUrl('lideranca', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.6', $images, $includeImages);
        }
    }
    if ($requestFile === 'documentos.sitemaps.xml') {
        $sql = "SELECT slug,COALESCE(updated_at,publicado_em,created_at) lm
                FROM documentos
                WHERE status='publicado'
                  AND (publicado_em IS NULL OR publicado_em<=NOW())
                  AND seo_noindex=0
                ORDER BY id DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            sitemapEmitUrl(contentUrl('documento', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.6', [], false);
        }
    }
    if ($requestFile === 'formularios.sitemaps.xml') {
        $sql = "SELECT slug,COALESCE(updated_at,publicado_em,created_at) lm FROM formularios WHERE status='publicado' AND ativo=1 AND (publicado_em IS NULL OR publicado_em<=NOW()) ORDER BY id DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            sitemapEmitUrl(contentUrl('formulario', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.4', [], $includeImages);
        }
    }
} catch (Throwable $e) {
    // O sitemap deve continuar retornando XML válido mesmo se um grupo não estiver disponível.
}

sitemapEndUrlset();
