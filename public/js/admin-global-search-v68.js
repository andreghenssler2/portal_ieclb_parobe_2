(function () {
    'use strict';

    const modalElement = document.getElementById('adminGlobalSearchModal');
    const input = document.getElementById('adminGlobalSearchInput');
    const resultsElement = document.getElementById('adminGlobalSearchResults');
    const statusElement = document.getElementById('adminGlobalSearchStatus');
    const openButtons = document.querySelectorAll('[data-admin-global-search-open]');

    if (!modalElement || !input || !resultsElement || !window.bootstrap) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const endpoint = resultsElement.dataset.endpoint || '';

    let timer = null;
    let controller = null;
    let activeIndex = -1;

    function openSearch() {
        modal.show();
    }

    function resetActive() {
        activeIndex = -1;
        resultsElement
            .querySelectorAll('.admin-global-search-result.is-active')
            .forEach(function (item) {
                item.classList.remove('is-active');
            });
    }

    function setStatus(text) {
        if (statusElement) {
            statusElement.textContent = text;
        }
    }

    function showMessage(icon, title, text) {
        resultsElement.replaceChildren();

        const wrapper = document.createElement('div');
        wrapper.className = 'admin-global-search-empty text-center py-5 px-4';

        const iconEl = document.createElement('i');
        iconEl.className = 'bi ' + icon + ' fs-2 text-secondary d-block mb-2';

        const titleEl = document.createElement('div');
        titleEl.className = 'fw-semibold';
        titleEl.textContent = title;

        const textEl = document.createElement('div');
        textEl.className = 'small text-secondary mt-1';
        textEl.textContent = text;

        wrapper.append(iconEl, titleEl, textEl);
        resultsElement.appendChild(wrapper);

        resetActive();
    }

    function createBadge(item) {
        const value = (item.badge || '').trim();

        if (!value) {
            return null;
        }

        const badge = document.createElement('span');
        badge.className =
            'badge text-bg-' + (item.badge_class || 'secondary') + ' ms-auto flex-shrink-0';
        badge.textContent = value;

        return badge;
    }

    function renderResults(data) {
        const results = Array.isArray(data.results) ? data.results : [];

        if (!results.length) {
            showMessage(
                'bi-search',
                'Nenhum resultado',
                'Tente outro termo ou confira se seu perfil possui acesso ao módulo.'
            );

            setStatus('Nenhum resultado encontrado.');
            return;
        }

        resultsElement.replaceChildren();

        let currentSection = '';

        results.forEach(function (item) {
            const section = String(item.section || 'Resultados');

            if (section !== currentSection) {
                currentSection = section;

                const heading = document.createElement('div');
                heading.className =
                    'admin-global-search-section px-3 py-2 small fw-semibold text-uppercase text-secondary';
                heading.textContent = section;

                resultsElement.appendChild(heading);
            }

            const link = document.createElement('a');
            link.className =
                'admin-global-search-result d-flex align-items-center gap-3 px-3 py-3 text-decoration-none text-reset';
            link.href = String(item.url || '#');

            const iconWrap = document.createElement('span');
            iconWrap.className =
                'admin-global-search-icon rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0';

            const icon = document.createElement('i');
            icon.className = 'bi ' + String(item.icon || 'bi-search');

            iconWrap.appendChild(icon);

            const content = document.createElement('span');
            content.className = 'min-w-0 flex-grow-1';

            const label = document.createElement('span');
            label.className = 'd-block fw-semibold text-truncate';
            label.textContent = String(item.label || '');

            const subtitle = document.createElement('span');
            subtitle.className = 'd-block small text-secondary text-truncate';
            subtitle.textContent = String(item.subtitle || '');

            content.append(label, subtitle);

            link.append(iconWrap, content);

            const badge = createBadge(item);

            if (badge) {
                link.appendChild(badge);
            }

            const arrow = document.createElement('i');
            arrow.className = 'bi bi-chevron-right small text-secondary flex-shrink-0';

            link.appendChild(arrow);

            link.addEventListener('mouseenter', function () {
                const links = getLinks();
                const index = links.indexOf(link);

                if (index >= 0) {
                    activate(index);
                }
            });

            resultsElement.appendChild(link);
        });

        activeIndex = -1;

        setStatus(
            String(data.total || results.length)
            + ' resultado(s) encontrado(s).'
        );
    }

    function getLinks() {
        return Array.from(
            resultsElement.querySelectorAll('.admin-global-search-result')
        );
    }

    function activate(index) {
        const links = getLinks();

        if (!links.length) {
            return;
        }

        if (index < 0) {
            index = links.length - 1;
        }

        if (index >= links.length) {
            index = 0;
        }

        links.forEach(function (link) {
            link.classList.remove('is-active');
        });

        activeIndex = index;
        links[index].classList.add('is-active');
        links[index].scrollIntoView({
            block: 'nearest'
        });
    }

    async function search(query) {
        if (!endpoint) {
            return;
        }

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        setStatus('Buscando...');

        try {
            const response = await fetch(
                endpoint + '?q=' + encodeURIComponent(query),
                {
                    headers: {
                        'Accept': 'application/json'
                    },
                    cache: 'no-store',
                    signal: controller.signal
                }
            );

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            if (!data || data.ok !== true) {
                throw new Error('Resposta inválida.');
            }

            renderResults(data);
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }

            showMessage(
                'bi-exclamation-circle',
                'Não foi possível pesquisar',
                'Tente novamente em alguns instantes.'
            );

            setStatus('Falha ao consultar a busca.');
        }
    }

    function scheduleSearch() {
        const query = input.value.trim();

        window.clearTimeout(timer);

        if (query.length < 2) {
            showMessage(
                'bi-command',
                'Encontre rapidamente qualquer item do painel',
                'Digite pelo menos 2 caracteres. Use ↑ ↓ e Enter para navegar.'
            );

            setStatus('A busca respeita as permissões do seu perfil.');
            return;
        }

        timer = window.setTimeout(function () {
            search(query);
        }, 180);
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            openSearch();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (
            (event.ctrlKey || event.metaKey)
            && event.key.toLowerCase() === 'k'
        ) {
            event.preventDefault();
            openSearch();
            return;
        }

        if (!modalElement.classList.contains('show')) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activate(activeIndex + 1);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activate(activeIndex - 1);
            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0) {
            const links = getLinks();

            if (links[activeIndex]) {
                event.preventDefault();
                window.location.href = links[activeIndex].href;
            }
        }
    });

    input.addEventListener('input', scheduleSearch);

    modalElement.addEventListener('shown.bs.modal', function () {
        window.setTimeout(function () {
            input.focus();
            input.select();
        }, 50);
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        if (controller) {
            controller.abort();
        }

        input.value = '';

        showMessage(
            'bi-command',
            'Encontre rapidamente qualquer item do painel',
            'Digite pelo menos 2 caracteres. Use ↑ ↓ e Enter para navegar.'
        );

        setStatus('A busca respeita as permissões do seu perfil.');
    });
})();
