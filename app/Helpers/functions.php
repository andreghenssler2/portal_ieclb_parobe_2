<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}



/**
 * Prefixo configurável dos links permanentes.
 */
function permalinkPrefix(string $type, ?PDO $pdo = null): string
{
    $defaults = [
        'noticia' => 'noticia',
        'pagina' => 'pagina',
        'evento' => 'evento',
        'galeria' => 'galeria',
        'formulario' => 'formulario',
    ];
    if (!isset($defaults[$type])) {
        throw new InvalidArgumentException('Tipo de conteúdo inválido.');
    }

    try {
        $pdo ??= Database::connection();
        $key = 'permalink_' . $type;
        $configured = trim(siteConfig($pdo, $key, $defaults[$type]));
        if ($type === 'pagina' && $configured === '__root__') {
            return '';
        }
        if ($configured !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $configured)) {
            return $configured;
        }
    } catch (Throwable $e) {
        // Mantém o formato padrão caso o banco ainda não esteja disponível.
    }
    return $defaults[$type];
}

/** Gera a URL pública amigável usando a slug salva no banco. */
function contentUrl(string $type, string $slug): string
{
    $allowed = ['noticia', 'pagina', 'evento', 'galeria', 'formulario'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Tipo de conteúdo inválido.');
    }
    $slug = trim($slug);
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        throw new InvalidArgumentException('Slug inválida.');
    }
    $prefix = permalinkPrefix($type);
    $path = $prefix === '' ? rawurlencode($slug) : $prefix . '/' . rawurlencode($slug);
    return url($path);
}

/** Caminho atual relativo à raiz do portal. */
function currentRelativePath(): string
{
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?? '');
    $basePath = '/' . trim($basePath, '/');
    if ($basePath !== '/') {
        if ($requestPath === $basePath) {
            $requestPath = '/';
        } elseif (str_starts_with($requestPath, $basePath . '/')) {
            $requestPath = substr($requestPath, strlen($basePath));
        }
    }
    return '/' . ltrim($requestPath, '/');
}

/**
 * Obtém a slug diretamente do caminho atual, sem usar $_GET.
 * Aceita o formato configurado e o prefixo histórico para compatibilidade.
 */
function routeSlug(string $type): string
{
    $allowed = ['noticia', 'pagina', 'evento', 'galeria', 'formulario'];
    if (!in_array($type, $allowed, true)) {
        return '';
    }
    $segments = array_values(array_filter(explode('/', trim(currentRelativePath(), '/')), static fn($v) => $v !== ''));
    $prefix = permalinkPrefix($type);
    $slug = '';

    if ($prefix === '' && $type === 'pagina' && count($segments) === 1) {
        $slug = (string)$segments[0];
    } elseif ($prefix !== '' && count($segments) === 2 && strtolower((string)$segments[0]) === $prefix) {
        $slug = (string)$segments[1];
    } elseif (count($segments) === 2 && strtolower((string)$segments[0]) === $type) {
        // Compatibilidade com URLs das versões anteriores.
        $slug = (string)$segments[1];
    }

    $slug = strtolower(rawurldecode($slug));
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ? $slug : '';
}

/** Redireciona URLs antigas para o formato canônico configurado. */
function redirectCanonicalContent(string $type, string $slug): void
{
    if (PHP_SAPI === 'cli' || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    $expectedPath = (string)(parse_url(contentUrl($type, $slug), PHP_URL_PATH) ?? '');
    $currentPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $norm = static fn(string $v): string => '/' . trim($v, '/');
    if ($norm($expectedPath) !== $norm($currentPath)) {
        header('Location: ' . contentUrl($type, $slug), true, 301);
        exit;
    }
}

function slugify(string $text): string
{
    $text = trim($text);
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

function uniqueSlug(PDO $pdo, string $table, string $title, ?int $ignoreId = null): string
{
    $allowed = ['posts', 'paginas', 'comunidades', 'categorias', 'eventos', 'menus', 'galerias', 'formularios'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Tabela inválida para slug.');
    }

    $base = slugify($title) ?: 'item';
    $slug = $base;
    $suffix = 1;

    while (true) {
        $sql = "SELECT id FROM {$table} WHERE slug = :slug";
        $params = ['slug' => $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $suffix++;
    }
}

function portalDateFormat(): string
{
    try {
        $value = siteConfig(Database::connection(), 'date_format', 'd/m/Y');
        return in_array($value, ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'], true) ? $value : 'd/m/Y';
    } catch (Throwable $e) { return 'd/m/Y'; }
}

function portalTimeFormat(): string
{
    try {
        $value = siteConfig(Database::connection(), 'time_format', 'H:i');
        return in_array($value, ['H:i', 'H\hi', 'g:i A'], true) ? $value : 'H:i';
    } catch (Throwable $e) { return 'H:i'; }
}

function formatDateBr(?string $date): string
{
    if (!$date) return '';
    return (new DateTime($date))->format(portalDateFormat() . ' ' . portalTimeFormat());
}

function formatDateOnlyBr(?string $date): string
{
    if (!$date) return '';
    return (new DateTime($date))->format(portalDateFormat());
}

function formatTimeBr(?string $date): string
{
    if (!$date) return '';
    return (new DateTime($date))->format(portalTimeFormat());
}

function portalExcerpt(?string $html, ?int $limit = null): string
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($text === '') return '';
    if ($limit === null) {
        try { $limit = (int)siteConfig(Database::connection(), 'writing_excerpt_length', '180'); }
        catch (Throwable $e) { $limit = 180; }
    }
    $limit = max(80, min(500, (int)$limit));
    return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

function formatMonthShortBr(?string $date): string
{
    if (!$date) {
        return '';
    }

    $months = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr',
        5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago',
        9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];
    $month = (int)(new DateTime($date))->format('n');
    return $months[$month] ?? '';
}

function eventTypeLabel(?string $type): string
{
    return $type === 'culto' ? 'Culto' : 'Evento';
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $value >= 10 ? 0 : 1, ',', '.') . ' ' . $unit;
        }
        $value /= 1024;
    }

    return $bytes . ' B';
}

function mediaUrl(?string $path): string
{
    if (!$path) {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return url(ltrim($path, '/'));
}

function logAction(PDO $pdo, string $acao, ?string $entidade = null, ?int $entidadeId = null, ?string $detalhes = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO logs (usuario_id, acao, entidade, entidade_id, detalhes, ip)
             VALUES (:usuario_id, :acao, :entidade, :entidade_id, :detalhes, :ip)'
        );
        $stmt->execute([
            'usuario_id' => Auth::id(),
            'acao' => mb_substr($acao, 0, 120),
            'entidade' => $entidade !== null ? mb_substr($entidade, 0, 100) : null,
            'entidade_id' => $entidadeId,
            'detalhes' => $detalhes,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        // O log não deve interromper a operação principal.
    }
}

/**
 * Retorna todas as configurações públicas do portal indexadas pela chave.
 */
function siteConfigAll(PDO $pdo, bool $refresh = false): array
{
    $key = spl_object_id($pdo);
    if (!$refresh && isset($GLOBALS['__portal_config_cache'][$key])) {
        return $GLOBALS['__portal_config_cache'][$key];
    }
    try {
        $stmt = $pdo->query('SELECT chave, valor FROM configuracoes ORDER BY chave');
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[(string)$row['chave']] = (string)($row['valor'] ?? '');
        }
        $GLOBALS['__portal_config_cache'][$key] = $items;
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

function siteConfig(PDO $pdo, string $key, string $default = ''): string
{
    $settings = siteConfigAll($pdo);
    return array_key_exists($key, $settings) ? (string)$settings[$key] : $default;
}

function saveSiteConfig(PDO $pdo, string $key, ?string $value, string $type = 'texto'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave, valor, tipo)
         VALUES (:chave, :valor, :tipo)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), tipo = VALUES(tipo)'
    );
    $stmt->execute([
        'chave' => $key,
        'valor' => $value,
        'tipo' => $type,
    ]);
    unset($GLOBALS['__portal_config_cache'][spl_object_id($pdo)]);
}

function mediaUploadMaxSize(?PDO $pdo = null): int
{
    $serverLimit = defined('UPLOAD_MAX_SIZE') ? (int)UPLOAD_MAX_SIZE : 10 * 1024 * 1024;
    try {
        $pdo ??= Database::connection();
        $mb = max(1, min(100, (int)siteConfig($pdo, 'media_upload_max_mb', (string)max(1, (int)floor($serverLimit / 1048576)))));
        return min($serverLimit, $mb * 1024 * 1024);
    } catch (Throwable $e) { return $serverLimit; }
}

function mediaOrganizeByDate(?PDO $pdo = null): bool
{
    try { $pdo ??= Database::connection(); return siteConfig($pdo, 'media_organize_year_month', '1') === '1'; }
    catch (Throwable $e) { return true; }
}

function mediaDocumentsAllowed(?PDO $pdo = null): bool
{
    try { $pdo ??= Database::connection(); return siteConfig($pdo, 'media_allow_documents', '1') === '1'; }
    catch (Throwable $e) { return true; }
}


/**
 * Carrega o menu público e organiza itens em até um nível de submenu.
 */
function publicMenu(PDO $pdo, string $location = 'principal'): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT mi.*, p.slug AS pagina_slug, p.status AS pagina_status, p.publicado_em AS pagina_publicado_em
             FROM menus m
             INNER JOIN menu_itens mi ON mi.menu_id = m.id
             LEFT JOIN paginas p ON p.id = mi.pagina_id
             WHERE m.localizacao = :localizacao
               AND m.ativo = 1
               AND mi.ativo = 1
               AND (
                    mi.tipo = 'link'
                    OR (
                        mi.tipo = 'pagina'
                        AND p.id IS NOT NULL
                        AND p.status = 'publicado'
                        AND (p.publicado_em IS NULL OR p.publicado_em <= NOW())
                    )
               )
             ORDER BY mi.ordem ASC, mi.id ASC"
        );
        $stmt->execute(['localizacao' => $location]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $parents = [];
    $children = [];
    foreach ($rows as $row) {
        $row['children'] = [];
        $parentId = (int)($row['parent_id'] ?? 0);
        if ($parentId > 0) {
            $children[$parentId][] = $row;
        } else {
            $parents[(int)$row['id']] = $row;
        }
    }

    foreach ($parents as $id => &$parent) {
        $parent['children'] = $children[$id] ?? [];
    }
    unset($parent);

    // Se um item filho perdeu o pai, mantém o link visível no nível principal.
    foreach ($children as $parentId => $orphanChildren) {
        if (!isset($parents[$parentId])) {
            foreach ($orphanChildren as $child) {
                $parents[(int)$child['id']] = $child;
            }
        }
    }

    return array_values($parents);
}

function menuItemUrl(array $item): string
{
    if (($item['tipo'] ?? '') === 'pagina' && !empty($item['pagina_slug'])) {
        return contentUrl('pagina', (string)$item['pagina_slug']);
    }

    $target = trim((string)($item['url'] ?? ''));
    if ($target === '' || $target === '/') {
        return url();
    }

    if (preg_match('#^(https?://|mailto:|tel:)#i', $target) || str_starts_with($target, '#')) {
        return $target;
    }

    return url(ltrim($target, '/'));
}

/**
 * Resolve um destino público interno ou externo de forma consistente.
 */
function publicTargetUrl(?string $target): string
{
    $target = trim((string)$target);
    if ($target === '' || $target === '/') {
        return url();
    }

    if (preg_match('#^(https?://|mailto:|tel:)#i', $target) || str_starts_with($target, '#')) {
        return $target;
    }

    return url(ltrim($target, '/'));
}

function currentCanonicalUrl(): string
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?? '');

    if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}


/**
 * Retorna o slug do tema público ativo. Se a configuração estiver inválida,
 * volta automaticamente para o tema ieclb.
 */
function activeThemeSlug(PDO $pdo): string
{
    $configured = trim(siteConfig($pdo, 'active_theme', 'ieclb'));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $configured)) {
        $configured = 'ieclb';
    }

    $themeRoot = dirname(__DIR__, 2) . '/theme';
    if (!is_file($themeRoot . '/' . $configured . '/theme.json')) {
        return 'ieclb';
    }

    return $configured;
}

/** Lista os temas instalados a partir de theme/<slug>/theme.json. */
function installedThemes(): array
{
    $root = dirname(__DIR__, 2) . '/theme';
    $themes = [];
    if (!is_dir($root)) {
        return $themes;
    }

    foreach (glob($root . '/*/theme.json') ?: [] as $manifestPath) {
        $slug = basename(dirname($manifestPath));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            continue;
        }
        $data = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($data)) {
            continue;
        }
        $data['slug'] = $slug;
        $data['name'] = trim((string)($data['name'] ?? $slug)) ?: $slug;
        $data['version'] = trim((string)($data['version'] ?? '1.0.0'));
        $data['description'] = trim((string)($data['description'] ?? ''));
        $data['author'] = trim((string)($data['author'] ?? ''));
        $data['colors'] = is_array($data['colors'] ?? null) ? $data['colors'] : [];
        $themes[$slug] = $data;
    }
    uasort($themes, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
    return $themes;
}

/** Resolve um arquivo do tema ativo com fallback seguro para theme/ieclb. */
function themeFile(PDO $pdo, string $file): string
{
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
        throw new InvalidArgumentException('Arquivo de tema inválido.');
    }

    $root = dirname(__DIR__, 2) . '/theme';
    $active = activeThemeSlug($pdo);
    $candidate = $root . '/' . $active . '/' . $file;
    if (is_file($candidate)) {
        return $candidate;
    }

    $fallback = $root . '/ieclb/' . $file;
    if (!is_file($fallback)) {
        throw new RuntimeException('Arquivo obrigatório do tema não encontrado: ' . $file);
    }
    return $fallback;
}

/** Retorna a URL de um recurso do tema ativo, com fallback para ieclb. */
function themeAssetUrl(PDO $pdo, string $asset): string
{
    if (!preg_match('#^[a-zA-Z0-9_./-]+$#', $asset) || str_contains($asset, '..')) {
        return '';
    }
    $root = dirname(__DIR__, 2) . '/theme';
    $active = activeThemeSlug($pdo);
    if (is_file($root . '/' . $active . '/' . $asset)) {
        return url('theme/' . rawurlencode($active) . '/' . ltrim($asset, '/'));
    }
    if (is_file($root . '/ieclb/' . $asset)) {
        return url('theme/ieclb/' . ltrim($asset, '/'));
    }
    return '';
}

/**
 * Widgets ativos da página inicial. Em instalações ainda não migradas retorna
 * a mesma ordem histórica do portal, preservando compatibilidade.
 */
function homeWidgets(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT id, tipo, titulo, conteudo, ativo, ordem, configuracao
             FROM widgets
             WHERE area='home' AND ativo=1
             ORDER BY ordem ASC, id ASC"
        )->fetchAll();
        if ($rows) {
            foreach ($rows as &$row) {
                $decoded = json_decode((string)($row['configuracao'] ?? ''), true);
                $row['config'] = is_array($decoded) ? $decoded : [];
            }
            unset($row);
            return $rows;
        }
    } catch (Throwable $e) {
        // Compatibilidade antes de executar a atualização v0.11.0.
    }

    $defaults = [
        ['tipo'=>'banners','titulo'=>'','ordem'=>10],
        ['tipo'=>'apresentacao','titulo'=>'','ordem'=>20],
        ['tipo'=>'destaque','titulo'=>'','ordem'=>30],
        ['tipo'=>'agenda','titulo'=>'Próximos cultos e eventos','ordem'=>40],
        ['tipo'=>'noticias','titulo'=>'Últimas notícias','ordem'=>50],
        ['tipo'=>'galerias','titulo'=>'Galerias de fotos','ordem'=>60],
        ['tipo'=>'comunidades','titulo'=>'Nossas comunidades','ordem'=>70],
    ];
    return array_map(static function(array $row): array {
        return $row + ['id'=>0,'conteudo'=>'','ativo'=>1,'config'=>[]];
    }, $defaults);
}
