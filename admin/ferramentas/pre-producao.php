<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo = Database::connection();
$root = dirname(__DIR__, 2);

if (!class_exists('ProductionReadinessService')) {
    throw new RuntimeException(
        'ProductionReadinessService indisponível.'
    );
}

$report =
    ProductionReadinessService::report(
        $pdo,
        $root
    );

$pageTitle = 'Pré-produção';

$stateClass = match ((string)$report['state']) {
    'ready' => 'success',
    'attention' => 'warning',
    default => 'danger',
};

$stateLabel = match ((string)$report['state']) {
    'ready' => 'Pronto',
    'attention' => 'Atenção',
    default => 'Bloqueado',
};

$statusClass = static fn(string $status): string => match ($status) {
    'ok' => 'success',
    'warning' => 'warning',
    default => 'danger',
};

$statusLabel = static fn(string $status): string => match ($status) {
    'ok' => 'OK',
    'warning' => 'Revisar',
    default => 'Erro',
};

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Pré-produção</h1>
        <p class="text-secondary mb-0">
            Checklist consolidado para preparar o Portal para a versão 1.0 e publicação definitiva.
        </p>
    </div>
</div>

<div class="alert alert-<?= e($stateClass) ?> d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <strong>Estado geral: <?= e($stateLabel) ?>.</strong>
        <?= (int)$report['passed'] ?> de <?= (int)$report['checks'] ?> verificações aprovadas.
    </div>
    <span class="badge text-bg-<?= e($stateClass) ?> fs-6">
        <?= (int)$report['score'] ?>%
    </span>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Verificações</div>
                <div class="display-6 fw-semibold"><?= (int)$report['checks'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Aprovadas</div>
                <div class="display-6 fw-semibold"><?= (int)$report['passed'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Avisos</div>
                <div class="display-6 fw-semibold"><?= count($report['warnings']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Bloqueadores</div>
                <div class="display-6 fw-semibold"><?= count($report['blockers']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($report['blockers']): ?>
    <div class="alert alert-danger">
        <strong>Bloqueadores para produção:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($report['blockers'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($report['warnings']): ?>
    <div class="alert alert-warning">
        <strong>Itens para revisar:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($report['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php foreach ($report['sections'] as $section => $items): ?>
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

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Antes da v1.0
    </div>
    <div class="card-body">
        <ul class="mb-0">
            <li>Resolver todos os itens marcados como bloqueadores.</li>
            <li>Revisar avisos de ambiente, cron, e-mail, desempenho e acessibilidade.</li>
            <li>Executar o backup e o teste de restaurabilidade.</li>
            <li>Validar o Portal público em desktop, celular e tablet.</li>
            <li>Testar formulário, newsletter, login, 2FA e envio de e-mail em produção.</li>
            <li>Confirmar HTTPS, domínio definitivo e APP_DEBUG desativado.</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
