<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('banners.gerenciar');
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$banner = [
    'titulo' => '', 'subtitulo' => '', 'imagem_id' => '', 'url_link' => '', 'texto_botao' => '',
    'nova_aba' => 0, 'ativo' => 1, 'ordem' => 10, 'data_inicio' => '', 'data_fim' => '',
];
$error = '';

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM banners WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Banner não encontrado.');
    }
    $banner = $found;
}

$images = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias WHERE mime_type LIKE 'image/%' ORDER BY id DESC"
)->fetchAll();
$midias = $images;
$currentBannerImage = !empty($banner['imagem_id']) ? MediaService::find($pdo, (int)$banner['imagem_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $banner = array_merge($banner, $_POST);
    $banner['nova_aba'] = isset($_POST['nova_aba']) ? 1 : 0;
    $banner['ativo'] = isset($_POST['ativo']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $imagemId = (int)($_POST['imagem_id'] ?? 0);
            $validIds = array_fill_keys(array_map(static fn($img) => (int)$img['id'], $images), true);
            if ($imagemId <= 0 || !isset($validIds[$imagemId])) {
                throw new RuntimeException('Selecione uma imagem válida para o banner.');
            }

            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $subtitulo = trim((string)($_POST['subtitulo'] ?? ''));
            $urlLink = trim((string)($_POST['url_link'] ?? ''));
            $textoBotao = trim((string)($_POST['texto_botao'] ?? ''));
            $novaAba = isset($_POST['nova_aba']) ? 1 : 0;
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            $ordem = (int)($_POST['ordem'] ?? 10);
            $inicioInput = trim((string)($_POST['data_inicio'] ?? ''));
            $fimInput = trim((string)($_POST['data_fim'] ?? ''));
            $inicio = null;
            $fim = null;
            if ($inicioInput !== '') {
                $timestampInicio = strtotime($inicioInput);
                if ($timestampInicio === false) throw new RuntimeException('Data inicial inválida.');
                $inicio = date('Y-m-d H:i:s', $timestampInicio);
            }
            if ($fimInput !== '') {
                $timestampFim = strtotime($fimInput);
                if ($timestampFim === false) throw new RuntimeException('Data final inválida.');
                $fim = date('Y-m-d H:i:s', $timestampFim);
            }

            if ($inicio && $fim && strtotime($fim) < strtotime($inicio)) {
                throw new RuntimeException('A data final não pode ser anterior à data inicial.');
            }
            if ($urlLink !== '' && preg_match('#^javascript:#i', $urlLink)) {
                throw new RuntimeException('O link informado não é permitido.');
            }
            if ($textoBotao !== '' && $urlLink === '') {
                throw new RuntimeException('Informe o link do banner para utilizar texto no botão.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE banners SET titulo=:titulo,subtitulo=:subtitulo,imagem_id=:imagem,url_link=:link,texto_botao=:botao,
                     nova_aba=:nova_aba,ativo=:ativo,ordem=:ordem,data_inicio=:inicio,data_fim=:fim WHERE id=:id'
                );
                $stmt->execute([
                    'titulo' => $titulo ?: null, 'subtitulo' => $subtitulo ?: null, 'imagem' => $imagemId,
                    'link' => $urlLink ?: null, 'botao' => $textoBotao ?: null, 'nova_aba' => $novaAba,
                    'ativo' => $ativo, 'ordem' => $ordem, 'inicio' => $inicio, 'fim' => $fim, 'id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO banners (titulo,subtitulo,imagem_id,url_link,texto_botao,nova_aba,ativo,ordem,data_inicio,data_fim)
                     VALUES (:titulo,:subtitulo,:imagem,:link,:botao,:nova_aba,:ativo,:ordem,:inicio,:fim)'
                );
                $stmt->execute([
                    'titulo' => $titulo ?: null, 'subtitulo' => $subtitulo ?: null, 'imagem' => $imagemId,
                    'link' => $urlLink ?: null, 'botao' => $textoBotao ?: null, 'nova_aba' => $novaAba,
                    'ativo' => $ativo, 'ordem' => $ordem, 'inicio' => $inicio, 'fim' => $fim,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            logAction($pdo, 'banner.salvar', 'banners', $id, $titulo ?: 'Banner');
            Session::flash('success', 'Banner salvo com sucesso.');
            header('Location: ' . url('admin/banners/index.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = $id > 0 ? 'Editar banner' : 'Novo banner';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $id > 0 ? 'Editar banner' : 'Novo banner' ?></h1>
        <p class="text-secondary mb-0">Defina imagem, texto, destino e período de exibição na Home.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/banners/index.php')) ?>">Voltar</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                                        <div class="mb-3">
                        <label class="form-label">Imagem</label>

                        <input
                            type="hidden"
                            name="imagem_id"
                            id="bannerImageId"
                            value="<?= e((string)$banner['imagem_id']) ?>"
                        >

                        <div
                            id="bannerImagePreview"
                            class="border rounded-3 p-3 bg-body-tertiary"
                        >
                            <?php if (
                                $currentBannerImage
                                && MediaService::isImage($currentBannerImage)
                            ): ?>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <img
                                        src="<?= e(mediaUrl((string)$currentBannerImage['caminho'])) ?>"
                                        alt="<?= e(
                                            (string)(
                                                $currentBannerImage['alt_text']
                                                ?: $currentBannerImage['titulo']
                                                ?: $currentBannerImage['nome_original']
                                            )
                                        ) ?>"
                                        class="img-thumbnail featured-preview"
                                    >
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)(
                                                $currentBannerImage['titulo']
                                                ?: $currentBannerImage['nome_original']
                                            )
                                        ) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-secondary small">
                                    Nenhuma imagem selecionada.
                                </div>
                            <?php endif; ?>
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-primary mt-3"
                            id="bannerImageOpen"
                        >
                            <i class="bi bi-images me-1"></i>
                            Escolher na Biblioteca de Mídia
                        </button>

                        <div class="form-text">
                            A imagem é obrigatória. O upload também pode ser feito dentro do modal.
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Título</label>
                            <input class="form-control" name="titulo" value="<?= e((string)$banner['titulo']) ?>" maxlength="180">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subtítulo</label>
                            <textarea class="form-control" name="subtitulo" rows="3" maxlength="500"><?= e((string)$banner['subtitulo']) ?></textarea>
                        </div>
                        <div class="col-lg-8">
                            <label class="form-label">Link</label>
                            <input class="form-control" name="url_link" value="<?= e((string)$banner['url_link']) ?>" placeholder="evento/minha-slug ou https://...">
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label">Texto do botão</label>
                            <input class="form-control" name="texto_botao" value="<?= e((string)$banner['texto_botao']) ?>" placeholder="Saiba mais">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Exibição</div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Ordem</label>
                        <input class="form-control" type="number" name="ordem" value="<?= (int)$banner['ordem'] ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Início da exibição</label>
                        <input class="form-control" type="datetime-local" name="data_inicio" value="<?= e(!empty($banner['data_inicio']) ? date('Y-m-d\TH:i', strtotime((string)$banner['data_inicio'])) : '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fim da exibição</label>
                        <input class="form-control" type="datetime-local" name="data_fim" value="<?= e(!empty($banner['data_fim']) ? date('Y-m-d\TH:i', strtotime((string)$banner['data_fim'])) : '') ?>">
                    </div>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="ativo" name="ativo" <?= (int)$banner['ativo'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Banner ativo</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="nova_aba" name="nova_aba" <?= (int)$banner['nova_aba'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="nova_aba">Abrir link em nova aba</label></div>
                </div>
            </div>
            <button class="btn btn-primary w-100 py-2">Salvar banner</button>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../_editor_media_picker.php'; ?>

<script src="<?= e(url('public/js/editor-media-picker.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.89.0'))) ?>"></script>
<script src="<?= e(url('public/js/admin-image-modal-v89-r5.js?v=0.89.0-r5')) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(url('admin/midias/upload-editor.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    csrfToken: <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
});

PortalAdminImageModal.bindSingle({
    openButton: document.getElementById('bannerImageOpen'),
    input: document.getElementById('bannerImageId'),
    preview: document.getElementById('bannerImagePreview'),
    title: 'Escolher imagem do banner',
    subtitle: 'Selecione uma imagem da Biblioteca de Mídia ou faça upload de uma nova.',
    confirmText: 'Usar no banner'
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
