<?php

if (!isset($media) || !is_array($media)) {
    $media = [];
}

$documentMediaSelectedId =
    isset($documentMediaSelectedId)
        ? (int)$documentMediaSelectedId
        : 0;
?>
<div
    class="modal fade"
    id="portalDocumentMediaPickerModal"
    tabindex="-1"
    aria-labelledby="portalDocumentMediaPickerTitle"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2
                        class="modal-title fs-5"
                        id="portalDocumentMediaPickerTitle"
                    >
                        Biblioteca de Mídia — Documentos
                    </h2>

                    <div class="small text-secondary">
                        Escolha um arquivo existente ou envie um novo documento.
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>
            </div>

            <div class="modal-body p-0">
                <div class="border-bottom p-3 bg-body-tertiary">
                    <div class="row g-2 align-items-end">
                        <div class="col-lg-5">
                            <label
                                class="form-label small fw-semibold"
                                for="portalDocumentMediaUploadInput"
                            >
                                Enviar novos arquivos
                            </label>

                            <input
                                type="file"
                                class="form-control"
                                id="portalDocumentMediaUploadInput"
                                multiple
                            >

                            <div class="form-text">
                                Imagens não são aceitas neste seletor de documentos.
                            </div>
                        </div>

                        <div class="col-lg-2 d-grid">
                            <button
                                type="button"
                                class="btn btn-primary"
                                id="portalDocumentMediaUploadButton"
                            >
                                <i class="bi bi-cloud-arrow-up me-1"></i>
                                Fazer upload
                            </button>
                        </div>

                        <div class="col-lg-5">
                            <label
                                class="form-label small fw-semibold"
                                for="portalDocumentMediaSearch"
                            >
                                Buscar na biblioteca
                            </label>

                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input
                                    type="search"
                                    class="form-control"
                                    id="portalDocumentMediaSearch"
                                    placeholder="Buscar por título, nome ou extensão"
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        id="portalDocumentMediaUploadStatus"
                        class="small mt-2"
                        aria-live="polite"
                    ></div>
                </div>

                <div class="p-3">
                    <div
                        class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3"
                        id="portalDocumentMediaGrid"
                    >
                        <?php foreach ($media as $file): ?>
                            <?php
                            $fileId =
                                (int)($file['id'] ?? 0);

                            $fileTitle =
                                trim((string)($file['titulo'] ?? ''))
                                ?: (string)($file['nome_original'] ?? 'Arquivo');

                            $extension =
                                strtoupper(
                                    trim(
                                        (string)($file['extensao'] ?? '')
                                    )
                                );

                            $searchText =
                                mb_strtolower(
                                    $fileTitle
                                    . ' '
                                    . (string)($file['nome_original'] ?? '')
                                    . ' '
                                    . $extension
                                );

                            $selected =
                                $documentMediaSelectedId === $fileId;
                            ?>

                            <div class="col">
                                <button
                                    type="button"
                                    class="btn btn-light border text-start w-100 h-100 p-3 position-relative portal-document-media-card <?= $selected ? 'border-primary' : '' ?>"
                                    data-document-media-card
                                    data-media-id="<?= $fileId ?>"
                                    data-media-title="<?= e($fileTitle) ?>"
                                    data-media-file-name="<?= e((string)($file['nome_original'] ?? '')) ?>"
                                    data-media-extension="<?= e($extension) ?>"
                                    data-media-size="<?= (int)($file['tamanho'] ?? 0) ?>"
                                    data-media-search="<?= e($searchText) ?>"
                                    aria-pressed="<?= $selected ? 'true' : 'false' ?>"
                                >
                                    <span
                                        class="position-absolute top-0 end-0 m-2 badge text-bg-primary <?= $selected ? '' : 'd-none' ?>"
                                        data-document-media-check
                                    >
                                        <i class="bi bi-check-lg"></i>
                                    </span>

                                    <div class="d-flex gap-3 align-items-start">
                                        <div
                                            class="rounded-3 bg-body-secondary d-flex align-items-center justify-content-center flex-shrink-0"
                                            style="width:56px;height:56px"
                                        >
                                            <i class="bi bi-file-earmark-text fs-3"></i>
                                        </div>

                                        <div class="min-w-0 flex-grow-1">
                                            <div
                                                class="fw-semibold text-truncate"
                                                title="<?= e($fileTitle) ?>"
                                            >
                                                <?= e($fileTitle) ?>
                                            </div>

                                            <div class="small text-secondary text-truncate">
                                                <?= e((string)($file['nome_original'] ?? '')) ?>
                                            </div>

                                            <div class="small mt-2">
                                                <span class="badge text-bg-light border">
                                                    <?= e($extension !== '' ? $extension : 'ARQUIVO') ?>
                                                </span>

                                                <span class="text-secondary ms-1">
                                                    <?= e(formatBytes((int)($file['tamanho'] ?? 0))) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div
                        id="portalDocumentMediaEmpty"
                        class="text-center text-secondary py-5 <?= $media ? 'd-none' : '' ?>"
                    >
                        <i class="bi bi-file-earmark-text fs-1 d-block mb-2"></i>
                        Nenhum documento encontrado na Biblioteca de Mídia.
                    </div>
                </div>
            </div>

            <div class="modal-footer justify-content-between">
                <div class="text-secondary small">
                    <span id="portalDocumentMediaSelectionText">
                        <?= $documentMediaSelectedId > 0
                            ? '1 arquivo selecionado'
                            : 'Nenhum arquivo selecionado' ?>
                    </span>
                </div>

                <div class="d-flex gap-2">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        id="portalDocumentMediaUseButton"
                        <?= $documentMediaSelectedId > 0 ? '' : 'disabled' ?>
                    >
                        Usar arquivo selecionado
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
