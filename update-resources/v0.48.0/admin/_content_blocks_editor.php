<?php
/** Editor compartilhado de blocos v0.48.0.
 * Espera $contentBlocks e opcionalmente $contentBlocksTitle/$contentPatterns.
 */
$contentBlocks = is_array($contentBlocks ?? null) ? $contentBlocks : [];
$contentBlocksTitle = trim((string)($contentBlocksTitle ?? 'Blocos de conteúdo')) ?: 'Blocos de conteúdo';
$contentPatterns = is_array($contentPatterns ?? null) ? $contentPatterns : [];

$contentDynamicOptions = [
    'categorias' => [],
    'comunidades' => [],
    'documento_categorias' => [],
];

if (
    isset($pdo)
    && $pdo instanceof PDO
    && class_exists('DynamicContentBlockService')
) {
    try {
        $contentDynamicOptions = DynamicContentBlockService::editorOptions($pdo);
    } catch (Throwable $ignored) {
    }
}

$contentBlocksJson = json_encode(
    $contentBlocks,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

$contentPatternsJson = json_encode(
    $contentPatterns,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

$contentDynamicOptionsJson = json_encode(
    $contentDynamicOptions,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

?>
<section class="card border-0 shadow-sm mt-3" id="contentBlocksEditor" data-content-block-editor>
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-semibold"><?= e($contentBlocksTitle) ?></div>
            <div class="small text-secondary">
                Monte o conteúdo em blocos, use padrões ou insira conteúdo dinâmico do próprio Portal.
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($contentPatterns): ?>
                <button
                    class="btn btn-sm btn-outline-secondary"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#contentPatternsModal"
                >
                    <i class="bi bi-grid-3x3-gap me-1"></i>Inserir padrão
                </button>
            <?php endif; ?>

            <div class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-plus-lg me-1"></i>Adicionar bloco
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" type="button" data-block-add="heading"><i class="bi bi-type-h2 me-2"></i>Título</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="text"><i class="bi bi-text-paragraph me-2"></i>Texto</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="image"><i class="bi bi-image me-2"></i>Imagem</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="quote"><i class="bi bi-quote me-2"></i>Citação</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="button"><i class="bi bi-link-45deg me-2"></i>Botão / Link</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="video"><i class="bi bi-play-btn me-2"></i>Vídeo</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="columns"><i class="bi bi-layout-split me-2"></i>Duas colunas</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="separator"><i class="bi bi-dash-lg me-2"></i>Separador</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Conteúdo dinâmico do Portal</h6></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_posts"><i class="bi bi-newspaper me-2"></i>Últimas Notícias</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_events"><i class="bi bi-calendar-event me-2"></i>Agenda / Eventos</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_documents"><i class="bi bi-file-earmark-text me-2"></i>Documentos</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_galleries"><i class="bi bi-images me-2"></i>Galerias</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_communities"><i class="bi bi-geo-alt me-2"></i>Comunidades</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_most_read"><i class="bi bi-bar-chart-line me-2"></i>Mais Lidas</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_leadership"><i class="bi bi-people me-2"></i>Lideranças</button></li>
                    <li><button class="dropdown-item" type="button" data-block-add="portal_gallery_embed"><i class="bi bi-images me-2"></i>Importar Galeria</button></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card-body">
        <input type="hidden" name="content_blocks_json" id="contentBlocksJson" value="">
        <script type="application/json" id="contentBlocksInitial"><?= $contentBlocksJson ?: '[]' ?></script>
        <script type="application/json" id="contentPatternsInitial"><?= $contentPatternsJson ?: '[]' ?></script>
        <script type="application/json" id="contentDynamicOptionsInitial"><?= $contentDynamicOptionsJson ?: '{}' ?></script>

        <div class="alert alert-light border small mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                O editor tradicional continua disponível. Os blocos são exibidos logo depois dele no conteúdo público.
            </span>
            <?php if (Auth::can('paginas.gerenciar') || Auth::can('noticias.gerenciar')): ?>
                <a
                    class="btn btn-sm btn-link text-decoration-none p-0"
                    target="_blank"
                    href="<?= e(url('admin/padroes-conteudo/index.php')) ?>"
                >Gerenciar padrões</a>
            <?php endif; ?>
        </div>

        <div class="content-block-list d-grid gap-3" data-block-list></div>
        <div class="text-center text-secondary py-4" data-block-empty>
            Nenhum bloco adicionado.
        </div>
    </div>
</section>

<?php if ($contentPatterns): ?>
<div class="modal fade" id="contentPatternsModal" tabindex="-1" aria-labelledby="contentPatternsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="contentPatternsModalLabel">Inserir padrão de conteúdo</h2>
                    <div class="small text-secondary">Os blocos do padrão serão adicionados ao final do conteúdo atual.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <?php foreach ($contentPatterns as $pattern): ?>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 d-flex flex-column">
                                <div class="fw-semibold"><?= e((string)$pattern['nome']) ?></div>
                                <?php if (!empty($pattern['descricao'])): ?>
                                    <div class="small text-secondary mt-1 mb-3">
                                        <?= e((string)$pattern['descricao']) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-secondary mt-1 mb-3">
                                        <?= count((array)($pattern['blocks'] ?? [])) ?> bloco(s)
                                    </div>
                                <?php endif; ?>

                                <button
                                    class="btn btn-sm btn-outline-primary mt-auto align-self-start"
                                    type="button"
                                    data-pattern-insert="<?= (int)$pattern['id'] ?>"
                                >
                                    Inserir este padrão
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <a
                    class="btn btn-link text-decoration-none"
                    target="_blank"
                    href="<?= e(url('admin/padroes-conteudo/index.php')) ?>"
                >Gerenciar padrões</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
