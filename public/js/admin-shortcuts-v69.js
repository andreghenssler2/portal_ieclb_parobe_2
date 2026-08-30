(function () {
    'use strict';

    const config = document.getElementById('adminShortcutConfig');
    const modalElement = document.getElementById('adminShortcutsModal');
    const favoritesElement = document.getElementById('adminShortcutFavorites');
    const recentElement = document.getElementById('adminShortcutRecent');
    const favoriteCurrentButton =
        document.getElementById('adminShortcutFavoriteCurrent');
    const openButtons =
        document.querySelectorAll('[data-admin-shortcuts-open]');

    if (!config || !modalElement || !window.bootstrap) {
        return;
    }

    const endpoint = config.dataset.endpoint || '';
    const route = config.dataset.route || '';
    const title = config.dataset.title || 'Painel';
    const csrf = config.dataset.csrf || '';

    const modal =
        bootstrap.Modal.getOrCreateInstance(
            modalElement
        );

    let currentFavorite = false;

    function body(action, values) {
        const form = new URLSearchParams();

        form.set('_token', csrf);
        form.set('action', action);

        Object.entries(values || {}).forEach(function (entry) {
            form.set(entry[0], String(entry[1] || ''));
        });

        return form;
    }

    async function post(action, values) {
        const response = await fetch(
            endpoint,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept':
                        'application/json'
                },
                cache: 'no-store',
                body: body(action, values)
            }
        );

        const data = await response.json();

        if (!response.ok || !data || data.ok !== true) {
            throw new Error(
                data && data.message
                    ? data.message
                    : 'Não foi possível concluir a ação.'
            );
        }

        return data;
    }

    function setCurrentFavorite(value) {
        currentFavorite = Boolean(value);

        if (!favoriteCurrentButton) {
            return;
        }

        const icon =
            favoriteCurrentButton.querySelector('i');

        const label =
            favoriteCurrentButton.querySelector('span');

        favoriteCurrentButton.classList.toggle(
            'btn-warning',
            currentFavorite
        );

        favoriteCurrentButton.classList.toggle(
            'btn-outline-warning',
            !currentFavorite
        );

        if (icon) {
            icon.className =
                'bi '
                + (
                    currentFavorite
                        ? 'bi-star-fill'
                        : 'bi-star'
                )
                + ' me-1';
        }

        if (label) {
            label.textContent =
                currentFavorite
                    ? 'Remover dos favoritos'
                    : 'Adicionar aos favoritos';
        }
    }

    function emptyRow(text) {
        const div = document.createElement('div');

        div.className =
            'list-group-item text-secondary small py-3';

        div.textContent = text;

        return div;
    }

    function buildRow(item, favoriteList) {
        const wrapper = document.createElement('div');

        wrapper.className =
            'list-group-item d-flex align-items-center gap-2 px-3 py-3';

        const link = document.createElement('a');

        link.href = String(item.url || '#');
        link.className =
            'min-w-0 flex-grow-1 text-decoration-none text-reset';

        const label = document.createElement('div');

        label.className =
            'fw-semibold text-truncate';

        label.textContent =
            String(item.titulo || 'Painel');

        const meta = document.createElement('div');

        meta.className =
            'small text-secondary text-truncate';

        const accesses =
            Number(item.acessos || 0);

        meta.textContent =
            accesses > 1
                ? accesses + ' acessos'
                : 'Acessado recentemente';

        link.append(label, meta);

        wrapper.appendChild(link);

        if (favoriteList) {
            const button = document.createElement('button');

            button.type = 'button';
            button.className =
                'btn btn-sm btn-outline-warning flex-shrink-0';
            button.title =
                'Remover dos favoritos';
            button.innerHTML =
                '<i class="bi bi-star-fill"></i>';

            button.addEventListener(
                'click',
                async function () {
                    button.disabled = true;

                    try {
                        await post(
                            'toggle_favorite',
                            {
                                route: item.rota,
                                title: item.titulo
                            }
                        );

                        await loadLists();
                    } catch (error) {
                        window.alert(error.message);
                    } finally {
                        button.disabled = false;
                    }
                }
            );

            wrapper.appendChild(button);
        }

        return wrapper;
    }

    function renderList(
        element,
        items,
        emptyText,
        favoriteList
    ) {
        if (!element) {
            return;
        }

        element.replaceChildren();

        if (!Array.isArray(items) || !items.length) {
            element.appendChild(
                emptyRow(emptyText)
            );

            return;
        }

        items.forEach(function (item) {
            element.appendChild(
                buildRow(
                    item,
                    favoriteList
                )
            );
        });
    }

    async function loadLists() {
        if (!endpoint) {
            return;
        }

        try {
            const response = await fetch(
                endpoint
                + '?current='
                + encodeURIComponent(route),
                {
                    headers: {
                        'Accept':
                            'application/json'
                    },
                    cache: 'no-store'
                }
            );

            const data = await response.json();

            if (!response.ok || !data || data.ok !== true) {
                throw new Error(
                    data && data.message
                        ? data.message
                        : 'Não foi possível carregar seus atalhos.'
                );
            }

            setCurrentFavorite(
                data.current_favorite
            );

            renderList(
                favoritesElement,
                data.favorites,
                'Você ainda não adicionou favoritos.',
                true
            );

            renderList(
                recentElement,
                data.recent,
                'Nenhum acesso recente registrado.',
                false
            );
        } catch (error) {
            renderList(
                favoritesElement,
                [],
                error.message,
                false
            );

            renderList(
                recentElement,
                [],
                error.message,
                false
            );
        }
    }

    async function recordVisit() {
        if (
            !endpoint
            || !route
            || !csrf
        ) {
            return;
        }

        try {
            await post(
                'visit',
                {
                    route: route,
                    title: title
                }
            );
        } catch (error) {
            /*
             * Histórico é acessório: falha silenciosa para não afetar
             * a experiência do painel.
             */
        }
    }

    openButtons.forEach(function (button) {
        button.addEventListener(
            'click',
            function (event) {
                event.preventDefault();
                modal.show();
            }
        );
    });

    if (favoriteCurrentButton) {
        favoriteCurrentButton.addEventListener(
            'click',
            async function () {
                favoriteCurrentButton.disabled = true;

                try {
                    const data =
                        await post(
                            'toggle_favorite',
                            {
                                route: route,
                                title: title
                            }
                        );

                    setCurrentFavorite(
                        data.favorite
                    );

                    await loadLists();
                } catch (error) {
                    window.alert(error.message);
                } finally {
                    favoriteCurrentButton.disabled = false;
                }
            }
        );
    }

    modalElement.addEventListener(
        'shown.bs.modal',
        loadLists
    );

    recordVisit();
})();
