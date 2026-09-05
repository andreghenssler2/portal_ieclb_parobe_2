<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo = Database::connection();

if (!class_exists('CookieConsentService')) {
    throw new RuntimeException('CookieConsentService indisponível.');
}

$defaults = CookieConsentService::defaults();
$settings = array_merge($defaults, siteConfigAll($pdo, true));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $settings['cookie_consent_enabled'] =
                isset($_POST['cookie_consent_enabled']) ? '1' : '0';

            $settings['cookie_consent_version'] =
                (string)max(1, min(999, (int)($_POST['cookie_consent_version'] ?? 1)));

            $settings['cookie_consent_days'] =
                (string)max(30, min(730, (int)($_POST['cookie_consent_days'] ?? 180)));

            foreach (
                [
                    'cookie_consent_title',
                    'cookie_consent_text',
                    'cookie_consent_analytics_label',
                    'cookie_consent_analytics_description',
                    'cookie_consent_marketing_label',
                    'cookie_consent_marketing_description',
                ]
                as $key
            ) {
                $settings[$key] = trim((string)($_POST[$key] ?? ''));
            }

            $settings['cookie_gtm_category'] =
                strtolower(trim((string)($_POST['cookie_gtm_category'] ?? 'analytics')));

            if (!in_array($settings['cookie_gtm_category'], ['analytics', 'marketing'], true)) {
                $settings['cookie_gtm_category'] = 'analytics';
            }

            $settings['cookie_preferences_footer_link'] =
                isset($_POST['cookie_preferences_footer_link']) ? '1' : '0';

            if ($settings['cookie_consent_title'] === '') {
                throw new RuntimeException('Informe o título do aviso de cookies.');
            }

            if ($settings['cookie_consent_text'] === '') {
                throw new RuntimeException('Informe o texto do aviso de cookies.');
            }

            $types = [
                'cookie_consent_enabled' => 'booleano',
                'cookie_consent_version' => 'numero',
                'cookie_consent_days' => 'numero',
                'cookie_consent_title' => 'texto',
                'cookie_consent_text' => 'texto',
                'cookie_consent_analytics_label' => 'texto',
                'cookie_consent_analytics_description' => 'texto',
                'cookie_consent_marketing_label' => 'texto',
                'cookie_consent_marketing_description' => 'texto',
                'cookie_gtm_category' => 'texto',
                'cookie_preferences_footer_link' => 'booleano',
            ];

            foreach ($defaults as $key => $_default) {
                saveSiteConfig(
                    $pdo,
                    $key,
                    (string)$settings[$key],
                    $types[$key] ?? 'texto'
                );
            }

            logAction(
                $pdo,
                'configuracoes.cookies',
                'configuracoes',
                null,
                'Central de consentimento atualizada; versão '
                . $settings['cookie_consent_version']
            );

            Session::flash(
                'success',
                'Preferências de cookies e consentimento atualizadas.'
            );

            header('Location: ' . url('admin/configuracoes/cookies.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$gtmId = trim(siteConfig($pdo, 'google_tag_manager_container_id', ''));
$gtmEnabled = siteConfig($pdo, 'google_tag_manager_ativo', '0') === '1';
$ga4Id = trim(siteConfig($pdo, 'analytics_measurement_id', ''));
$ga4Enabled = siteConfig($pdo, 'analytics_enabled', '0') === '1';

$pageTitle = 'Cookies e Consentimento';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Cookies e Consentimento</h1>
        <p class="text-secondary mb-0">Controle o aviso público e o carregamento de tecnologias opcionais.</p>
    </div>

    <a class="btn btn-outline-secondary" href="<?= e(url()) ?>" target="_blank" rel="noopener">Ver Portal</a>
</div>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Central de consentimento</div>
        <div class="card-body p-4">
            <div class="form-check form-switch mb-4">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    name="cookie_consent_enabled"
                    id="cookieConsentEnabled"
                    <?= $settings['cookie_consent_enabled'] === '1' ? 'checked' : '' ?>
                >
                <label class="form-check-label fw-semibold" for="cookieConsentEnabled">
                    Ativar aviso e preferências de cookies no Portal público
                </label>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="cookieConsentVersion">Versão do consentimento</label>
                    <input
                        class="form-control"
                        type="number"
                        min="1"
                        max="999"
                        name="cookie_consent_version"
                        id="cookieConsentVersion"
                        value="<?= e((string)$settings['cookie_consent_version']) ?>"
                    >
                    <div class="form-text">Aumente este número quando quiser pedir consentimento novamente a todos.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="cookieConsentDays">Validade da escolha</label>
                    <div class="input-group">
                        <input
                            class="form-control"
                            type="number"
                            min="30"
                            max="730"
                            name="cookie_consent_days"
                            id="cookieConsentDays"
                            value="<?= e((string)$settings['cookie_consent_days']) ?>"
                        >
                        <span class="input-group-text">dias</span>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="cookieConsentTitle">Título</label>
                    <input
                        class="form-control"
                        name="cookie_consent_title"
                        id="cookieConsentTitle"
                        maxlength="120"
                        value="<?= e((string)$settings['cookie_consent_title']) ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label" for="cookieConsentText">Texto principal</label>
                    <textarea
                        class="form-control"
                        name="cookie_consent_text"
                        id="cookieConsentText"
                        rows="3"
                        maxlength="1000"
                    ><?= e((string)$settings['cookie_consent_text']) ?></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="cookie_preferences_footer_link"
                            id="cookiePreferencesFooterLink"
                            <?= $settings['cookie_preferences_footer_link'] === '1' ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="cookiePreferencesFooterLink">
                            Exibir “Preferências de cookies” no rodapé
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Categorias</div>
        <div class="card-body p-4">
            <div class="alert alert-light border">
                <strong>Necessários</strong> ficam sempre ativos e não podem ser recusados.
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <label class="form-label fw-semibold" for="cookieAnalyticsLabel">Nome da categoria de estatísticas</label>
                    <input
                        class="form-control mb-2"
                        name="cookie_consent_analytics_label"
                        id="cookieAnalyticsLabel"
                        value="<?= e((string)$settings['cookie_consent_analytics_label']) ?>"
                    >
                    <textarea
                        class="form-control"
                        rows="4"
                        name="cookie_consent_analytics_description"
                    ><?= e((string)$settings['cookie_consent_analytics_description']) ?></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label fw-semibold" for="cookieMarketingLabel">Nome da categoria de marketing</label>
                    <input
                        class="form-control mb-2"
                        name="cookie_consent_marketing_label"
                        id="cookieMarketingLabel"
                        value="<?= e((string)$settings['cookie_consent_marketing_label']) ?>"
                    >
                    <textarea
                        class="form-control"
                        rows="4"
                        name="cookie_consent_marketing_description"
                    ><?= e((string)$settings['cookie_consent_marketing_description']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Integrações</div>
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold mb-2">Google Analytics 4</div>
                        <div class="small text-secondary mb-3">
                            <?= $ga4Enabled && $ga4Id !== ''
                                ? 'Configurado: ' . e($ga4Id)
                                : 'Desativado ou não configurado' ?>
                        </div>
                        <span class="badge text-bg-info">Categoria: Estatísticas</span>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold mb-2">Google Tag Manager</div>
                        <div class="small text-secondary mb-3">
                            <?= $gtmEnabled && $gtmId !== ''
                                ? 'Configurado: ' . e($gtmId)
                                : 'Desativado ou não configurado' ?>
                        </div>

                        <label class="form-label" for="cookieGtmCategory">
                            Carregar o contêiner somente após consentimento de
                        </label>

                        <select class="form-select" name="cookie_gtm_category" id="cookieGtmCategory">
                            <option value="analytics" <?= $settings['cookie_gtm_category'] === 'analytics' ? 'selected' : '' ?>>
                                Estatísticas
                            </option>
                            <option value="marketing" <?= $settings['cookie_gtm_category'] === 'marketing' ? 'selected' : '' ?>>
                                Marketing
                            </option>
                        </select>

                        <div class="form-text">
                            Se o contêiner GTM possuir tags de marketing, use a categoria Marketing ou configure o próprio contêiner para respeitar consentimento.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary px-4">Salvar cookies e consentimento</button>
</form>

<?php require __DIR__ . '/../_footer.php'; ?>
