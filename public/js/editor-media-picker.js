(function (window, document) {
    'use strict';

    const PortalMediaPicker = {
        config: null,
        modalElement: null,
        modal: null,
        grid: null,
        search: null,
        selectedCount: null,
        insertButton: null,
        uploadInput: null,
        uploadButton: null,
        uploadStatus: null,
        empty: null,
        mode: 'editor',
        editor: null,
        featuredCallback: null,
        selected: new Map(),

        init(config) {
            this.config = config || {};
            this.modalElement = document.getElementById(this.config.modalId || 'portalMediaPickerModal');
            if (!this.modalElement) return;
            if (!window.bootstrap || !window.bootstrap.Modal) return;

            this.modal = window.bootstrap.Modal.getOrCreateInstance(this.modalElement);
            this.grid = this.modalElement.querySelector('#portalMediaGrid');
            this.search = this.modalElement.querySelector('#portalMediaSearch');
            this.selectedCount = this.modalElement.querySelector('#portalMediaSelectedCount');
            this.insertButton = this.modalElement.querySelector('#portalMediaInsertButton');
            this.uploadInput = this.modalElement.querySelector('#portalMediaUploadInput');
            this.uploadButton = this.modalElement.querySelector('#portalMediaUploadButton');
            this.uploadStatus = this.modalElement.querySelector('#portalMediaUploadStatus');
            this.empty = this.modalElement.querySelector('#portalMediaEmpty');

            this.grid.addEventListener('click', (event) => {
                const card = event.target.closest('.media-picker-card');
                if (!card || card.classList.contains('d-none')) return;
                this.toggleCard(card);
            });
            this.search.addEventListener('input', () => this.filter());
            this.insertButton.addEventListener('click', () => this.confirmSelection());
            this.uploadButton.addEventListener('click', () => this.upload());
            this.modalElement.addEventListener('hidden.bs.modal', () => this.resetSelection());
        },

        openForEditor(editor) {
            if (!this.modal) return;
            this.mode = 'editor';
            this.editor = editor;
            this.featuredCallback = null;
            this.modalElement.querySelector('#portalMediaPickerTitle').textContent = 'Inserir mídia no conteúdo';
            this.modalElement.querySelector('#portalMediaPickerSubtitle').textContent =
                'Selecione imagens e/ou vídeos MP4. Eles serão inseridos no ponto atual do editor.';
            this.insertButton.textContent = 'Inserir selecionadas';
            this.resetSelection();
            this.modal.show();
        },

        openForFeatured(callback, currentId) {
            if (!this.modal) return;
            this.mode = 'featured';
            this.editor = null;
            this.featuredCallback = callback;
            this.modalElement.querySelector('#portalMediaPickerTitle').textContent = 'Escolher imagem destacada';
            this.modalElement.querySelector('#portalMediaPickerSubtitle').textContent =
                'A imagem destacada aceita somente arquivos de imagem.';
            this.insertButton.textContent = 'Usar como imagem destacada';
            this.resetSelection();
            if (currentId) {
                const card = this.grid.querySelector('[data-media-id="' + CSS.escape(String(currentId)) + '"]');
                if (card && card.dataset.mediaKind !== 'video') this.toggleCard(card, true);
            }
            this.modal.show();
        },

        bindFeatured(options) {
            if (!options || !options.openButton || !options.input || !options.preview) return;
            const openButton = options.openButton;
            const input = options.input;
            const preview = options.preview;

            const bindRemove = () => {
                const remove = preview.querySelector(options.removeButtonSelector || '[data-media-featured-remove]');
                if (remove) {
                    remove.onclick = () => {
                        input.value = '';
                        preview.innerHTML = '<div class="text-secondary small">Nenhuma imagem selecionada.</div>';
                    };
                }
            };

            bindRemove();
            openButton.addEventListener('click', () => {
                this.openForFeatured((item) => {
                    if (item.kind === 'video') return;
                    input.value = String(item.id);
                    preview.innerHTML = '';

                    const wrap = document.createElement('div');
                    wrap.className = 'd-flex align-items-center gap-3';
                    const img = document.createElement('img');
                    img.src = item.url;
                    img.alt = item.alt || item.title || '';
                    img.className = 'img-thumbnail featured-preview';

                    const text = document.createElement('div');
                    const title = document.createElement('div');
                    title.className = 'fw-semibold';
                    title.textContent = item.title || 'Imagem selecionada';

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-sm btn-link text-danger p-0 mt-1';
                    remove.setAttribute('data-media-featured-remove', '');
                    remove.textContent = 'Remover imagem';

                    text.append(title, remove);
                    wrap.append(img, text);
                    preview.append(wrap);
                    bindRemove();
                }, input.value);
            });
        },

        toggleCard(card, forceSelect) {
            const id = String(card.dataset.mediaId || '');
            if (!id) return;
            if (this.mode === 'featured' && card.dataset.mediaKind === 'video') return;

            const shouldSelect = forceSelect === true ? true : !this.selected.has(id);
            if (this.mode === 'featured' && shouldSelect) this.resetSelection(false);

            if (shouldSelect) {
                this.selected.set(id, this.cardData(card));
                card.classList.add('is-selected');
                card.setAttribute('aria-pressed', 'true');
            } else {
                this.selected.delete(id);
                card.classList.remove('is-selected');
                card.setAttribute('aria-pressed', 'false');
            }
            this.updateSelectionState();
        },

        cardData(card) {
            return {
                id: Number(card.dataset.mediaId),
                url: card.dataset.mediaUrl || '',
                title: card.dataset.mediaTitle || '',
                alt: card.dataset.mediaAlt || '',
                kind: card.dataset.mediaKind || 'image',
                mime: card.dataset.mediaMime || ''
            };
        },

        resetSelection(clearSearch = true) {
            this.selected.clear();
            if (this.grid) {
                this.grid.querySelectorAll('.media-picker-card.is-selected').forEach((card) => {
                    card.classList.remove('is-selected');
                    card.setAttribute('aria-pressed', 'false');
                });
            }
            if (clearSearch && this.search) this.search.value = '';
            this.filter();
            this.updateSelectionState();
            if (this.uploadStatus) {
                this.uploadStatus.textContent = '';
                this.uploadStatus.className = 'small mt-2';
            }
        },

        updateSelectionState() {
            const count = this.selected.size;
            if (this.selectedCount) this.selectedCount.textContent = String(count);
            if (this.insertButton) this.insertButton.disabled = count === 0;
        },

        filter() {
            if (!this.grid) return;
            const term = (this.search?.value || '').trim().toLocaleLowerCase('pt-BR');
            let visible = 0;

            this.grid.querySelectorAll('.media-picker-card').forEach((card) => {
                const haystack = (card.dataset.mediaSearch || '').toLocaleLowerCase('pt-BR');
                const allowedByMode = this.mode !== 'featured' || card.dataset.mediaKind !== 'video';
                const allowedBySearch = term === '' || haystack.includes(term);
                const show = allowedByMode && allowedBySearch;
                card.classList.toggle('d-none', !show);
                if (show) visible++;
            });

            if (this.empty) this.empty.classList.toggle('d-none', visible > 0);
        },

        confirmSelection() {
            const items = Array.from(this.selected.values());
            if (!items.length) return;

            if (this.mode === 'featured') {
                const image = items.find((item) => item.kind !== 'video');
                if (image && typeof this.featuredCallback === 'function') this.featuredCallback(image);
            } else if (this.editor) {
                const html = items.map((item) => {
                    if (item.kind === 'video') {
                        const url = this.escapeAttribute(item.url);
                        const mime = this.escapeAttribute(item.mime || 'video/mp4');
                        return '<p class="portal-video-wrap">'
                            + '<video controls preload="metadata" playsinline '
                            + 'style="display:block;width:100%;max-width:960px;height:auto;margin:0 auto;">'
                            + '<source src="' + url + '" type="' + mime + '">'
                            + 'Seu navegador não suporta vídeo HTML5.'
                            + '</video></p>';
                    }

                    return '<p><img src="' + this.escapeAttribute(item.url)
                        + '" alt="' + this.escapeAttribute(item.alt || item.title || '') + '"></p>';
                }).join('');

                this.editor.focus();
                this.editor.insertContent(html);
                this.editor.save();
            }

            this.modal.hide();
        },

        async upload() {
            const files = Array.from(this.uploadInput?.files || []);
            if (!files.length) {
                this.setUploadStatus('Selecione uma ou mais imagens ou vídeos MP4.', 'danger');
                return;
            }

            const formData = new FormData();
            formData.append('_token', this.config.csrfToken || '');
            files.forEach((file) => formData.append('arquivos[]', file));

            this.uploadButton.disabled = true;
            this.uploadInput.disabled = true;
            this.setUploadStatus('Enviando mídias...', 'secondary');

            try {
                const response = await fetch(this.config.uploadUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível enviar as mídias.');

                (data.items || []).slice().reverse().forEach((item) => {
                    const card = this.createCard(item);
                    this.grid.prepend(card);
                    if (this.mode !== 'featured' || item.kind !== 'video') this.toggleCard(card, true);
                });

                this.uploadInput.value = '';
                this.search.value = '';
                this.filter();
                this.setUploadStatus(data.message || 'Upload concluído.', data.errors?.length ? 'warning' : 'success');
            } catch (error) {
                this.setUploadStatus(error.message || 'Falha no upload.', 'danger');
            } finally {
                this.uploadButton.disabled = false;
                this.uploadInput.disabled = false;
            }
        },

        createCard(item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'media-picker-card';
            button.dataset.mediaId = String(item.id);
            button.dataset.mediaUrl = item.url || '';
            button.dataset.mediaTitle = item.title || item.fileName || '';
            button.dataset.mediaAlt = item.alt || item.title || '';
            button.dataset.mediaKind = item.kind || 'image';
            button.dataset.mediaMime = item.mime || '';
            button.dataset.mediaSearch =
                ((item.title || '') + ' ' + (item.fileName || '')).toLocaleLowerCase('pt-BR');
            button.setAttribute('aria-pressed', 'false');

            const check = document.createElement('span');
            check.className = 'media-picker-check';
            check.innerHTML = '<i class="bi bi-check-lg"></i>';

            let preview;
            if (item.kind === 'video') {
                preview = document.createElement('video');
                preview.src = item.url || '';
                preview.preload = 'metadata';
                preview.muted = true;
                preview.playsInline = true;
                preview.style.width = '100%';
                preview.style.aspectRatio = '16 / 9';
                preview.style.objectFit = 'cover';
                preview.style.background = '#111';
            } else {
                preview = document.createElement('img');
                preview.src = item.url || '';
                preview.alt = item.alt || item.title || '';
                preview.loading = 'lazy';
            }

            const info = document.createElement('span');
            info.className = 'media-picker-card-info';
            const strong = document.createElement('strong');
            strong.textContent = item.title || item.fileName || 'Mídia';
            strong.title = strong.textContent;
            info.append(strong);

            const small = document.createElement('small');
            if (item.kind === 'video') small.textContent = 'Vídeo MP4';
            else if (item.width && item.height) small.textContent = item.width + ' × ' + item.height;
            if (small.textContent) info.append(small);

            button.append(check, preview, info);
            return button;
        },

        setUploadStatus(message, type) {
            if (!this.uploadStatus) return;
            this.uploadStatus.className = 'small mt-2 text-' + (type || 'secondary');
            this.uploadStatus.textContent = message;
        },

        escapeAttribute(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
    };

    window.PortalMediaPicker = PortalMediaPicker;
})(window, document);
