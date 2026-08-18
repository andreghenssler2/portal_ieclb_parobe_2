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
 * Ex.: /noticia/minha-noticia, /pagina/quem-somos, /evento/culto-especial.
 */
function contentUrl(string $type, string $slug): string
{
    $allowed = ['noticia', 'pagina', 'evento'];
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
    $allowed = ['noticia', 'pagina', 'evento'];
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
    $allowed = ['posts', 'paginas', 'comunidades', 'categorias', 'eventos'];
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
