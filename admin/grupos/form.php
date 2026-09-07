<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/GroupService.php';

Auth::requirePermission('grupos.gerenciar');

$pdo = Database::connection();

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$item = $id > 0 ? GroupService::find($pdo, $id) : null;

if ($id > 0 && !$item) {
    http_response_code(404);
    exit('Grupo / Ministério não encontrado.');
}

$defaults = [
    'nome' => '',
    'slug' => '',
    'tipo' => 'grupo',
    'resumo' => '',
    'descricao' => '',
    'responsavel' => '',
    'email' => '',
    'telefone' => '',
    'whatsapp' => '',
    'local' => '',
    'dia_semana' => '',
    'horario' => '',
    'instagram' => '',
    'facebook' => '',
    'comunidade_id' => '',
    'imagem_id' => '',
    'ativo' => 1,
    'ordem' => 0,
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
];

$item = array_merge($defaults, $item ?: []);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($defaults) as $key) {
        if (array_key_exists($key, $_POST)) {
            $item[$key] = $_POST[$key];
        }
    }

    $item['ativo'] = isset($_POST['ativo']) ? 1 : 0;
    $item['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $savedId = GroupService::save(
                $pdo,
                array_merge(
                    $_POST,
                    [
                        'id' => $id,
                        'ativo' => isset($_POST['ativo']) ? 1 : 0,
                        'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    ]
                ),
                (int)Auth::id()
            );

            logAction(
                $pdo,
                $id > 0 ? 'grupo.editar' : 'grupo.criar',
                'grupos',
                $savedId,
                trim((string)($_POST['nome'] ?? ''))
            );

            Session::flash(
                'success',
                $id > 0
                    ? 'Grupo / Ministério atualizado.'
                    : 'Grupo / Ministério criado.'
            );

            header(
                'Location: '
                . url('admin/grupos/form.php?id=' . $savedId)
            );
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$communities = GroupService::communities($pdo);

$currentImage = !empty($item['imagem_id'])
    ? MediaService::find($pdo, (int)$item['imagem_id'])
    : null;

$weekDays = [
    '' => 'Não informado',
    'Domingo' => 'Domingo',
    'Segunda-feira' => 'Segunda-feira',
    'Terça-feira' => 'Terça-feira',
    'Quarta-feira' => 'Quarta-feira',
    'Quinta-feira' => 'Quinta-feira',
    'Sexta-feira' => 'Sexta-feira',
    'Sábado' => 'Sábado',
    'Variável' => 'Variável',
];

$pageTitle = $id > 0
    ? 'Editar Grupo / Ministério'
    : 'Novo Grupo / Ministério';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-secondary mb-0">
            Cadastre as informações administrativas do grupo ou ministério.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/grupos/index.php')) ?>"
    >
        Voltar
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" id="groupForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nome</label>
                            <input
                                class="form-control form-control-lg"
                                name="nome"
                                maxlength="180"
                                required
                                value="<?= e((string)$item['nome']) ?>"
                                placeholder="Ex.: OASE, Juventude Evangélica, Ministério de Música"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tipo</label>
                            <select class="form-select form-select-lg" name="tipo">
                                <?php foreach (GroupService::typeLabels() as $value => $label): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= (string)$item['tipo'] === $value ? 'selected' : '' ?>
                                    >
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Resumo</label>
                            <input
                                class="form-control"
                                name="resumo"
                                maxlength="500"
                                value="<?= e((string)$item['resumo']) ?>"
                                placeholder="Descrição curta do grupo"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Slug</label>
                            <input
                                class="form-control"
                                name="slug"
                                maxlength="220"
                                value="<?= e((string)$item['slug']) ?>"
                                placeholder="automática"
                            >
                            <div class="form-text">
                                Pode deixar em branco para gerar automaticamente.
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Descrição / Apresentação</label>
                            <textarea
                                class="form-control"
                                name="descricao"
                                rows="10"
                                placeholder="Objetivos, público, atividades e demais informações."
                            ><?= e((string)$item['descricao']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    Responsável e contatos
                </div>

                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Responsável / Coordenação</label>
                            <input
                                class="form-control"
                                name="responsavel"
                                maxlength="180"
                                value="<?= e((string)$item['responsavel']) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input
                                class="form-control"
                                type="email"
                                name="email"
                                maxlength="190"
                                value="<?= e((string)$item['email']) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input
                                class="form-control"
                                name="telefone"
                                maxlength="40"
                                value="<?= e((string)$item['telefone']) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">WhatsApp</label>
                            <input
                                class="form-control"
                                name="whatsapp"
                                maxlength="40"
                                value="<?= e((string)$item['whatsapp']) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Instagram</label>
                            <input
                                class="form-control"
                                name="instagram"
                                maxlength="500"
                                value="<?= e((string)$item['instagram']) ?>"
                                placeholder="https://instagram.com/..."
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Facebook</label>
                            <input
                                class="form-control"
                                name="facebook"
                                maxlength="500"
                                value="<?= e((string)$item['facebook']) ?>"
                                placeholder="https://facebook.com/..."
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">SEO</div>

                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Título SEO</label>
                        <input
                            class="form-control"
                            name="seo_titulo"
                            maxlength="220"
                            value="<?= e((string)$item['seo_titulo']) ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descrição SEO</label>
                        <textarea
                            class="form-control"
                            name="seo_descricao"
                            maxlength="320"
                            rows="3"
                        ><?= e((string)$item['seo_descricao']) ?></textarea>
                    </div>

                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="seo_noindex"
                            id="seoNoindex"
                            <?= !empty($item['seo_noindex']) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="seoNoindex">
                            Não indexar quando a página pública deste módulo for ativada
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    Publicação
                </div>

                <div class="card-body p-4">
                    <div class="form-check form-switch mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="ativo"
                            id="groupActive"
                            <?= !empty($item['ativo']) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="groupActive">
                            Grupo / Ministério ativo
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ordem</label>
                        <input
                            class="form-control"
                            type="number"
                            name="ordem"
                            value="<?= e((string)$item['ordem']) ?>"
                        >
                        <div class="form-text">
                            Números menores aparecem primeiro.
                        </div>
                    </div>

                    <button class="btn btn-primary w-100">
                        <?= $id > 0 ? 'Salvar alterações' : 'Criar grupo / ministério' ?>
                    </button>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    Comunidade
                </div>

                <div class="card-body p-4">
                    <label class="form-label">Vínculo</label>
                    <select class="form-select" name="comunidade_id">
                        <option value="">Paroquial / Todas</option>

                        <?php foreach ($communities as $community): ?>
                            <option
                                value="<?= (int)$community['id'] ?>"
                                <?= (string)$item['comunidade_id'] === (string)$community['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($community['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    Encontros
                </div>

                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Dia</label>
                        <select class="form-select" name="dia_semana">
                            <?php foreach ($weekDays as $value => $label): ?>
                                <option
                                    value="<?= e($value) ?>"
                                    <?= (string)$item['dia_semana'] === $value ? 'selected' : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Horário</label>
                        <input
                            class="form-control"
                            name="horario"
                            maxlength="80"
                            value="<?= e((string)$item['horario']) ?>"
                            placeholder="Ex.: 19h30"
                        >
                    </div>

                    <div>
                        <label class="form-label">Local</label>
                        <input
                            class="form-control"
                            name="local"
                            maxlength="255"
                            value="<?= e((string)$item['local']) ?>"
                            placeholder="Ex.: Sala da Comunidade Martin Luther"
                        >
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    Imagem
                </div>

                <div class="card-body p-4">
                    <input
                        type="hidden"
                        name="imagem_id"
                        id="groupImageId"
                        value="<?= e((string)$item['imagem_id']) ?>"
                    >

                    <div id="groupImagePreview" class="featured-picker-preview">
                        <?php if (
                            $currentImage
                            && MediaService::isImage($currentImage)
                        ): ?>
                            <div class="d-flex align-items-center gap-3">
                                <img
                                    src="<?= e(mediaUrl((string)$currentImage['caminho'])) ?>"
                                    alt="<?= e(
                                        $currentImage['alt_text']
                                        ?: $currentImage['titulo']
                                        ?: $currentImage['nome_original']
                                    ) ?>"
                                    class="img-thumbnail featured-preview"
                                >

                                <div>
                                    <div class="fw-semibold">
                                        <?= e(
                                            $currentImage['titulo']
                                            ?: $currentImage['nome_original']
                                        ) ?>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-link text-danger p-0 mt-1"
                                        data-media-featured-remove
                                    >
                                        Remover imagem
                                    </button>
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
                        class="btn btn-outline-primary w-100 mt-3"
                        data-media-featured-open
                    >
                        <i class="bi bi-images me-1"></i>
                        Escolher na Biblioteca
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_editor_media_picker.php'; ?>

<script src="<?= e(url('public/js/editor-media-picker.js')) ?>"></script>
<script>
PortalMediaPicker.init({
    modalId: 'portalMediaPickerModal',
    uploadUrl: <?= json_encode(
        url('admin/midias/upload-editor.php'),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>,
    csrfToken: <?= json_encode(
        Csrf::token(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>
});

PortalMediaPicker.bindFeatured({
    openButton: document.querySelector('[data-media-featured-open]'),
    removeButtonSelector: '[data-media-featured-remove]',
    input: document.getElementById('groupImageId'),
    preview: document.getElementById('groupImagePreview')
});
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
