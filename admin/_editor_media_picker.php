<?php
if (!isset($midias) || !is_array($midias)) {
    $midias = [];
}
?>
<div class="modal fade" id="portalMediaPickerModal" tabindex="-1" aria-labelledby="portalMediaPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="portalMediaPickerTitle">Biblioteca de Mídia</h2>
                    <div class="small text-secondary" id="portalMediaPickerSubtitle">Selecione imagens ou vídeos MP4 para inserir no conteúdo.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-0">
                <div class="media-picker-toolbar border-bottom p-3 bg-body-tertiary">
                    <div class="row g-2 align-items-end">
                        <div class="col-lg-5">
                            <label class="form-label small fw-semibold">Enviar imagens ou vídeos MP4</label>
                            <input type="file" class="form-control" id="portalMediaUploadInput"
                                   accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,application/mp4,.mp4" multiple>
                        </div>
                        <div class="col-lg-2 d-grid">
                            <button type="button" class="btn btn-primary" id="portalMediaUploadButton">
                                <i class="bi bi-cloud-arrow-up me-1"></i> Fazer upload
                            </button>
                        </div>
                        <div class="col-lg-5">
                            <label class="form-label small fw-semibold">Buscar na biblioteca</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" class="form-control" id="portalMediaSearch"
                                       placeholder="Buscar por título ou nome do arquivo">
                            </div>
                        </div>
                    </div>
                    <div id="portalMediaUploadStatus" class="small mt-2" aria-live="polite"></div>
                </div>

                <div class="p-3">
                    <div class="media-picker-grid" id="portalMediaGrid">
                        <?php foreach ($midias as $m):
                            $title = trim((string)($m['titulo'] ?? '')) ?: (string)$m['nome_original'];
                            $alt = trim((string)($m['alt_text'] ?? '')) ?: $title;
                            $url = mediaUrl((string)$m['caminho']);
                            $mime = strtolower(trim((string)($m['mime_type'] ?? '')));
                            $isVideo = MediaService::isVideo($m);
                            $kind = $isVideo ? 'video' : 'image';
                        ?>
                            <button type="button"
                                    class="media-picker-card"
                                    data-media-id="<?= (int)$m['id'] ?>"
                                    data-media-url="<?= e($url) ?>"
                                    data-media-title="<?= e($title) ?>"
                                    data-media-alt="<?= e($alt) ?>"
                                    data-media-kind="<?= e($kind) ?>"
                                    data-media-mime="<?= e($mime) ?>"
                                    data-media-search="<?= e(mb_strtolower($title . ' ' . (string)$m['nome_original'])) ?>"
                                    aria-pressed="false">
                                <span class="media-picker-check"><i class="bi bi-check-lg"></i></span>
                                <?php if ($isVideo): ?>
                                    <video src="<?= e($url) ?>" preload="metadata" muted playsinline
                                           aria-label="<?= e($title) ?>"
                                           style="width:100%;aspect-ratio:16/9;object-fit:cover;background:#111"></video>
                                    <span class="badge text-bg-dark position-absolute top-0 end-0 m-2" style="z-index:2">
                                        <i class="bi bi-play-fill"></i> MP4
                                    </span>
                                <?php else: ?>
                                    <img src="<?= e($url) ?>" alt="<?= e($alt) ?>" loading="lazy">
                                <?php endif; ?>
                                <span class="media-picker-card-info">
                                    <strong title="<?= e($title) ?>"><?= e($title) ?></strong>
                                    <?php if ($isVideo): ?>
                                        <small>Vídeo MP4</small>
                                    <?php elseif (!empty($m['largura']) && !empty($m['altura'])): ?>
                                        <small><?= (int)$m['largura'] ?> × <?= (int)$m['altura'] ?></small>
                                    <?php endif; ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div id="portalMediaEmpty" class="text-center text-secondary py-5 <?= $midias ? 'd-none' : '' ?>">
                        <i class="bi bi-collection-play fs-1 d-block mb-2"></i>
                        Nenhuma mídia encontrada na Biblioteca de Mídia.
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="text-secondary small"><strong id="portalMediaSelectedCount">0</strong> mídia(s) selecionada(s)</div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="portalMediaInsertButton" disabled>Inserir selecionadas</button>
                </div>
            </div>
        </div>
    </div>
</div>
