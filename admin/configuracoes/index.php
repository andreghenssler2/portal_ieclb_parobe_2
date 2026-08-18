<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
Auth::requirePermission('configuracoes.gerenciar');
$pdo = Database::connection();

$settings = siteConfigAll($pdo);
$error = '';
$secao = (string)($_GET['secao'] ?? 'geral');
if (!in_array($secao, ['geral', 'seo', 'aparencia'], true)) {
    $secao = 'geral';
}

$defaults = [
    'site_nome' => 'Paróquia Evangélica de Confissão Luterana de Parobé',
    'site_descricao' => 'Portal da IECLB Parobé',
    'site_email' => '',
    'site_telefone' => '',
    'site_endereco' => '',
    'site_instagram' => '',
    'site_youtube' => '',
    'site_facebook' => '',
    'site_logo_id' => '',
    'site_favicon_id' => '',
    'hero_titulo' => 'IECLB Parobé',
    'hero_subtitulo' => 'Notícias, cultos, eventos e informações das comunidades da Paróquia de Parobé.',
    'footer_texto' => 'Paróquia Evangélica de Confissão Luterana de Parobé',
    'seo_titulo' => 'IECLB Parobé',
    'seo_descricao' => 'Portal da IECLB Parobé',
    'seo_keywords' => 'IECLB, Parobé, igreja luterana, cultos, eventos',
    'seo_og_image_id' => '',
];
$settings = array_merge($defaults, $settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($defaults) as $key) {
        if (array_key_exists($key, $_POST)) {
            $settings[$key] = trim((string)$_POST[$key]);
        }
    }

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $uploadMap = [
                'site_logo_upload' => 'site_logo_id',
                'site_favicon_upload' => 'site_favicon_id',
                'seo_og_image_upload' => 'seo_og_image_id',
            ];

            foreach ($uploadMap as $fileField => $settingKey) {
                if (isset($_FILES[$fileField]) && (int)($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $media = MediaService::upload($pdo, $_FILES[$fileField], (int)Auth::id());
                    if (!MediaService::isImage($media)) {
                        MediaService::delete($pdo, (int)$media['id']);
                        throw new RuntimeException('Logo, favicon e imagem social precisam ser arquivos de imagem.');
                    }
                    $settings[$settingKey] = (string)$media['id'];
                    logAction($pdo, 'midia.upload', 'midias', (int)$media['id'], 'Imagem enviada pelas configurações do portal');
                }
            }

            if ($settings['site_email'] !== '' && !filter_var($settings['site_email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }

            foreach (['site_instagram', 'site_youtube', 'site_facebook'] as $urlField) {
                if ($settings[$urlField] !== '' && !filter_var($settings[$urlField], FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('Informe URLs completas e válidas para as redes sociais.');
                }
            }

            $types = [
                'site_email' => 'email',
                'site_instagram' => 'url',
                'site_youtube' => 'url',
                'site_facebook' => 'url',
                'site_logo_id' => 'numero',
                'site_favicon_id' => 'numero',
                'seo_og_image_id' => 'numero',
            ];

            $pdo->beginTransaction();
            foreach ($defaults as $key => $_default) {
                saveSiteConfig($pdo, $key, $settings[$key] ?? '', $types[$key] ?? 'texto');
            }
            $pdo->commit();

            logAction($pdo, 'configuracoes.atualizar', 'configuracoes', null, 'Configurações gerais e SEO atualizadas');
            Session::flash('success', 'Configurações atualizadas com sucesso.');
            header('Location: ' . url('admin/configuracoes/index.php?secao=' . urlencode($secao)));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$images = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC"
)->fetchAll();


$pageTitle = 'Configurações';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Configurações do Portal</h1>
        <p class="text-secondary mb-0">Identidade, contatos, página inicial e SEO.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url()) ?>" target="_blank">Ver portal</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="card border-0 shadow-sm mb-4" id="config-geral">
        <div class="card-header bg-white fw-semibold">Identidade e contato</div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-lg-8">
                    <label class="form-label">Nome completo da paróquia</label>
                    <input class="form-control" name="site_nome" value="<?= e($settings['site_nome']) ?>" required>
                </div>
                <div class="col-lg-4">
                    <label class="form-label">Telefone</label>
                    <input class="form-control" name="site_telefone" value="<?= e($settings['site_telefone']) ?>">
                </div>
                <div class="col-lg-8">
                    <label class="form-label">Descrição curta</label>
                    <input class="form-control" name="site_descricao" value="<?= e($settings['site_descricao']) ?>">
                </div>
                <div class="col-lg-4">
                    <label class="form-label">E-mail</label>
                    <input class="form-control" type="email" name="site_email" value="<?= e($settings['site_email']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Endereço</label>
                    <input class="form-control" name="site_endereco" value="<?= e($settings['site_endereco']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Instagram</label>
                    <input class="form-control" type="url" name="site_instagram" value="<?= e($settings['site_instagram']) ?>" placeholder="https://...">
                </div>
                <div class="col-md-4">
                    <label class="form-label">YouTube</label>
                    <input class="form-control" type="url" name="site_youtube" value="<?= e($settings['site_youtube']) ?>" placeholder="https://...">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Facebook</label>
                    <input class="form-control" type="url" name="site_facebook" value="<?= e($settings['site_facebook']) ?>" placeholder="https://...">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" id="aparencia">
        <div class="card-header bg-white fw-semibold">Identidade visual</div>
        <div class="card-body p-4">
            <div class="row g-4">
                <?php
                $visualFields = [
                    ['site_logo_id', 'site_logo_upload', 'Logo do portal', 'Usado no cabeçalho público.'],
                    ['site_favicon_id', 'site_favicon_upload', 'Favicon', 'Ícone exibido na aba do navegador.'],
                    ['seo_og_image_id', 'seo_og_image_upload', 'Imagem padrão de compartilhamento', 'Usada quando a página não possui imagem destacada.'],
                ];
                foreach ($visualFields as [$settingKey, $uploadKey, $label, $help]):
                ?>
                <div class="col-lg-4"<?= $settingKey === 'seo_og_image_id' ? ' id="seo-social"' : '' ?>>
                    <label class="form-label fw-semibold"><?= e($label) ?></label>
                    <select class="form-select mb-2 image-setting-select" name="<?= e($settingKey) ?>" data-preview="preview-<?= e($settingKey) ?>">
                        <option value="">Nenhuma imagem</option>
                        <?php foreach ($images as $image): ?>
                            <option value="<?= (int)$image['id'] ?>" data-url="<?= e(mediaUrl($image['caminho'])) ?>" <?= (string)$settings[$settingKey] === (string)$image['id'] ? 'selected' : '' ?>><?= e($image['titulo'] ?: $image['nome_original']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control" type="file" name="<?= e($uploadKey) ?>" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="form-text"><?= e($help) ?></div>
                    <div id="preview-<?= e($settingKey) ?>" class="mt-3 config-image-preview"></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" id="leitura">
        <div class="card-header bg-white fw-semibold">Página inicial e rodapé</div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label">Título principal</label>
                    <input class="form-control" name="hero_titulo" value="<?= e($settings['hero_titulo']) ?>">
                </div>
                <div class="col-lg-6">
                    <label class="form-label">Texto do rodapé</label>
                    <input class="form-control" name="footer_texto" value="<?= e($settings['footer_texto']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Texto de apresentação</label>
                    <textarea class="form-control" name="hero_subtitulo" rows="3"><?= e($settings['hero_subtitulo']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" id="seo-geral">
        <div class="card-header bg-white fw-semibold">SEO</div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Título padrão</label>
                    <input class="form-control" name="seo_titulo" value="<?= e($settings['seo_titulo']) ?>" maxlength="180">
                </div>
                <div class="col-12">
                    <label class="form-label">Descrição padrão</label>
                    <textarea class="form-control" name="seo_descricao" rows="3" maxlength="320"><?= e($settings['seo_descricao']) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Palavras-chave</label>
                    <input class="form-control" name="seo_keywords" value="<?= e($settings['seo_keywords']) ?>" placeholder="IECLB, Parobé, cultos, eventos">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary px-4">Salvar configurações</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/index.php')) ?>">Cancelar</a>
    </div>
</form>

<script>
function renderSettingPreview(select) {
    const target = document.getElementById(select.dataset.preview);
    if (!target) return;
    const option = select.options[select.selectedIndex];
    const src = option ? option.dataset.url : '';
    target.innerHTML = src ? '<img src="' + src.replace(/"/g, '&quot;') + '" alt="Pré-visualização">' : '';
}
document.querySelectorAll('.image-setting-select').forEach(function (select) {
    renderSettingPreview(select);
    select.addEventListener('change', function () { renderSettingPreview(select); });
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
