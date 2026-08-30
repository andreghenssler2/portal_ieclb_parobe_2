<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'seguranca.gerenciar'
);

$pdo =
    Database::connection();

$defaults =
    SecurityHeadersService::defaults();

$settings =
    SecurityHeadersService::settings(
        $pdo
    );

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $settings['security_headers_enabled'] =
                isset(
                    $_POST['security_headers_enabled']
                )
                    ? '1'
                    : '0';

            $mode =
                strtolower(
                    trim(
                        (string)(
                            $_POST['security_csp_mode']
                            ?? 'report-only'
                        )
                    )
                );

            if (
                !in_array(
                    $mode,
                    [
                        'off',
                        'report-only',
                        'enforce',
                    ],
                    true
                )
            ) {
                $mode =
                    'report-only';
            }

            $settings['security_csp_mode'] =
                $mode;

            $settings['security_csp_report_enabled'] =
                isset(
                    $_POST['security_csp_report_enabled']
                )
                    ? '1'
                    : '0';

            $settings['security_csp_report_retention_days'] =
                (string)max(
                    1,
                    min(
                        365,
                        (int)(
                            $_POST['security_csp_report_retention_days']
                            ?? 30
                        )
                    )
                );

            $settings['security_hsts_enabled'] =
                isset(
                    $_POST['security_hsts_enabled']
                )
                    ? '1'
                    : '0';

            $settings['security_hsts_include_subdomains'] =
                isset(
                    $_POST['security_hsts_include_subdomains']
                )
                    ? '1'
                    : '0';

            $settings['security_permissions_policy_enabled'] =
                isset(
                    $_POST['security_permissions_policy_enabled']
                )
                    ? '1'
                    : '0';

            $settings['security_hsts_max_age'] =
                (string)max(
                    300,
                    min(
                        63072000,
                        (int)(
                            $_POST['security_hsts_max_age']
                            ?? 15552000
                        )
                    )
                );

            $frame =
                strtoupper(
                    trim(
                        (string)(
                            $_POST['security_frame_policy']
                            ?? 'SAMEORIGIN'
                        )
                    )
                );

            if (
                !in_array(
                    $frame,
                    [
                        'SAMEORIGIN',
                        'DENY',
                    ],
                    true
                )
            ) {
                $frame =
                    'SAMEORIGIN';
            }

            $settings['security_frame_policy'] =
                $frame;

            $referrer =
                strtolower(
                    trim(
                        (string)(
                            $_POST['security_referrer_policy']
                            ?? 'strict-origin-when-cross-origin'
                        )
                    )
                );

            if (
                !in_array(
                    $referrer,
                    [
                        'no-referrer',
                        'same-origin',
                        'strict-origin',
                        'strict-origin-when-cross-origin',
                    ],
                    true
                )
            ) {
                $referrer =
                    'strict-origin-when-cross-origin';
            }

            $settings['security_referrer_policy'] =
                $referrer;

            $coop =
                strtolower(
                    trim(
                        (string)(
                            $_POST['security_coop_policy']
                            ?? 'same-origin-allow-popups'
                        )
                    )
                );

            if (
                !in_array(
                    $coop,
                    [
                        'off',
                        'same-origin',
                        'same-origin-allow-popups',
                    ],
                    true
                )
            ) {
                $coop =
                    'same-origin-allow-popups';
            }

            $settings['security_coop_policy'] =
                $coop;

            $types = [
                'security_headers_enabled' =>
                    'booleano',

                'security_csp_mode' =>
                    'texto',

                'security_csp_report_enabled' =>
                    'booleano',

                'security_csp_report_retention_days' =>
                    'numero',

                'security_hsts_enabled' =>
                    'booleano',

                'security_hsts_max_age' =>
                    'numero',

                'security_hsts_include_subdomains' =>
                    'booleano',

                'security_permissions_policy_enabled' =>
                    'booleano',

                'security_frame_policy' =>
                    'texto',

                'security_referrer_policy' =>
                    'texto',

                'security_coop_policy' =>
                    'texto',
            ];

            foreach (
                $types
                as $key => $type
            ) {
                saveSiteConfig(
                    $pdo,
                    $key,
                    $settings[$key],
                    $type
                );
            }

            logAction(
                $pdo,
                'seguranca.headers.atualizar',
                'configuracoes',
                null,
                'Cabeçalhos HTTP de segurança atualizados.'
            );

            Session::flash(
                'success',
                'Cabeçalhos HTTP de segurança atualizados.'
            );

            header(
                'Location: '
                . url(
                    'admin/configuracoes/cabecalhos-http.php'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$settings =
    SecurityHeadersService::settings(
        $pdo
    );

$preview =
    SecurityHeadersService::preview(
        $pdo
    );

$https =
    SecurityHeadersService::isHttps();

$activeCount =
    count(
        array_filter(
            $preview,
            static fn(array $item): bool =>
                !empty($item['active'])
        )
    );

$pageTitle =
    'Cabeçalhos HTTP de Segurança';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Configurações · Segurança
        </div>

        <h1 class="h3 mb-1">
            Cabeçalhos HTTP
        </h1>

        <p class="text-secondary mb-0">
            Proteções do navegador contra clickjacking, MIME sniffing,
            vazamento de referência e carregamento de origens não autorizadas.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/configuracoes/csp-relatorios.php')) ?>"
        >
            <i class="bi bi-activity me-1"></i>
            Relatórios CSP
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/configuracoes/seguranca.php')) ?>"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Segurança
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Cabeçalhos ativos
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$activeCount ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    CSP
                </div>

                <div class="h4 mt-2 mb-0">
                    <?php if ($settings['security_csp_mode'] === 'enforce'): ?>
                        <span class="badge text-bg-success">
                            Enforce
                        </span>
                    <?php elseif ($settings['security_csp_mode'] === 'report-only'): ?>
                        <span class="badge text-bg-info">
                            Report-Only
                        </span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">
                            Desativada
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    HTTPS detectado
                </div>

                <div class="h4 mt-2 mb-0">
                    <span class="badge <?= $https ? 'text-bg-success' : 'text-bg-warning' ?>">
                        <?= $https ? 'Sim' : 'Não' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    HSTS
                </div>

                <div class="h4 mt-2 mb-0">
                    <span class="badge <?= $settings['security_hsts_enabled'] === '1' && $https ? 'text-bg-success' : 'text-bg-secondary' ?>">
                        <?= $settings['security_hsts_enabled'] === '1'
                            ? ($https ? 'Ativo' : 'Aguardando HTTPS')
                            : 'Desativado' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-3">
    <i class="bi bi-shield-check fs-4"></i>

    <div>
        <strong>CSP começa em Report-Only.</strong>
        Nesse modo o navegador registra violações no console, mas não bloqueia
        scripts, estilos ou integrações. Use essa etapa para homologar o Portal
        antes de trocar para <strong>Enforce</strong>.
    </div>
</div>

<form
    method="post"
    class="card border-0 shadow-sm mb-4"
>
    <div class="card-body p-4">
        <?= Csrf::field() ?>

        <h2 class="h5 mb-3">
            Política do navegador
        </h2>

        <div class="row g-4">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_headers_enabled"
                        id="securityHeadersEnabled"
                        <?= $settings['security_headers_enabled'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="securityHeadersEnabled"
                    >
                        Ativar cabeçalhos HTTP de segurança
                    </label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Content Security Policy
                </label>

                <select
                    class="form-select"
                    name="security_csp_mode"
                >
                    <option
                        value="off"
                        <?= $settings['security_csp_mode'] === 'off' ? 'selected' : '' ?>
                    >
                        Desativada
                    </option>

                    <option
                        value="report-only"
                        <?= $settings['security_csp_mode'] === 'report-only' ? 'selected' : '' ?>
                    >
                        Report-Only (recomendado inicialmente)
                    </option>

                    <option
                        value="enforce"
                        <?= $settings['security_csp_mode'] === 'enforce' ? 'selected' : '' ?>
                    >
                        Enforce (bloquear violações)
                    </option>
                </select>

                <div class="form-text">
                    O modo Enforce deve ser ativado apenas depois de testar
                    Home, Admin, formulários, Analytics e conteúdos incorporados.
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-check form-switch mb-3">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_csp_report_enabled"
                        id="cspReportEnabled"
                        <?= $settings['security_csp_report_enabled'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="cspReportEnabled"
                    >
                        Coletar violações CSP no Portal
                    </label>
                </div>

                <label class="form-label">
                    Reter relatórios por
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="1"
                        max="365"
                        name="security_csp_report_retention_days"
                        value="<?= e($settings['security_csp_report_retention_days']) ?>"
                    >

                    <span class="input-group-text">
                        dias
                    </span>
                </div>

                <div class="form-text">
                    Não são armazenados IP, query strings nem conteúdo de scripts inline.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Proteção contra frames
                </label>

                <select
                    class="form-select"
                    name="security_frame_policy"
                >
                    <option
                        value="SAMEORIGIN"
                        <?= $settings['security_frame_policy'] === 'SAMEORIGIN' ? 'selected' : '' ?>
                    >
                        SAMEORIGIN
                    </option>

                    <option
                        value="DENY"
                        <?= $settings['security_frame_policy'] === 'DENY' ? 'selected' : '' ?>
                    >
                        DENY
                    </option>
                </select>

                <div class="form-text">
                    SAMEORIGIN permite frame apenas pelo próprio domínio.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Referrer Policy
                </label>

                <select
                    class="form-select"
                    name="security_referrer_policy"
                >
                    <?php foreach (
                        [
                            'strict-origin-when-cross-origin' =>
                                'strict-origin-when-cross-origin',
                            'strict-origin' =>
                                'strict-origin',
                            'same-origin' =>
                                'same-origin',
                            'no-referrer' =>
                                'no-referrer',
                        ]
                        as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $settings['security_referrer_policy'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Cross-Origin Opener Policy
                </label>

                <select
                    class="form-select"
                    name="security_coop_policy"
                >
                    <option
                        value="off"
                        <?= $settings['security_coop_policy'] === 'off' ? 'selected' : '' ?>
                    >
                        Desativada
                    </option>

                    <option
                        value="same-origin-allow-popups"
                        <?= $settings['security_coop_policy'] === 'same-origin-allow-popups' ? 'selected' : '' ?>
                    >
                        same-origin-allow-popups
                    </option>

                    <option
                        value="same-origin"
                        <?= $settings['security_coop_policy'] === 'same-origin' ? 'selected' : '' ?>
                    >
                        same-origin
                    </option>
                </select>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_permissions_policy_enabled"
                        id="permissionsPolicy"
                        <?= $settings['security_permissions_policy_enabled'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="permissionsPolicy"
                    >
                        Aplicar Permissions-Policy restritiva
                    </label>
                </div>

                <div class="form-text">
                    Desativa câmera, microfone, geolocalização, pagamento,
                    USB e sensores que o Portal não utiliza.
                </div>
            </div>
        </div>

        <hr class="my-4">

        <h2 class="h5 mb-3">
            HSTS
        </h2>

        <div class="alert alert-warning">
            <strong>Ative HSTS somente em domínio com HTTPS definitivo.</strong>
            Navegadores que receberem esse cabeçalho passarão a exigir HTTPS
            pelo período configurado.
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_hsts_enabled"
                        id="hstsEnabled"
                        <?= $settings['security_hsts_enabled'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="hstsEnabled"
                    >
                        Ativar Strict-Transport-Security
                    </label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    HSTS max-age
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="300"
                        max="63072000"
                        name="security_hsts_max_age"
                        value="<?= e($settings['security_hsts_max_age']) ?>"
                    >

                    <span class="input-group-text">
                        segundos
                    </span>
                </div>

                <div class="form-text">
                    Padrão: 15.552.000 segundos (180 dias).
                </div>
            </div>

            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_hsts_include_subdomains"
                        id="hstsSubdomains"
                        <?= $settings['security_hsts_include_subdomains'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="hstsSubdomains"
                    >
                        Aplicar também aos subdomínios
                    </label>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button class="btn btn-primary px-4">
                Salvar cabeçalhos HTTP
            </button>
        </div>
    </div>
</form>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="fw-semibold">
            Prévia da resposta HTTP
        </div>

        <div class="small text-secondary">
            Cabeçalhos que o Portal pretende enviar com a configuração atual.
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Cabeçalho</th>
                    <th>Valor</th>
                    <th>Objetivo</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($preview as $item): ?>
                    <tr>
                        <td>
                            <span class="badge <?= !empty($item['active']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= !empty($item['active']) ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>

                        <td class="fw-semibold text-nowrap">
                            <?= e((string)$item['header']) ?>
                        </td>

                        <td style="min-width:320px">
                            <code class="text-break">
                                <?= e((string)$item['value']) ?>
                            </code>
                        </td>

                        <td class="small text-secondary">
                            <?= e((string)$item['note']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
