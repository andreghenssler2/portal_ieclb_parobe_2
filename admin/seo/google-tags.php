<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('seo.gerenciar');

$pdo = Database::connection();

$defaults = [
    'google_tag_manager_ativo' => '0',
    'google_tag_manager_container_id' => '',
];

$settings =
    array_merge(
        $defaults,
        siteConfigAll($pdo, true)
    );

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['google_tag_manager_ativo'] =
        isset($_POST['google_tag_manager_ativo'])
            ? '1'
            : '0';

    $settings['google_tag_manager_container_id'] =
        strtoupper(
            trim(
                (string)(
                    $_POST['google_tag_manager_container_id']
                    ?? ''
                )
            )
        );

    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $error =
            'Token de segurança inválido.';
    } else {
        try {
            $containerId =
                $settings['google_tag_manager_container_id'];

            if (
                $containerId !== ''
                && !preg_match(
                    '/^GTM-[A-Z0-9]+$/',
                    $containerId
                )
            ) {
                throw new RuntimeException(
                    'Informe um ID válido do Google Tag Manager, por exemplo GTM-ABC1234.'
                );
            }

            if (
                $settings['google_tag_manager_ativo'] === '1'
                && $containerId === ''
            ) {
                throw new RuntimeException(
                    'Informe o ID do contêiner antes de ativar o Google Tag Manager.'
                );
            }

            saveSiteConfig(
                $pdo,
                'google_tag_manager_ativo',
                $settings['google_tag_manager_ativo'],
                'booleano'
            );

            saveSiteConfig(
                $pdo,
                'google_tag_manager_container_id',
                $containerId,
                'texto'
            );

            logAction(
                $pdo,
                'seo.google_tag_manager.atualizar',
                'configuracoes',
                null,
                'Google Tag Manager '
                . (
                    $settings['google_tag_manager_ativo'] === '1'
                        ? 'ativado'
                        : 'desativado'
                )
                . (
                    $containerId !== ''
                        ? ' (' . $containerId . ')'
                        : ''
                )
            );

            Session::flash(
                'success',
                $settings['google_tag_manager_ativo'] === '1'
                    ? 'Google Tag Manager ativado no Portal.'
                    : 'Google Tag Manager desativado.'
            );

            header(
                'Location: '
                . url(
                    'admin/seo/google-tags.php'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$containerId =
    trim(
        (string)(
            $settings['google_tag_manager_container_id']
            ?? ''
        )
    );

$isValidId =
    $containerId !== ''
    && (bool)preg_match(
        '/^GTM-[A-Z0-9]+$/',
        $containerId
    );

$isActive =
    ($settings['google_tag_manager_ativo'] ?? '0') === '1'
    && $isValidId;

$pageTitle =
    'SEO - Google Tag Manager';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            SEO · Google Tag Manager
        </h1>

        <p class="text-secondary mb-0">
            Configure o contêiner do Google Tag Manager sem editar manualmente o tema.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        target="_blank"
        rel="noopener"
        href="<?= e(url()) ?>"
    >
        Ver portal
    </a>
</div>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <form
            method="post"
            class="card border-0 shadow-sm"
        >
            <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="fw-semibold">
                    Configuração do contêiner
                </span>

                <span
                    class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>"
                >
                    <?= $isActive ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>

            <div class="card-body p-4">
                <?= Csrf::field() ?>

                <div class="mb-4">
                    <label
                        class="form-label fw-semibold"
                        for="googleTagManagerContainerId"
                    >
                        ID do contêiner
                    </label>

                    <input
                        class="form-control font-monospace"
                        type="text"
                        name="google_tag_manager_container_id"
                        id="googleTagManagerContainerId"
                        maxlength="32"
                        value="<?= e($containerId) ?>"
                        placeholder="GTM-ABC1234"
                        autocomplete="off"
                    >

                    <div class="form-text">
                        Encontre este código no Google Tag Manager. O formato começa com <code>GTM-</code>.
                    </div>
                </div>

                <div class="form-check form-switch mb-3">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        name="google_tag_manager_ativo"
                        id="googleTagManagerActive"
                        <?= $settings['google_tag_manager_ativo'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="googleTagManagerActive"
                    >
                        Ativar Google Tag Manager no Portal público
                    </label>
                </div>

                <div class="alert alert-light border mb-0">
                    <div class="fw-semibold mb-1">
                        Instalação automática
                    </div>

                    <div class="small text-secondary">
                        Quando ativado, o Portal insere automaticamente o código do Google Tag Manager no <code>&lt;head&gt;</code> e o bloco <code>&lt;noscript&gt;</code> logo após o início do <code>&lt;body&gt;</code>.
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white d-flex justify-content-end">
                <button
                    type="submit"
                    class="btn btn-primary px-4"
                >
                    Salvar configurações
                </button>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Estado atual
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span class="text-secondary">
                        Contêiner
                    </span>

                    <strong class="font-monospace">
                        <?= e($containerId !== '' ? $containerId : 'Não informado') ?>
                    </strong>
                </div>

                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span class="text-secondary">
                        ID válido
                    </span>

                    <strong class="<?= $isValidId ? 'text-success' : 'text-secondary' ?>">
                        <?= $isValidId ? 'Sim' : 'Não' ?>
                    </strong>
                </div>

                <div class="d-flex justify-content-between gap-3 py-2">
                    <span class="text-secondary">
                        Carregamento
                    </span>

                    <strong class="<?= $isActive ? 'text-success' : 'text-secondary' ?>">
                        <?= $isActive ? 'Ativo' : 'Desativado' ?>
                    </strong>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Observações
            </div>

            <div class="card-body small text-secondary">
                <p>
                    O Google Tag Manager permite administrar tags como Google Analytics, conversões e outras integrações diretamente pelo painel do Google.
                </p>

                <p class="mb-0">
                    O Portal não publica automaticamente nenhuma tag além do contêiner GTM. As tags e regras de consentimento devem ser configuradas dentro do seu contêiner.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
