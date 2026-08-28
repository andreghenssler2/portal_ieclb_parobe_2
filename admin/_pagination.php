<?php

declare(strict_types=1);

/**
 * Paginação administrativa padronizada.
 *
 * @return array{page:int,pages:int,limit:int,offset:int,total:int,from:int,to:int}
 */
function adminPaginationState(int $totalItems, int $perPage = 50, string $parameter = 'pagina'): array
{
    $totalItems = max(0, $totalItems);
    $perPage = max(1, min(500, $perPage));
    $totalPages = max(1, (int)ceil($totalItems / $perPage));

    $raw = $_GET[$parameter] ?? 1;
    $requestedPage = is_scalar($raw) ? (int)$raw : 1;
    $page = max(1, min($totalPages, $requestedPage));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'pages' => $totalPages,
        'limit' => $perPage,
        'offset' => $offset,
        'total' => $totalItems,
        'from' => $totalItems > 0 ? $offset + 1 : 0,
        'to' => min($totalItems, $offset + $perPage),
    ];
}

/**
 * Monta URL interna preservando apenas filtros explicitamente informados.
 */
function adminPaginationUrl(string $basePath, int $page, array $query = [], string $parameter = 'pagina'): string
{
    unset($query[$parameter]);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            unset($query[$key]);
        }
    }

    if ($page > 1) {
        $query[$parameter] = $page;
    }

    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return url($basePath . ($qs !== '' ? '?' . $qs : ''));
}

/**
 * Renderiza os controles Bootstrap. Só aparece quando há mais de 50 itens
 * (ou mais de uma página para o limite informado).
 */
function adminPaginationHtml(string $basePath, array $pagination, array $query = [], string $parameter = 'pagina'): string
{
    $current = max(1, (int)($pagination['page'] ?? 1));
    $pages = max(1, (int)($pagination['pages'] ?? 1));
    $total = max(0, (int)($pagination['total'] ?? 0));
    $from = max(0, (int)($pagination['from'] ?? 0));
    $to = max(0, (int)($pagination['to'] ?? 0));

    if ($pages <= 1) {
        return '';
    }

    $numbers = [1, $pages];
    for ($i = max(1, $current - 2); $i <= min($pages, $current + 2); $i++) {
        $numbers[] = $i;
    }
    $numbers = array_values(array_unique($numbers));
    sort($numbers, SORT_NUMERIC);

    ob_start();
    ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-3 mb-1">
        <div class="small text-secondary">
            Exibindo <?= $from ?>–<?= $to ?> de <?= $total ?> itens · 50 por página
        </div>
        <nav aria-label="Paginação do administrador">
            <ul class="pagination pagination-sm mb-0 flex-wrap">
                <li class="page-item <?= $current <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(adminPaginationUrl($basePath, max(1, $current - 1), $query, $parameter)) ?>" aria-label="Página anterior">Anterior</a>
                </li>
                <?php $previous = 0; ?>
                <?php foreach ($numbers as $number): ?>
                    <?php if ($previous > 0 && $number > $previous + 1): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item <?= $number === $current ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e(adminPaginationUrl($basePath, $number, $query, $parameter)) ?>"><?= $number ?></a>
                    </li>
                    <?php $previous = $number; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $current >= $pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(adminPaginationUrl($basePath, min($pages, $current + 1), $query, $parameter)) ?>" aria-label="Próxima página">Próxima</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php
    return (string)ob_get_clean();
}
