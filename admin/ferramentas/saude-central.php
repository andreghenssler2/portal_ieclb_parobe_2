<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/SiteHealthService.php';
require_once __DIR__ . '/../../app/Services/ProductionDiagnosticsService.php';

Auth::requirePermission('saude.visualizar');

$pdo = Database::connection();
$root = dirname(__DIR__, 2);

$pageTitle = 'Saúde do Portal';
$success = '';
$error = '';
$diagnoseSmtp = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido. Atualize a página e tente novamente.';
    } else {
        $action = (string)($_POST['action'] ?? 'refresh');

        try {
            if ($action === 'smtp') {
                $diagnoseSmtp = true;

                logAction(
                    $pdo,
                    'saude.smtp.diagnosticar',
                    'sistema',
                    null,
                    'Diagnóstico SMTP solicitado pela Saúde do Portal unificada.'
                );
            } elseif ($action === 'snapshot') {
                if (!Auth::can('configuracoes.gerenciar') && !Auth::isAdmin()) {
                    throw new RuntimeException(
                        'Seu perfil não possui permissão para registrar snapshots.'
                    );
                }

                if (!class_exists('PortalHealthSnapshotService')) {
                    throw new RuntimeException(
                        'PortalHealthSnapshotService indisponível.'
                    );
                }

                $saved = PortalHealthSnapshotService::save(
                    $pdo,
                    $root,
                    'admin-central'
                );

                $success =
                    'Snapshot registrado com '
                    . (int)($saved['score'] ?? 0)
                    . '% de saúde operacional.';
            } elseif ($action !== 'refresh') {
                throw new RuntimeException('Ação inválida.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$healthService = new SiteHealthService(
    $pdo,
    $root
);

$health = $healthService->run(
    $diagnoseSmtp
);

$productionService = new ProductionDiagnosticsService(
    $pdo,
    $root
);

$production = $productionService->run();

$snapshot = null;
$snapshotHistory = [];
$snapshotTrend = [
    'label' => 'Sem histórico',
];

if (class_exists('PortalHealthSnapshotService')) {
    try {
        $snapshot = PortalHealthSnapshotService::current(
            $pdo,
            $root
        );

        $snapshotHistory = PortalHealthSnapshotService::history(
            $root,
            12
        );

        $snapshotTrend = PortalHealthSnapshotService::trend(
            $snapshotHistory
        );
    } catch (Throwable $ignored) {
        $snapshot = null;
        $snapshotHistory = [];
    }
}

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
    'warning' => [
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

$healthSummary = (array)($health['summary'] ?? []);
$productionSummary = (array)($production['summary'] ?? []);

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
    'error' => 'Portal com problemas que exigem atenção',
    'warn' => 'Portal funcionando com recomendações',
    default => 'Portal saudável',
};

$overallMeta =
    $statusMeta[$overall]
    ?? $statusMeta['info'];

function portalHealthFormatBytes(int $bytes): string
{
    $bytes = max(0, $bytes);

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    $value = (float)$bytes;
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

<?php /* PORTAL_HEALTH_UNIFIED_V120_R5 */ ?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Ferramentas
        </div>

        <h1 class="h3 mb-1">
            Saúde do Portal
        </h1>

        <p class="text-secondary mb-0">
            Diagnóstico técnico, operação, produção e histórico de saúde em uma única central.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="refresh">

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
                <input type="hidden" name="action" value="smtp">

                <button class="btn btn-outline-primary">
                    <i class="bi bi-envelope-check me-1"></i>
                    Diagnosticar SMTP
                </button>
            </form>
        <?php endif; ?>

        <?php if (
            class_exists('PortalHealthSnapshotService')
            && (
                Auth::can('configuracoes.gerenciar')
                || Auth::isAdmin()
            )
        ): ?>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="snapshot">

                <button class="btn btn-primary">
                    <i class="bi bi-camera me-1"></i>
                    Registrar snapshot
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success">
        <?= e($success) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="alert alert-<?= e($overallMeta['class']) ?> d-flex align-items-start gap-3 shadow-sm mb-4">
    <i class="bi <?= e($overallMeta['icon']) ?> fs-3"></i>

    <div>
        <div class="fw-semibold fs-5">
            <?= e($overallLabel) ?>
        </div>

        <div>
            Diagnóstico técnico:
            <?= (int)($healthSummary['ok'] ?? 0) ?> OK ·
            <?= (int)($healthSummary['warn'] ?? 0) ?> atenção ·
            <?= (int)($healthSummary['error'] ?? 0) ?> erro(s)

            <br>

            Diagnóstico operacional:
            <?= (int)($productionSummary['ok'] ?? 0) ?> OK ·
            <?= (int)($productionSummary['warn'] ?? 0) ?> atenção ·
            <?= (int)($productionSummary['error'] ?? 0) ?> erro(s)
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Versão do Portal</div>
                <div class="h4 mb-0 fw-semibold">
                    <?= e(defined('APP_VERSION') ? (string)APP_VERSION : '-') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">PHP</div>
                <div class="h4 mb-0 fw-semibold">
                    <?= e(PHP_VERSION) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Snapshot</div>

                <?php if (is_array($snapshot)): ?>
                    <div class="h4 mb-0 fw-semibold">
                        <?= (int)($snapshot['score'] ?? 0) ?>%
                    </div>
                <?php else: ?>
                    <div class="h4 mb-0 fw-semibold">—</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Tendência</div>
                <div class="h4 mb-0 fw-semibold">
                    <?= e((string)($snapshotTrend['label'] ?? 'Sem histórico')) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item">
        <a class="nav-link active" href="#diagnostico">
            Diagnóstico
        </a>
    </li>

    <li class="nav-item">
        <a class="nav-link" href="#operacao">
            Operação
        </a>
    </li>

    <li class="nav-item">
        <a class="nav-link" href="#historico">
            Histórico
        </a>
    </li>
</ul>

<div id="diagnostico" class="mb-4">
    <?php foreach ((array)($health['sections'] ?? []) as $section): ?>
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h5 mb-0">
                    <?= e((string)($section['title'] ?? 'Diagnóstico')) ?>
                </h2>
            </div>

            <div class="list-group list-group-flush">
                <?php foreach ((array)($section['checks'] ?? []) as $check): ?>
                    <?php
                    $status =
                        (string)($check['status'] ?? 'info');

                    $meta =
                        $statusMeta[$status]
                        ?? $statusMeta['info'];
                    ?>

                    <div class="list-group-item py-3">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi <?= e($meta['icon']) ?> text-<?= e($meta['class']) ?> fs-5 mt-1"></i>

                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <strong>
                                        <?= e((string)($check['label'] ?? 'Verificação')) ?>
                                    </strong>

                                    <span class="badge text-bg-<?= e($meta['class']) ?>">
                                        <?= e($meta['label']) ?>
                                    </span>
                                </div>

                                <div>
                                    <?= e((string)($check['detail'] ?? '')) ?>
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
</div>

<div id="operacao" class="mb-4">
    <h2 class="h4 mb-3">
        Operação
    </h2>

    <div class="row g-3 mb-4">
        <?php foreach ((array)($production['metrics'] ?? []) as $metric): ?>
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
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <i class="bi <?= e((string)($metric['icon'] ?? 'bi-info-circle')) ?> fs-4"></i>

                            <span class="badge text-bg-<?= e($meta['class']) ?>">
                                <?= e($meta['label']) ?>
                            </span>
                        </div>

                        <div class="fw-semibold">
                            <?= e((string)($metric['label'] ?? 'Métrica')) ?>
                        </div>

                        <div class="small text-secondary">
                            <?= e((string)($metric['detail'] ?? '')) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-3 py-3">
                    <div>
                        <div class="fw-semibold">
                            Backups recentes
                        </div>

                        <div class="small text-secondary">
                            Banco e backups completos.
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
                            <?php if (empty($production['backups'])): ?>
                                <tr>
                                    <td colspan="4" class="text-secondary">
                                        Nenhum backup encontrado.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ((array)($production['backups'] ?? []) as $backup): ?>
                                <tr>
                                    <td class="small">
                                        <?= e((string)($backup['name'] ?? '')) ?>
                                    </td>

                                    <td>
                                        <?= e((string)($backup['type'] ?? '')) ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= e(portalHealthFormatBytes((int)($backup['size'] ?? 0))) ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= !empty($backup['mtime'])
                                            ? e(date('d/m/Y H:i', (int)$backup['mtime']))
                                            : '—' ?>
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
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-3 py-3">
                    <div>
                        <div class="fw-semibold">
                            Tarefas agendadas
                        </div>

                        <div class="small text-secondary">
                            Situação atual do agendador.
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
                                <th>Próxima</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($production['tasks'])): ?>
                                <tr>
                                    <td colspan="3" class="text-secondary">
                                        Nenhuma tarefa encontrada.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ((array)($production['tasks'] ?? []) as $task): ?>
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
                                        <?= e(
                                            (string)(
                                                $task['nome']
                                                ?? $task['slug']
                                                ?? 'Tarefa'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= e($taskClass) ?>">
                                            <?= $active
                                                ? e($lastStatus !== '' ? $lastStatus : 'ativa')
                                                : 'inativa' ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= !empty($task['proxima_execucao_em'])
                                            ? e(formatDateBr((string)$task['proxima_execucao_em']))
                                            : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="fw-semibold">
                Maiores tabelas do banco
            </div>

            <div class="small text-secondary">
                Dados + índices conforme information_schema.
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
                    <?php if (empty($production['database_tables'])): ?>
                        <tr>
                            <td colspan="3" class="text-secondary">
                                Não foi possível obter os tamanhos das tabelas.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ((array)($production['database_tables'] ?? []) as $table): ?>
                        <tr>
                            <td>
                                <code>
                                    <?= e((string)($table['table_name'] ?? '')) ?>
                                </code>
                            </td>

                            <td>
                                <?= number_format(
                                    (int)($table['table_rows'] ?? 0),
                                    0,
                                    ',',
                                    '.'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    portalHealthFormatBytes(
                                        (int)($table['total_bytes'] ?? 0)
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

<div id="historico" class="mb-4">
    <h2 class="h4 mb-3">
        Histórico de saúde
    </h2>

    <?php if (is_array($snapshot)): ?>
        <?php
        $snapshotState =
            (string)($snapshot['state'] ?? 'attention');

        $snapshotClass =
            match ($snapshotState) {
                'ready' => 'success',
                'attention' => 'warning',
                default => 'danger',
            };
        ?>

        <div class="alert alert-<?= e($snapshotClass) ?>">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <strong>
                        Snapshot atual:
                        <?= (int)($snapshot['score'] ?? 0) ?>%
                    </strong>

                    <div>
                        <?= (int)($snapshot['passed'] ?? 0) ?>
                        de
                        <?= (int)($snapshot['checks'] ?? 0) ?>
                        verificações aprovadas.
                    </div>
                </div>

                <div class="text-end">
                    <div class="small">Tendência</div>
                    <strong>
                        <?= e((string)($snapshotTrend['label'] ?? 'Sem histórico')) ?>
                    </strong>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="fw-semibold">
                Snapshots recentes
            </div>

            <div class="small text-secondary">
                Histórico operacional do Portal.
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Versão</th>
                        <th>Estado</th>
                        <th>Pontuação</th>
                        <th>Avisos</th>
                        <th>Bloqueadores</th>
                        <th>Origem</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$snapshotHistory): ?>
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">
                                Nenhum snapshot registrado ainda.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($snapshotHistory as $item): ?>
                        <?php
                        $state =
                            (string)($item['state'] ?? 'attention');

                        $stateClass =
                            match ($state) {
                                'ready' => 'success',
                                'attention' => 'warning',
                                default => 'danger',
                            };
                        ?>

                        <tr>
                            <td>
                                <?= e((string)($item['generated_at'] ?? '')) ?>
                            </td>

                            <td>
                                <?= e((string)($item['version'] ?? '')) ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e($stateClass) ?>">
                                    <?= e(strtoupper($state)) ?>
                                </span>
                            </td>

                            <td>
                                <?= (int)($item['score'] ?? 0) ?>%
                            </td>

                            <td>
                                <?= count((array)($item['warnings'] ?? [])) ?>
                            </td>

                            <td>
                                <?= count((array)($item['blockers'] ?? [])) ?>
                            </td>

                            <td>
                                <?= e((string)($item['source'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <?php if (Auth::can('performance.gerenciar')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/desempenho.php')) ?>">
            Desempenho
        </a>
    <?php endif; ?>

    <?php if (Auth::can('backups.gerenciar')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/backups.php')) ?>">
            Backups
        </a>
    <?php endif; ?>

    <?php if (Auth::can('tarefas.gerenciar')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>">
            Tarefas agendadas
        </a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
