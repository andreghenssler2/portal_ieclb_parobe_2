<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/SiteHealthService.php';
require_once __DIR__ . '/../../app/Services/ProductionDiagnosticsService.php';

Auth::requirePermission('saude.visualizar');

$pdo =
    Database::connection();

$pageTitle =
    'Central de Diagnóstico';

$diagnoseSmtp = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !Csrf::validate(
            $_POST['_token'] ?? null
        )
    ) {
        Session::flash(
            'error',
            'Token de segurança inválido.'
        );

        header(
            'Location: '
            . url(
                'admin/ferramentas/diagnostico.php'
            )
        );

        exit;
    }

    $action =
        (string)($_POST['action'] ?? 'refresh');

    $diagnoseSmtp =
        $action === 'smtp';

    if ($diagnoseSmtp) {
        logAction(
            $pdo,
            'diagnostico.smtp',
            'sistema',
            null,
            'Diagnóstico SMTP solicitado pela Central de Diagnóstico.'
        );
    }
}

$healthService =
    new SiteHealthService(
        $pdo,
        dirname(__DIR__, 2)
    );

$health =
    $healthService->run(
        $diagnoseSmtp
    );

$productionService =
    new ProductionDiagnosticsService(
        $pdo,
        dirname(__DIR__, 2)
    );

$production =
    $productionService->run();

$statusMeta = [
    'ok' => [
        'class' => 'success',
        'icon' => 'bi-check-circle-fill',
        'label' => 'OK',
    ],
    'warn' => [
        'class' => 'warning',
        'icon' => 'bi-exclamation-triangle-fill',
        'label' => 'Atenção',
    ],
    'error' => [
        'class' => 'danger',
        'icon' => 'bi-x-octagon-fill',
        'label' => 'Erro',
    ],
    'info' => [
        'class' => 'secondary',
        'icon' => 'bi-info-circle-fill',
        'label' => 'Info',
    ],
];

$healthSummary =
    $health['summary'];

$productionSummary =
    $production['summary'];

$overall =
    (
        ($healthSummary['overall'] ?? 'ok') === 'error'
        || ($productionSummary['overall'] ?? 'ok') === 'error'
    )
        ? 'error'
        : (
            ($healthSummary['overall'] ?? 'ok') === 'warn'
            || ($productionSummary['overall'] ?? 'ok') === 'warn'
                ? 'warn'
                : 'ok'
        );

$overallLabel = match ($overall) {
    'error' =>
        'Portal com problemas que exigem atenção',

    'warn' =>
        'Portal funcionando com recomendações',

    default =>
        'Portal saudável',
};

$overallMeta =
    $statusMeta[$overall]
    ?? $statusMeta['info'];

function diagnosticFormatBytes(
    int $bytes
): string {
    $bytes = max(0, $bytes);

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    $value =
        (float)$bytes;

    $unit = 0;

    while (
        $value >= 1024
        && $unit < count($units) - 1
    ) {
        $value /= 1024;
        $unit++;
    }

    return number_format(
        $value,
        $unit === 0 ? 0 : 1,
        ',',
        '.'
    ) . ' ' . $units[$unit];
}

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Ferramentas
        </div>

        <h1 class="h3 mb-1">
            Central de Diagnóstico
        </h1>

        <p class="text-secondary mb-0">
            Visão técnica e operacional do Portal para ambiente de produção.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <form method="post">
            <?= Csrf::field() ?>
            <input
                type="hidden"
                name="action"
                value="refresh"
            >

            <button class="btn btn-outline-secondary">
                <i class="bi bi-arrow-clockwise me-1"></i>
                Atualizar
            </button>
        </form>

        <?php if (
            class_exists('MailService')
            && MailService::transport($pdo) === 'smtp'
        ): ?>
            <form method="post">
                <?= Csrf::field() ?>
                <input
                    type="hidden"
                    name="action"
                    value="smtp"
                >

                <button class="btn btn-outline-primary">
                    <i class="bi bi-envelope-check me-1"></i>
                    Diagnosticar SMTP
                </button>
            </form>
        <?php endif; ?>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/ferramentas/saude.php')) ?>"
        >
            Saúde clássica
        </a>
    </div>
</div>

<div class="alert alert-<?= e($overallMeta['class']) ?> d-flex align-items-start gap-3 shadow-sm mb-4">
    <i class="bi <?= e($overallMeta['icon']) ?> fs-3"></i>

    <div>
        <div class="fw-semibold fs-5">
            <?= e($overallLabel) ?>
        </div>

        <div>
            Diagnóstico estrutural:
            <?= (int)$healthSummary['ok'] ?> OK ·
            <?= (int)$healthSummary['warn'] ?> atenção ·
            <?= (int)$healthSummary['error'] ?> erro(s)

            <br>

            Diagnóstico operacional:
            <?= (int)$productionSummary['ok'] ?> OK ·
            <?= (int)$productionSummary['warn'] ?> atenção ·
            <?= (int)$productionSummary['error'] ?> erro(s)
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($production['metrics'] as $metric): ?>
        <?php
        $meta =
            $statusMeta[
                (string)($metric['status'] ?? 'info')
            ]
            ?? $statusMeta['info'];
        ?>

        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                        <div
                            class="rounded-circle bg-<?= e($meta['class']) ?>-subtle text-<?= e($meta['class']) ?> d-inline-flex align-items-center justify-content-center"
                            style="width:42px;height:42px"
                        >
                            <i class="bi <?= e((string)$metric['icon']) ?>"></i>
                        </div>

                        <span class="badge text-bg-<?= e($meta['class']) ?>">
                            <?= e($meta['label']) ?>
                        </span>
                    </div>

                    <div class="fw-semibold mb-1">
                        <?= e((string)$metric['label']) ?>
                    </div>

                    <div class="small text-secondary">
                        <?= e((string)$metric['detail']) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">
                        Backups recentes
                    </div>

                    <div class="small text-secondary">
                        Banco e backups completos em storage/backups.
                    </div>
                </div>

                <?php if (Auth::can('backups.gerenciar')): ?>
                    <a
                        class="btn btn-sm btn-outline-secondary"
                        href="<?= e(url('admin/ferramentas/backups.php')) ?>"
                    >
                        Gerenciar
                    </a>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Arquivo</th>
                            <th>Tipo</th>
                            <th>Tamanho</th>
                            <th>Data</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$production['backups']): ?>
                            <tr>
                                <td
                                    colspan="4"
                                    class="text-secondary"
                                >
                                    Nenhum backup encontrado.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($production['backups'] as $backup): ?>
                            <tr>
                                <td class="small">
                                    <?= e((string)$backup['name']) ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-light border">
                                        <?= e((string)$backup['type']) ?>
                                    </span>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        diagnosticFormatBytes(
                                            (int)$backup['size']
                                        )
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        date(
                                            'd/m/Y H:i',
                                            (int)$backup['mtime']
                                        )
                                    ) ?>
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
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">
                        Tarefas agendadas
                    </div>

                    <div class="small text-secondary">
                        Situação atual do agendador do Portal.
                    </div>
                </div>

                <?php if (Auth::can('tarefas.gerenciar')): ?>
                    <a
                        class="btn btn-sm btn-outline-secondary"
                        href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
                    >
                        Gerenciar
                    </a>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tarefa</th>
                            <th>Status</th>
                            <th>Próxima execução</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$production['tasks']): ?>
                            <tr>
                                <td
                                    colspan="3"
                                    class="text-secondary"
                                >
                                    Nenhuma tarefa encontrada.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($production['tasks'] as $task): ?>
                            <?php
                            $active =
                                (int)($task['ativa'] ?? 0) === 1;

                            $lastStatus =
                                (string)($task['ultimo_status'] ?? '');

                            $taskClass =
                                !$active
                                    ? 'secondary'
                                    : (
                                        $lastStatus === 'erro'
                                            ? 'danger'
                                            : 'success'
                                    );
                            ?>

                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)(
                                                $task['nome']
                                                ?? $task['slug']
                                                ?? 'Tarefa'
                                            )
                                        ) ?>
                                    </div>

                                    <?php if (
                                        isset($task['intervalo_minutos'])
                                    ): ?>
                                        <small class="text-secondary">
                                            a cada
                                            <?= (int)$task['intervalo_minutos'] ?>
                                            min
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= e($taskClass) ?>">
                                        <?= $active ? e($lastStatus !== '' ? $lastStatus : 'ativa') : 'inativa' ?>
                                    </span>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        !empty($task['proxima_execucao_em'])
                                            ? formatDateBr(
                                                $task['proxima_execucao_em']
                                            )
                                            : '-'
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Maiores tabelas do banco
                </div>

                <div class="small text-secondary">
                    Dados + índices, segundo information_schema.
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tabela</th>
                            <th>Linhas</th>
                            <th>Tamanho</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($production['database_tables'] as $table): ?>
                            <tr>
                                <td>
                                    <code><?= e((string)$table['table_name']) ?></code>
                                </td>

                                <td>
                                    <?= number_format(
                                        (int)($table['table_rows'] ?? 0),
                                        0,
                                        ',',
                                        '.'
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        diagnosticFormatBytes(
                                            (int)($table['total_bytes'] ?? 0)
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$production['database_tables']): ?>
                            <tr>
                                <td
                                    colspan="3"
                                    class="text-secondary"
                                >
                                    Não foi possível obter os tamanhos das tabelas.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">
                        Alertas recentes
                    </div>

                    <div class="small text-secondary">
                        Últimos warnings e eventos críticos da auditoria.
                    </div>
                </div>

                <?php if (Auth::can('auditoria.visualizar')): ?>
                    <a
                        class="btn btn-sm btn-outline-secondary"
                        href="<?= e(url('admin/auditoria/index.php?nivel=warning')) ?>"
                    >
                        Auditoria
                    </a>
                <?php endif; ?>
            </div>

            <div class="list-group list-group-flush">
                <?php if (!$production['recent_errors']): ?>
                    <div class="list-group-item text-secondary">
                        Nenhum warning/critical encontrado.
                    </div>
                <?php endif; ?>

                <?php foreach ($production['recent_errors'] as $errorRow): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold">
                                    <span
                                        class="badge <?= (string)($errorRow['nivel'] ?? 'warning') === 'critical' ? 'text-bg-danger' : 'text-bg-warning' ?> me-1"
                                    >
                                        <?= e((string)($errorRow['nivel'] ?? 'warning')) ?>
                                    </span>

                                    <?= e((string)$errorRow['acao']) ?>
                                </div>

                                <?php if (!empty($errorRow['detalhes'])): ?>
                                    <div class="small text-secondary mt-1">
                                        <?= e(
                                            portalExcerpt(
                                                (string)$errorRow['detalhes'],
                                                130
                                            )
                                        ) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <small class="text-secondary text-nowrap">
                                <?= e(formatDateBr($errorRow['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<h2 class="h4 mb-3">
    Diagnóstico estrutural
</h2>

<p class="text-secondary">
    Abaixo estão os testes já existentes da Saúde do Portal:
    servidor, PHP, banco, arquivos, URLs, e-mail e segurança.
</p>

<?php foreach ($health['sections'] as $section): ?>
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h3 class="h5 mb-0">
                <?= e((string)$section['title']) ?>
            </h3>
        </div>

        <div class="list-group list-group-flush">
            <?php foreach ($section['checks'] as $check): ?>
                <?php
                $status =
                    (string)($check['status'] ?? 'info');

                $meta =
                    $statusMeta[$status]
                    ?? $statusMeta['info'];
                ?>

                <div class="list-group-item py-3">
                    <div class="d-flex align-items-start gap-3">
                        <i
                            class="bi <?= e($meta['icon']) ?> text-<?= e($meta['class']) ?> fs-5 mt-1"
                        ></i>

                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <strong>
                                    <?= e((string)$check['label']) ?>
                                </strong>

                                <span class="badge text-bg-<?= e($meta['class']) ?>">
                                    <?= e($meta['label']) ?>
                                </span>
                            </div>

                            <div>
                                <?= e((string)$check['detail']) ?>
                            </div>

                            <?php if (!empty($check['help'])): ?>
                                <div class="small text-secondary mt-1">
                                    <?= e((string)$check['help']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<div class="alert alert-light border small">
    <strong>Importante:</strong>
    esta Central é somente leitura. O diagnóstico SMTP testa a conexão,
    mas não envia mensagem. Backups, tarefas, cache e configurações
    continuam sendo gerenciados em seus módulos próprios.
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
