<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'seguranca.gerenciar'
);

$pdo =
    Database::connection();

CspReportService::ensureSchema(
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
            $action =
                trim(
                    (string)(
                        $_POST['action']
                        ?? ''
                    )
                );

            if ($action === 'cleanup') {
                $removed =
                    CspReportService::cleanup(
                        $pdo
                    );

                logAction(
                    $pdo,
                    'seguranca.csp_relatorios.limpar_antigos',
                    'security_csp_reports',
                    null,
                    'Relatórios removidos: '
                    . $removed
                );

                Session::flash(
                    'success',
                    $removed
                    . ' relatório(s) antigo(s) removido(s).'
                );
            } elseif ($action === 'clear') {
                $removed =
                    CspReportService::clear(
                        $pdo
                    );

                logAction(
                    $pdo,
                    'seguranca.csp_relatorios.limpar_tudo',
                    'security_csp_reports',
                    null,
                    'Relatórios removidos: '
                    . $removed
                );

                Session::flash(
                    'success',
                    $removed
                    . ' relatório(s) CSP removido(s).'
                );
            }

            header(
                'Location: '
                . url(
                    'admin/configuracoes/csp-relatorios.php'
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

$summary =
    CspReportService::summary(
        $pdo
    );

$directives =
    CspReportService::topDirectives(
        $pdo,
        10
    );

$blocked =
    CspReportService::topBlocked(
        $pdo,
        10
    );

$recent =
    CspReportService::recent(
        $pdo,
        100
    );

$pageTitle =
    'Relatórios CSP';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Configurações · Segurança
        </div>

        <h1 class="h3 mb-1">
            Relatórios CSP
        </h1>

        <p class="text-secondary mb-0">
            Violações da Content Security Policy registradas pelos navegadores.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/configuracoes/cabecalhos-http.php')) ?>"
        >
            Cabeçalhos HTTP
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/configuracoes/seguranca.php')) ?>"
        >
            Segurança
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($settings['security_csp_report_enabled'] !== '1'): ?>
    <div class="alert alert-warning">
        A coleta de relatórios CSP está desativada em
        <a href="<?= e(url('admin/configuracoes/cabecalhos-http.php')) ?>">
            Cabeçalhos HTTP
        </a>.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Violações · 24h
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['last_24h'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Padrões únicos
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['unique_reports'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Ocorrências totais
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['total_occurrences'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Retenção
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$settings['security_csp_report_retention_days'] ?>
                </div>

                <div class="small text-secondary">
                    dias
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <strong>Como usar:</strong>
    mantenha a CSP em <strong>Report-Only</strong>, navegue pelas telas do Portal
    e acompanhe aqui quais diretivas seriam bloqueadas. Quando os relatórios
    estiverem limpos e as integrações esperadas estiverem liberadas, a política
    poderá ser promovida para <strong>Enforce</strong>.
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Diretivas com mais violações
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Diretiva</th>
                            <th class="text-end">Ocorrências</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$directives): ?>
                            <tr>
                                <td colspan="2" class="text-secondary py-4">
                                    Nenhum relatório recebido.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($directives as $item): ?>
                            <tr>
                                <td>
                                    <code>
                                        <?= e((string)$item['directive_name']) ?>
                                    </code>
                                </td>

                                <td class="text-end">
                                    <?= (int)$item['occurrence_count'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Recursos bloqueados mais frequentes
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Recurso</th>
                            <th class="text-end">Ocorrências</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$blocked): ?>
                            <tr>
                                <td colspan="2" class="text-secondary py-4">
                                    Nenhum relatório recebido.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($blocked as $item): ?>
                            <tr>
                                <td class="text-break">
                                    <code>
                                        <?= e((string)$item['blocked']) ?>
                                    </code>
                                </td>

                                <td class="text-end">
                                    <?= (int)$item['occurrence_count'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <div class="fw-semibold">
                Relatórios recentes
            </div>

            <div class="small text-secondary">
                URLs são armazenadas sem query string/fragmento e nenhum IP é salvo.
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <form method="post">
                <?= Csrf::field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="cleanup"
                >

                <button class="btn btn-sm btn-outline-secondary">
                    Limpar expirados
                </button>
            </form>

            <form
                method="post"
                onsubmit="return confirm('Apagar todo o histórico de relatórios CSP?');"
            >
                <?= Csrf::field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="clear"
                >

                <button class="btn btn-sm btn-outline-danger">
                    Limpar tudo
                </button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Última ocorrência</th>
                    <th>Diretiva</th>
                    <th>Bloqueado</th>
                    <th>Página</th>
                    <th>Origem</th>
                    <th class="text-end">Qtd.</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$recent): ?>
                    <tr>
                        <td colspan="6" class="text-secondary py-4">
                            Ainda não existem relatórios CSP.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recent as $item): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= e(formatDateBr((string)$item['last_seen_at'])) ?>
                        </td>

                        <td>
                            <code>
                                <?= e(
                                    (string)(
                                        $item['effective_directive']
                                        ?: $item['violated_directive']
                                        ?: '—'
                                    )
                                ) ?>
                            </code>
                        </td>

                        <td
                            class="text-break"
                            style="min-width:220px"
                        >
                            <code>
                                <?= e((string)($item['blocked_uri'] ?: '—')) ?>
                            </code>
                        </td>

                        <td
                            class="text-break small"
                            style="min-width:220px"
                        >
                            <?= e((string)($item['document_uri'] ?: '—')) ?>
                        </td>

                        <td
                            class="text-break small"
                            style="min-width:200px"
                        >
                            <?= e((string)($item['source_file'] ?: '—')) ?>

                            <?php if ((int)$item['line_number'] > 0): ?>
                                :
                                <?= (int)$item['line_number'] ?>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <?= (int)$item['occurrences'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
