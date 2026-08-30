<?php

$adminGlobalSearchEndpoint =
    url(
        'admin/api/busca-global.php'
    );
?>

<div
    class="modal fade"
    id="adminGlobalSearchModal"
    tabindex="-1"
    aria-labelledby="adminGlobalSearchTitle"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-scrollable admin-global-search-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom py-3">
                <div class="w-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-search text-secondary"></i>

                        <h2
                            class="h6 mb-0"
                            id="adminGlobalSearchTitle"
                        >
                            Busca global
                        </h2>

                        <span class="badge text-bg-light border ms-auto">
                            Ctrl K
                        </span>
                    </div>

                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search"></i>
                        </span>

                        <input
                            type="search"
                            class="form-control border-start-0 ps-0"
                            id="adminGlobalSearchInput"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="Buscar notícias, páginas, eventos, mídia, documentos..."
                            aria-label="Pesquisar no painel"
                        >
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close ms-2"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>
            </div>

            <div
                class="modal-body p-0"
                id="adminGlobalSearchResults"
                data-endpoint="<?= e($adminGlobalSearchEndpoint) ?>"
            >
                <div class="admin-global-search-empty text-center py-5 px-4">
                    <i class="bi bi-command fs-2 text-secondary d-block mb-2"></i>

                    <div class="fw-semibold">
                        Encontre rapidamente qualquer item do painel
                    </div>

                    <div class="small text-secondary mt-1">
                        Digite pelo menos 2 caracteres. Use ↑ ↓ e Enter para navegar.
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top justify-content-between py-2">
                <small
                    class="text-secondary"
                    id="adminGlobalSearchStatus"
                    aria-live="polite"
                >
                    A busca respeita as permissões do seu perfil.
                </small>

                <small class="text-secondary d-none d-sm-inline">
                    Esc para fechar
                </small>
            </div>
        </div>
    </div>
</div>

<script
    defer
    src="<?= e(url('public/js/admin-global-search-v68.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.68.0'))) ?>"
></script>
