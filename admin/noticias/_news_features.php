<?php
$newsFeatures =
    is_array($newsFeatures ?? null)
        ? $newsFeatures
        : [];

$newsFeatureMedia =
    is_array($newsFeatureMedia ?? null)
        ? $newsFeatureMedia
        : [
            'imagens' => [],
            'videos' => [],
            'anexos' => [],
        ];

$selectedGallery =
    array_map(
        'intval',
        (array)($newsFeatures['galeria_ids'] ?? [])
    );

$selectedAttachments =
    array_map(
        'intval',
        (array)($newsFeatures['anexo_ids'] ?? [])
    );

$selectedVideo =
    max(
        0,
        (int)($newsFeatures['video_midia_id'] ?? 0)
    );
?>

<details class="wp-settings-section wp-settings-details" open>
    <summary>
        Mídia e publicação avançada
    </summary>

    <div class="wp-settings-section-body">
        <div class="mb-3">
            <label class="form-label small fw-semibold">
                Encerrar publicação em
            </label>

            <input
                type="datetime-local"
                class="form-control form-control-sm"
                name="publicado_ate"
                value="<?= e((string)($newsFeatures['publicado_ate'] ?? '')) ?>"
            >

            <div class="form-text">
                Opcional. Depois dessa data a notícia deixa de aparecer no Portal sem ser excluída.
            </div>
        </div>

        <div class="border rounded-3 p-3 mb-3">
            <div class="small fw-semibold mb-2">
                Período do destaque
            </div>

            <div class="mb-2">
                <label class="form-label small">
                    Início
                </label>

                <input
                    type="datetime-local"
                    class="form-control form-control-sm"
                    name="destaque_inicio"
                    value="<?= e((string)($newsFeatures['destaque_inicio'] ?? '')) ?>"
                >
            </div>

            <div>
                <label class="form-label small">
                    Fim
                </label>

                <input
                    type="datetime-local"
                    class="form-control form-control-sm"
                    name="destaque_fim"
                    value="<?= e((string)($newsFeatures['destaque_fim'] ?? '')) ?>"
                >
            </div>

            <div class="form-text mt-2">
                Funciona quando “Destacar na página inicial” estiver marcado. Campos vazios deixam o período sem limite.
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">
                Vídeo MP4 da notícia
            </label>

            <select
                class="form-select form-select-sm"
                name="video_midia_id"
            >
                <option value="">
                    Nenhum vídeo adicional
                </option>

                <?php foreach (($newsFeatureMedia['videos'] ?? []) as $video): ?>
                    <?php
                    $videoLabel =
                        trim((string)($video['titulo'] ?? ''))
                        ?: (string)($video['nome_original'] ?? 'Vídeo');
                    ?>
                    <option
                        value="<?= (int)$video['id'] ?>"
                        <?= $selectedVideo === (int)$video['id'] ? 'selected' : '' ?>
                    >
                        <?= e($videoLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="form-text">
                O player HTML5 aparece depois do conteúdo. Vídeos também podem continuar sendo inseridos diretamente no editor.
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">
                Galeria de fotos
            </label>

            <div
                class="border rounded p-2"
                style="max-height:220px;overflow:auto"
            >
                <?php if (!($newsFeatureMedia['imagens'] ?? [])): ?>
                    <div class="small text-secondary">
                        Nenhuma imagem disponível.
                    </div>
                <?php endif; ?>

                <?php foreach (($newsFeatureMedia['imagens'] ?? []) as $image): ?>
                    <?php
                    $imageLabel =
                        trim((string)($image['titulo'] ?? ''))
                        ?: (string)($image['nome_original'] ?? 'Imagem');
                    ?>
                    <label class="form-check small mb-1">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="galeria_ids[]"
                            value="<?= (int)$image['id'] ?>"
                            <?= in_array((int)$image['id'], $selectedGallery, true) ? 'checked' : '' ?>
                        >
                        <span class="form-check-label">
                            <?= e($imageLabel) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-text">
                Até 30 imagens. Elas aparecem em uma galeria responsiva ao final da notícia.
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label small fw-semibold">
                Anexos para download
            </label>

            <div
                class="border rounded p-2"
                style="max-height:220px;overflow:auto"
            >
                <?php if (!($newsFeatureMedia['anexos'] ?? [])): ?>
                    <div class="small text-secondary">
                        Nenhum documento disponível.
                    </div>
                <?php endif; ?>

                <?php foreach (($newsFeatureMedia['anexos'] ?? []) as $attachment): ?>
                    <?php
                    $attachmentLabel =
                        trim((string)($attachment['titulo'] ?? ''))
                        ?: (string)($attachment['nome_original'] ?? 'Documento');
                    ?>
                    <label class="form-check small mb-1">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="anexo_ids[]"
                            value="<?= (int)$attachment['id'] ?>"
                            <?= in_array((int)$attachment['id'], $selectedAttachments, true) ? 'checked' : '' ?>
                        >
                        <span class="form-check-label">
                            <?= e($attachmentLabel) ?>
                            <?php if (!empty($attachment['extensao'])): ?>
                                <span class="text-secondary">
                                    .<?= e(strtoupper((string)$attachment['extensao'])) ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-text">
                Use a Biblioteca de Mídia para enviar PDFs e outros documentos permitidos.
            </div>
        </div>
    </div>
</details>
