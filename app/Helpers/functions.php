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
 * Gera a URL pública amigável de um conteúdo usando a slug salva no banco.
 * Ex.: /noticia/minha-noticia, /pagina/quem-somos, /evento/culto-especial, /formulario/contato.
 */
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

    return url($type . '/' . rawurlencode($slug));
}

/**
 * Obtém a slug diretamente do caminho da URL atual, sem usar $_GET.
 * Funciona também quando o portal está instalado em uma subpasta.
 */
function routeSlug(string $type): string
{
    $allowed = ['noticia', 'pagina', 'evento', 'galeria', 'formulario'];
    if (!in_array($type, $allowed, true)) {
        return '';
    }

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

    $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), static fn($segment) => $segment !== ''));
    if (count($segments) !== 2 || strtolower($segments[0]) !== $type) {
        return '';
    }

    $slug = strtolower(rawurldecode($segments[1]));
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return '';
    }

    return $slug;
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

function formatDateBr(?string $date): string
{
    if (!$date) {
        return '';
    }
    return (new DateTime($date))->format('d/m/Y H:i');
}

function formatDateOnlyBr(?string $date): string
{
    if (!$date) {
        return '';
    }
    return (new DateTime($date))->format('d/m/Y');
}

function formatTimeBr(?string $date): string
{
    if (!$date) {
        return '';
    }
    return (new DateTime($date))->format('H:i');
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
function siteConfigAll(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT chave, valor FROM configuracoes ORDER BY chave');
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[(string)$row['chave']] = (string)($row['valor'] ?? '');
        }
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
