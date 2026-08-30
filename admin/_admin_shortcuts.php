<?php

$adminShortcutEndpoint =
    url(
        'admin/api/atalhos.php'
    );

$adminShortcutPath =
    trim(
        (string)(
            $currentAdminPath
            ?? ''
        )
    );

$currentQuery =
    $_GET;

foreach (
    [
        '_token',
    ]
    as $removeQueryKey
) {
    unset(
        $currentQuery[$removeQueryKey]
    );
}

ksort($currentQuery);

$adminShortcutRoute =
    'admin/'
    . ltrim(
        $adminShortcutPath !== ''
            ? $adminShortcutPath
            : 'index.php',
        '/'
    );

if ($currentQuery) {
    $adminShortcutRoute .=
        '?'
        . http_build_query(
            $currentQuery,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

$adminShortcutTitle =
    trim(
        (string)(
            $pageTitle
            ?? 'Painel'
        )
    );

if ($adminShortcutTitle === '') {
    $adminShortcutTitle = 'Painel';
}
?>

<div
    id="adminShortcutConfig"
    class="d-none"
    data-endpoint="<?= e($adminShortcutEndpoint) ?>"
    data-route="<?= e($adminShortcutRoute) ?>"
    data-title="<?= e($adminShortcutTitle) ?>"
    data-csrf="<?= e(Csrf::token()) ?>"
></div>

<div
    class="modal fade"
    id="adminShortcutsModal"
    tabindex="-1"
    aria-labelledby="adminShortcutsTitle"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <h2
                        class="h5 mb-1"
                        id="adminShortcutsTitle"
                    >
                        Favoritos e recentes
                    </h2>

                    <div class="small text-secondary">
                        Acesse rapidamente as telas que você mais usa.
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>
            </div>

            <div class="modal-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                    <div>
                        <div class="small text-secondary">
                            Página atual
                        </div>

                        <div class="fw-semibold">
                            <?= e($adminShortcutTitle) ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-warning"
                        id="adminShortcutFavoriteCurrent"
                    >
                        <i class="bi bi-star me-1"></i>
                        <span>Adicionar aos favoritos</span>
                    </button>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <section>
                            <h3 class="h6 mb-3">
                                <i class="bi bi-star-fill text-warning me-1"></i>
                                Favoritos
                            </h3>

                            <div
                                class="list-group"
                                id="adminShortcutFavorites"
                            >
                                <div class="list-group-item text-secondary">
                                    Carregando...
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="col-lg-6">
                        <section>
                            <h3 class="h6 mb-3">
                                <i class="bi bi-clock-history me-1"></i>
                                Acessados recentemente
                            </h3>

                            <div
                                class="list-group"
                                id="adminShortcutRecent"
                            >
                                <div class="list-group-item text-secondary">
                                    Carregando...
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <small class="text-secondary me-auto">
                    Os atalhos são salvos na sua conta administrativa.
                </small>

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>

<script
    defer
    src="<?= e(url('public/js/admin-shortcuts-v69.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.69.0'))) ?>"
></script>
