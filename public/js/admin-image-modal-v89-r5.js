(function (window, document) {
    'use strict';

    const PortalAdminImageModal = {
        bindSingle(options) {
            if (!window.PortalMediaPicker || !options) return;

            const picker = window.PortalMediaPicker;
            const openButton = options.openButton;

            if (!openButton || !options.input || !options.preview) return;

            picker.bindFeatured({
                openButton: openButton,
                removeButtonSelector: options.removeButtonSelector || '[data-media-featured-remove]',
                input: options.input,
                preview: options.preview
            });

            openButton.addEventListener('click', function () {
                const title = document.getElementById('portalMediaPickerTitle');
                const subtitle = document.getElementById('portalMediaPickerSubtitle');
                const insertButton = document.getElementById('portalMediaInsertButton');

                if (title && options.title) {
                    title.textContent = options.title;
                }

                if (subtitle && options.subtitle) {
                    subtitle.textContent = options.subtitle;
                }

                if (insertButton && options.confirmText) {
                    insertButton.textContent = options.confirmText;
                }
            });
        },

        bindMultiple(options) {
            if (!window.PortalMediaPicker || !options) return;

            const picker = window.PortalMediaPicker;
            const openButton = options.openButton;
            const container = options.container;

            if (!openButton || !container || !picker.modal) return;

            if (!picker._r5OriginalConfirmSelection) {
                picker._r5OriginalConfirmSelection =
                    picker.confirmSelection.bind(picker);

                picker.confirmSelection = function () {
                    if (this.mode === 'r5-multiple') {
                        const items = Array.from(this.selected.values());

                        if (typeof this._r5MultipleCallback === 'function') {
                            this._r5MultipleCallback(items);
                        }

                        this.modal.hide();
                        return;
                    }

                    return this._r5OriginalConfirmSelection();
                };
            }

            const selectedIds = function () {
                return Array.from(
                    container.querySelectorAll('input[name="midias[]"]')
                )
                    .map(function (input) {
                        return String(input.value || '');
                    })
                    .filter(Boolean);
            };

            const existingMeta = function () {
                const map = new Map();

                container.querySelectorAll('[data-r5-gallery-item]').forEach(function (row) {
                    const id = String(row.dataset.mediaId || '');
                    if (!id) return;

                    map.set(id, {
                        caption: row.querySelector('[data-r5-caption]')?.value || '',
                        order: row.querySelector('[data-r5-order]')?.value || ''
                    });
                });

                return map;
            };

            const emptyState = function () {
                if (container.querySelector('[data-r5-gallery-item]')) return;

                const empty = document.createElement('div');
                empty.className = 'text-secondary small py-3';
                empty.setAttribute('data-r5-gallery-empty', '');
                empty.textContent = options.emptyText || 'Nenhuma imagem selecionada.';
                container.append(empty);
            };

            const render = function (items) {
                const meta = existingMeta();
                container.innerHTML = '';

                items.forEach(function (item, index) {
                    const id = String(item.id || '');
                    if (!id) return;

                    const previous = meta.get(id) || {};

                    const row = document.createElement('div');
                    row.className = 'border rounded-3 p-2 mb-2';
                    row.setAttribute('data-r5-gallery-item', '');
                    row.dataset.mediaId = id;

                    const layout = document.createElement('div');
                    layout.className = 'd-flex flex-column flex-md-row gap-3 align-items-md-center';

                    const image = document.createElement('img');
                    image.src = item.url || '';
                    image.alt = item.alt || item.title || '';
                    image.className = 'img-thumbnail flex-shrink-0';
                    image.style.width = '120px';
                    image.style.height = '82px';
                    image.style.objectFit = 'cover';

                    const body = document.createElement('div');
                    body.className = 'flex-grow-1 min-w-0';

                    const title = document.createElement('div');
                    title.className = 'fw-semibold text-truncate mb-2';
                    title.textContent = item.title || 'Imagem';

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'midias[]';
                    hidden.value = id;

                    const fields = document.createElement('div');
                    fields.className = 'row g-2';

                    const captionCol = document.createElement('div');
                    captionCol.className = 'col-md-8';

                    const caption = document.createElement('input');
                    caption.className = 'form-control form-control-sm';
                    caption.name = 'legenda[' + id + ']';
                    caption.value = previous.caption || '';
                    caption.placeholder = 'Legenda opcional';
                    caption.setAttribute('data-r5-caption', '');

                    captionCol.append(caption);

                    const orderCol = document.createElement('div');
                    orderCol.className = 'col-md-4';

                    const order = document.createElement('input');
                    order.className = 'form-control form-control-sm';
                    order.type = 'number';
                    order.name = 'ordem[' + id + ']';
                    order.value = previous.order || String((index + 1) * 10);
                    order.placeholder = 'Ordem';
                    order.setAttribute('data-r5-order', '');

                    orderCol.append(order);
                    fields.append(captionCol, orderCol);

                    body.append(title, hidden, fields);

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
                    remove.setAttribute('data-r5-gallery-remove', '');
                    remove.innerHTML = '<i class="bi bi-trash"></i><span class="visually-hidden">Remover</span>';

                    layout.append(image, body, remove);
                    row.append(layout);
                    container.append(row);
                });

                emptyState();
            };

            container.addEventListener('click', function (event) {
                const remove = event.target.closest('[data-r5-gallery-remove]');
                if (!remove) return;

                remove.closest('[data-r5-gallery-item]')?.remove();
                emptyState();
            });

            openButton.addEventListener('click', function () {
                picker.mode = 'r5-multiple';
                picker.editor = null;
                picker.featuredCallback = null;
                picker._r5MultipleCallback = render;
                picker.resetSelection();

                selectedIds().forEach(function (id) {
                    const card = picker.grid?.querySelector(
                        '[data-media-id="' + CSS.escape(id) + '"]'
                    );

                    if (card) {
                        picker.toggleCard(card, true);
                    }
                });

                const title = document.getElementById('portalMediaPickerTitle');
                const subtitle = document.getElementById('portalMediaPickerSubtitle');
                const insertButton = document.getElementById('portalMediaInsertButton');

                if (title) {
                    title.textContent = options.title || 'Escolher imagens';
                }

                if (subtitle) {
                    subtitle.textContent =
                        options.subtitle ||
                        'Selecione uma ou várias imagens da Biblioteca de Mídia.';
                }

                if (insertButton) {
                    insertButton.textContent =
                        options.confirmText || 'Usar imagens selecionadas';
                }

                picker.modal.show();
            });

            emptyState();
        }
    };

    window.PortalAdminImageModal = PortalAdminImageModal;
})(window, document);
