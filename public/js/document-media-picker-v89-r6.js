(function (window, document) {
    'use strict';

    const formatBytes = (bytes) => {
        const value = Number(bytes || 0);

        if (!Number.isFinite(value) || value <= 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const index = Math.min(
            Math.floor(Math.log(value) / Math.log(1024)),
            units.length - 1
        );

        const amount = value / Math.pow(1024, index);

        return (
            (index === 0 ? amount.toFixed(0) : amount.toFixed(amount >= 10 ? 1 : 2))
            + ' '
            + units[index]
        );
    };

    const iconForExtension = (extension) => {
        const ext = String(extension || '').toLowerCase();

        if (ext === 'pdf') {
            return 'bi-file-earmark-pdf';
        }

        if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) {
            return 'bi-file-earmark-word';
        }

        if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) {
            return 'bi-file-earmark-excel';
        }

        if (['ppt', 'pptx', 'odp'].includes(ext)) {
            return 'bi-file-earmark-ppt';
        }

        if (['zip', 'rar', '7z', 'gz'].includes(ext)) {
            return 'bi-file-earmark-zip';
        }

        return 'bi-file-earmark-text';
    };

    const PortalDocumentMediaPicker = {
        config: null,
        modalElement: null,
        modal: null,
        grid: null,
        search: null,
        empty: null,
        uploadInput: null,
        uploadButton: null,
        uploadStatus: null,
        useButton: null,
        selectionText: null,
        input: null,
        preview: null,
        openButton: null,
        selected: null,

        init(config) {
            this.config = config || {};
            this.modalElement = document.getElementById(
                this.config.modalId || 'portalDocumentMediaPickerModal'
            );

            this.input = document.getElementById(
                this.config.inputId || 'documentMediaId'
            );

            this.preview = document.getElementById(
                this.config.previewId || 'documentMediaPreview'
            );

            this.openButton = document.getElementById(
                this.config.openButtonId || 'documentMediaOpen'
            );

            if (
                !this.modalElement
                || !this.input
                || !this.preview
                || !this.openButton
            ) {
                return;
            }

            if (
                !window.bootstrap
                || !window.bootstrap.Modal
            ) {
                console.error(
                    'PortalDocumentMediaPicker: Bootstrap 5 não foi carregado.'
                );
                return;
            }

            this.modal =
                window.bootstrap.Modal.getOrCreateInstance(
                    this.modalElement
                );

            this.grid =
                this.modalElement.querySelector(
                    '#portalDocumentMediaGrid'
                );

            this.search =
                this.modalElement.querySelector(
                    '#portalDocumentMediaSearch'
                );

            this.empty =
                this.modalElement.querySelector(
                    '#portalDocumentMediaEmpty'
                );

            this.uploadInput =
                this.modalElement.querySelector(
                    '#portalDocumentMediaUploadInput'
                );

            this.uploadButton =
                this.modalElement.querySelector(
                    '#portalDocumentMediaUploadButton'
                );

            this.uploadStatus =
                this.modalElement.querySelector(
                    '#portalDocumentMediaUploadStatus'
                );

            this.useButton =
                this.modalElement.querySelector(
                    '#portalDocumentMediaUseButton'
                );

            this.selectionText =
                this.modalElement.querySelector(
                    '#portalDocumentMediaSelectionText'
                );

            this.grid?.addEventListener(
                'click',
                (event) => {
                    const card =
                        event.target.closest(
                            '[data-document-media-card]'
                        );

                    if (!card) {
                        return;
                    }

                    this.selectCard(card);
                }
            );

            this.search?.addEventListener(
                'input',
                () => this.filter()
            );

            this.uploadButton?.addEventListener(
                'click',
                () => this.upload()
            );

            this.useButton?.addEventListener(
                'click',
                () => this.applySelection()
            );

            this.openButton.addEventListener(
                'click',
                () => this.open()
            );

            this.preview.addEventListener(
                'click',
                (event) => {
                    const remove =
                        event.target.closest(
                            '[data-document-media-remove]'
                        );

                    if (!remove) {
                        return;
                    }

                    this.input.value = '';
                    this.selected = null;
                    this.renderPreview(null);
                    this.clearModalSelection();
                }
            );

            this.syncFromInput();
        },

        open() {
            this.syncFromInput();

            if (this.search) {
                this.search.value = '';
            }

            this.filter();

            if (this.uploadStatus) {
                this.uploadStatus.textContent = '';
                this.uploadStatus.className = 'small mt-2';
            }

            this.modal.show();
        },

        syncFromInput() {
            const id =
                String(
                    this.input?.value
                    || ''
                );

            this.clearModalSelection();

            if (!id || !this.grid) {
                return;
            }

            const card =
                this.grid.querySelector(
                    '[data-media-id="' + CSS.escape(id) + '"]'
                );

            if (card) {
                this.selectCard(card);
            }
        },

        clearModalSelection() {
            this.selected = null;

            this.grid?.querySelectorAll(
                '[data-document-media-card]'
            ).forEach((card) => {
                card.classList.remove('border-primary');
                card.setAttribute('aria-pressed', 'false');

                card.querySelector(
                    '[data-document-media-check]'
                )?.classList.add('d-none');
            });

            if (this.useButton) {
                this.useButton.disabled = true;
            }

            if (this.selectionText) {
                this.selectionText.textContent =
                    'Nenhum arquivo selecionado';
            }
        },

        selectCard(card) {
            this.clearModalSelection();

            card.classList.add('border-primary');
            card.setAttribute('aria-pressed', 'true');

            card.querySelector(
                '[data-document-media-check]'
            )?.classList.remove('d-none');

            this.selected = this.cardData(card);

            if (this.useButton) {
                this.useButton.disabled = false;
            }

            if (this.selectionText) {
                this.selectionText.textContent =
                    '1 arquivo selecionado';
            }
        },

        cardData(card) {
            return {
                id:
                    Number(
                        card.dataset.mediaId
                        || 0
                    ),
                title:
                    card.dataset.mediaTitle
                    || '',
                fileName:
                    card.dataset.mediaFileName
                    || '',
                extension:
                    card.dataset.mediaExtension
                    || '',
                size:
                    Number(
                        card.dataset.mediaSize
                        || 0
                    ),
            };
        },

        applySelection() {
            if (!this.selected) {
                return;
            }

            this.input.value =
                String(
                    this.selected.id
                );

            this.renderPreview(
                this.selected
            );

            this.modal.hide();
        },

        renderPreview(item) {
            this.preview.innerHTML = '';

            if (!item) {
                const empty =
                    document.createElement('div');

                empty.className =
                    'text-secondary small';

                empty.textContent =
                    'Nenhum arquivo selecionado.';

                this.preview.append(empty);
                return;
            }

            const wrap =
                document.createElement('div');

            wrap.className =
                'd-flex align-items-start gap-3';

            const iconWrap =
                document.createElement('div');

            iconWrap.className =
                'rounded-3 bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0';

            iconWrap.style.width =
                '56px';

            iconWrap.style.height =
                '56px';

            const icon =
                document.createElement('i');

            icon.className =
                'bi '
                + iconForExtension(
                    item.extension
                )
                + ' fs-3';

            iconWrap.append(icon);

            const body =
                document.createElement('div');

            body.className =
                'flex-grow-1 min-w-0';

            const title =
                document.createElement('div');

            title.className =
                'fw-semibold text-truncate';

            title.textContent =
                item.title
                || item.fileName
                || 'Arquivo';

            const meta =
                document.createElement('div');

            meta.className =
                'small text-secondary';

            meta.textContent =
                [
                    item.extension
                        ? String(item.extension).toUpperCase()
                        : 'ARQUIVO',
                    formatBytes(item.size),
                    item.fileName || ''
                ]
                    .filter(Boolean)
                    .join(' · ');

            const remove =
                document.createElement('button');

            remove.type =
                'button';

            remove.className =
                'btn btn-sm btn-link text-danger p-0 mt-1';

            remove.setAttribute(
                'data-document-media-remove',
                ''
            );

            remove.textContent =
                'Remover arquivo';

            body.append(
                title,
                meta,
                remove
            );

            wrap.append(
                iconWrap,
                body
            );

            this.preview.append(wrap);
        },

        filter() {
            if (!this.grid) {
                return;
            }

            const term =
                String(
                    this.search?.value
                    || ''
                )
                    .trim()
                    .toLocaleLowerCase('pt-BR');

            let visible = 0;

            this.grid.querySelectorAll(
                '[data-document-media-card]'
            ).forEach((card) => {
                const haystack =
                    String(
                        card.dataset.mediaSearch
                        || ''
                    )
                        .toLocaleLowerCase('pt-BR');

                const show =
                    term === ''
                    || haystack.includes(term);

                card.closest('.col')?.classList.toggle(
                    'd-none',
                    !show
                );

                if (show) {
                    visible++;
                }
            });

            this.empty?.classList.toggle(
                'd-none',
                visible > 0
            );
        },

        async upload() {
            const files =
                Array.from(
                    this.uploadInput?.files
                    || []
                );

            if (!files.length) {
                this.setStatus(
                    'Selecione pelo menos um arquivo.',
                    'danger'
                );

                return;
            }

            const formData =
                new FormData();

            formData.append(
                '_token',
                this.config.csrfToken
                || ''
            );

            files.forEach((file) => {
                formData.append(
                    'arquivos[]',
                    file
                );
            });

            this.uploadButton.disabled = true;
            this.uploadInput.disabled = true;

            this.setStatus(
                'Enviando arquivos...',
                'secondary'
            );

            try {
                const response =
                    await fetch(
                        this.config.uploadUrl,
                        {
                            method:
                                'POST',
                            body:
                                formData,
                            credentials:
                                'same-origin',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',
                            },
                        }
                    );

                const data =
                    await response.json();

                if (
                    !response.ok
                    || !data.ok
                ) {
                    throw new Error(
                        data.message
                        || 'Não foi possível enviar os arquivos.'
                    );
                }

                let newest = null;

                (data.items || [])
                    .slice()
                    .reverse()
                    .forEach((item) => {
                        const card =
                            this.createCard(
                                item
                            );

                        this.grid.prepend(
                            card.closest('.col')
                        );

                        newest =
                            newest
                            || card;
                    });

                this.uploadInput.value = '';

                if (this.search) {
                    this.search.value = '';
                }

                this.filter();

                if (newest) {
                    this.selectCard(
                        newest
                    );
                }

                this.setStatus(
                    data.message
                    || 'Upload concluído.',
                    data.errors?.length
                        ? 'warning'
                        : 'success'
                );
            } catch (error) {
                this.setStatus(
                    error?.message
                    || 'Falha no upload.',
                    'danger'
                );
            } finally {
                this.uploadButton.disabled = false;
                this.uploadInput.disabled = false;
            }
        },

        createCard(item) {
            const col =
                document.createElement('div');

            col.className =
                'col';

            const button =
                document.createElement('button');

            button.type =
                'button';

            button.className =
                'btn btn-light border text-start w-100 h-100 p-3 position-relative portal-document-media-card';

            button.setAttribute(
                'data-document-media-card',
                ''
            );

            button.dataset.mediaId =
                String(
                    item.id
                    || 0
                );

            button.dataset.mediaTitle =
                item.title
                || item.fileName
                || 'Arquivo';

            button.dataset.mediaFileName =
                item.fileName
                || '';

            button.dataset.mediaExtension =
                item.extension
                || '';

            button.dataset.mediaSize =
                String(
                    item.size
                    || 0
                );

            button.dataset.mediaSearch =
                (
                    (
                        item.title
                        || ''
                    )
                    + ' '
                    + (
                        item.fileName
                        || ''
                    )
                    + ' '
                    + (
                        item.extension
                        || ''
                    )
                )
                    .toLocaleLowerCase('pt-BR');

            button.setAttribute(
                'aria-pressed',
                'false'
            );

            const check =
                document.createElement('span');

            check.className =
                'position-absolute top-0 end-0 m-2 badge text-bg-primary d-none';

            check.setAttribute(
                'data-document-media-check',
                ''
            );

            check.innerHTML =
                '<i class="bi bi-check-lg"></i>';

            const row =
                document.createElement('div');

            row.className =
                'd-flex gap-3 align-items-start';

            const iconWrap =
                document.createElement('div');

            iconWrap.className =
                'rounded-3 bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0';

            iconWrap.style.width =
                '56px';

            iconWrap.style.height =
                '56px';

            const icon =
                document.createElement('i');

            icon.className =
                'bi '
                + iconForExtension(
                    item.extension
                )
                + ' fs-3';

            iconWrap.append(icon);

            const body =
                document.createElement('div');

            body.className =
                'min-w-0 flex-grow-1';

            const title =
                document.createElement('div');

            title.className =
                'fw-semibold text-truncate';

            title.textContent =
                item.title
                || item.fileName
                || 'Arquivo';

            title.title =
                title.textContent;

            const name =
                document.createElement('div');

            name.className =
                'small text-secondary text-truncate';

            name.textContent =
                item.fileName
                || '';

            const meta =
                document.createElement('div');

            meta.className =
                'small mt-2';

            const badge =
                document.createElement('span');

            badge.className =
                'badge text-bg-light border';

            badge.textContent =
                item.extension
                    ? String(item.extension).toUpperCase()
                    : 'ARQUIVO';

            const size =
                document.createElement('span');

            size.className =
                'text-secondary ms-1';

            size.textContent =
                formatBytes(
                    item.size
                );

            meta.append(
                badge,
                size
            );

            body.append(
                title,
                name,
                meta
            );

            row.append(
                iconWrap,
                body
            );

            button.append(
                check,
                row
            );

            col.append(
                button
            );

            return button;
        },

        setStatus(message, type) {
            if (!this.uploadStatus) {
                return;
            }

            this.uploadStatus.className =
                'small mt-2 text-'
                + (
                    type
                    || 'secondary'
                );

            this.uploadStatus.textContent =
                message;
        }
    };

    window.PortalDocumentMediaPicker =
        PortalDocumentMediaPicker;
})(window, document);
