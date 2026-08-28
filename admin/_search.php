<?php

declare(strict_types=1);

/**
 * Normaliza o termo usado nas pesquisas administrativas.
 */
function adminSearchTerm(string $parameter = 'q', int $maxLength = 160): string
{
    $raw = $_GET[$parameter] ?? '';
    if (!is_scalar($raw)) {
        return '';
    }

    $term = trim((string)$raw);
    $term = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $term) ?? $term;
    $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
    $term = trim($term);

    $maxLength = max(20, min(500, $maxLength));
    if (function_exists('mb_substr')) {
        return mb_substr($term, 0, $maxLength, 'UTF-8');
    }
    return substr($term, 0, $maxLength);
}

/**
 * Comparação textual usada quando a lista é filtrada em PHP (ex.: árvore de categorias).
 */
function adminSearchMatches(string $term, mixed ...$values): bool
{
    if ($term === '') {
        return true;
    }

    foreach ($values as $value) {
        $text = (string)$value;
        if ($text === '') {
            continue;
        }
        if (function_exists('mb_stripos')) {
            if (mb_stripos($text, $term, 0, 'UTF-8') !== false) {
                return true;
            }
        } elseif (stripos($text, $term) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Renderiza uma caixa de pesquisa GET mantendo somente os filtros informados.
 */
function adminSearchHtml(
    string $basePath,
    string $term,
    array $query = [],
    string $placeholder = 'Pesquisar…',
    ?int $totalItems = null,
    string $parameter = 'q'
): string {
    unset($query[$parameter], $query['pagina']);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            unset($query[$key]);
        }
    }

    $clearQuery = $query;
    $clearQs = http_build_query($clearQuery, '', '&', PHP_QUERY_RFC3986);
    $clearUrl = url($basePath . ($clearQs !== '' ? '?' . $clearQs : ''));

    ob_start();
    ?>
    <div class="card border-0 shadow-sm mb-3 admin-list-search">
        <div class="card-body py-3">
            <form method="get" action="<?= e(url($basePath)) ?>" class="row g-2 align-items-center">
                <?php foreach ($query as $key => $value): ?>
                    <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
                <?php endforeach; ?>
                <div class="col">
                    <label class="visually-hidden" for="admin-list-search-input">Pesquisar</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input
                            id="admin-list-search-input"
                            class="form-control"
                            type="search"
                            name="<?= e($parameter) ?>"
                            value="<?= e($term) ?>"
                            maxlength="160"
                            placeholder="<?= e($placeholder) ?>"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit">Pesquisar</button></div>
                <?php if ($term !== ''): ?>
                    <div class="col-auto"><a class="btn btn-outline-secondary" href="<?= e($clearUrl) ?>">Limpar</a></div>
                <?php endif; ?>
            </form>
            <?php if ($term !== '' && $totalItems !== null): ?>
                <div class="small text-secondary mt-2">
                    <?= (int)$totalItems ?> resultado<?= (int)$totalItems === 1 ? '' : 's' ?> para <strong>“<?= e($term) ?>”</strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
