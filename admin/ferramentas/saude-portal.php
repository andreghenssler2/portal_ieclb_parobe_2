<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo =
    Database::connection();

$root =
    dirname(
        __DIR__,
        2
    );

if (!class_exists('PortalHealthSnapshotService')) {
    throw new RuntimeException(
        'PortalHealthSnapshotService indisponível.'
    );
}

$success = '';
$error = '';

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {
    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $error =
            'Token de segurança inválido. Atualize a página e tente novamente.';
    } else {
        $action =
            (string)(
                $_POST['action']
                ?? ''
            );

        try {
            if ($action === 'snapshot') {
                $saved =
                    PortalHealthSnapshotService::save(
                        $pdo,
                        $root,
                        'admin'
                    );

                $success =
                    'Snapshot registrado com '
                    . (int)$saved['score']
                    . '% de saúde operacional.';
            } else {
                $error =
                    'Ação inválida.';
            }
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$current =
    PortalHealthSnapshotService::current(
        $pdo,
        $root
    );

$history =
    PortalHealthSnapshotService::history(
        $root,
        30
    );

$trend =
    PortalHealthSnapshotService::trend(
        $history
    );

$pageTitle =
    'Saúde do Portal';

$stateClass =
    match (
        (string)$current['state']
    ) {
        'ready' => 'success',
        'attention' => 'warning',
        default => 'danger',
    };

$stateLabel =
    match (
        (string)$current['state']
    ) {
        'ready' => 'Pronto',
        'attention' => 'Atenção',
        default => 'Bloqueado',
    };

$statusClass =
    static fn(string $status): string =>
        match ($status) {
            'ok' => 'success',
            'warning' => 'warning',
            default => 'danger',
        };

$statusLabel =
    static fn(string $status): string =>
        match ($status) {
            'ok' => 'OK',
            'warning' => 'Revisar',
            default => 'Erro',
        };

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Saúde do Portal</h1>
        <p class="text-secondary mb-0">
            Monitoramento operacional da série 1.x com histórico de snapshots.
        </p>
    </div>

    <form method="post" class="m-0">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="snapshot">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-camera me-1"></i>
            Registrar snapshot
        </button>
    </form>
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

<div class="alert alert-<?= e($stateClass) ?> d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <strong>Estado geral: <?= e($stateLabel) ?>.</strong>
        <?= (int)$current['passed'] ?> de <?= (int)$current['checks'] ?> verificações aprovadas.
    </div>

    <span class="badge text-bg-<?= e($stateClass) ?> fs-6">
        <?= (int)$current['score'] ?>%
    </span>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Pontuação atual</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$current['score'] ?>%
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Aprovadas</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$current['passed'] ?>/<?= (int)$current['checks'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Avisos</div>
                <div class="display-6 fw-semibold">
                    <?= count($current['warnings']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Tendência registrada</div>
                <div class="h4 fw-semibold mb-1">
                    <?= e((string)$trend['label']) ?>
                </div>
                <div class="small text-secondary">
                    Baseada nos dois snapshots mais recentes.
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($current['blockers']): ?>
    <div class="alert alert-danger">
        <strong>Bloqueadores:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($current['blockers'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($current['warnings']): ?>
    <div class="alert alert-warning">
        <strong>Itens para revisar:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($current['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Histórico</span>
        <span class="small text-secondary">
            Até 120 snapshots são mantidos automaticamente.
        </span>
    </div>

    <div class="card-body p-0">
        <?php if (!$history): ?>
            <div class="p-4 text-secondary">
                Nenhum snapshot registrado ainda.
            </div>
        <?php else: ?>
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
                        <?php foreach ($history as $item): ?>
                            <?php
                            $historyState =
                                (string)($item['state'] ?? 'attention');

                            $historyClass =
                                match ($historyState) {
                                    'ready' => 'success',
                                    'attention' => 'warning',
                                    default => 'danger',
                                };
                            ?>
                            <tr>
                                <td><?= e((string)($item['generated_at'] ?? '')) ?></td>
                                <td><?= e((string)($item['version'] ?? '')) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= e($historyClass) ?>">
                                        <?= e(strtoupper($historyState)) ?>
                                    </span>
                                </td>
                                <td><?= (int)($item['score'] ?? 0) ?>%</td>
                                <td><?= count((array)($item['warnings'] ?? [])) ?></td>
                                <td><?= count((array)($item['blockers'] ?? [])) ?></td>
                                <td><?= e((string)($item['source'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($current['sections'] as $section => $items): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <?= e((string)$section) ?>
        </div>

        <div class="list-group list-group-flush">
            <?php foreach ($items as $item): ?>
                <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-semibold">
                            <?= e((string)$item['label']) ?>
                        </div>
                        <div class="small text-secondary">
                            <?= e((string)$item['detail']) ?>
                        </div>
                    </div>

                    <span class="badge text-bg-<?= e($statusClass((string)$item['status'])) ?>">
                        <?= e($statusLabel((string)$item['status'])) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../_footer.php'; ?>
