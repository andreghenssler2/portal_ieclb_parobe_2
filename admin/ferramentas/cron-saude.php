<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('tarefas.gerenciar');

$pdo = Database::connection();
$root = dirname(__DIR__, 2);

if (!class_exists('CronHealthService')) {
    throw new RuntimeException(
        'CronHealthService indisponível.'
    );
}

$status = CronHealthService::status($pdo, $root);

$overallClass = match ((string)$status['overall']) {
    'healthy' => 'success',
    'attention' => 'info',
    'warning' => 'warning',
    default => 'danger',
};

$overallLabel = match ((string)$status['overall']) {
    'healthy' => 'Saudável',
    'attention' => 'Atenção',
    'warning' => 'Revisar',
    default => 'Erro estrutural',
};

$heartbeatClass = match ((string)$status['heartbeat']['state']) {
    'healthy' => 'success',
    'warning' => 'warning',
    'stale' => 'danger',
    default => 'secondary',
};

$heartbeatData =
    is_array($status['heartbeat']['data'])
        ? $status['heartbeat']['data']
        : [];

$lastCron =
    is_array($status['history']['last_cron'])
        ? $status['history']['last_cron']
        : null;

$cronPath =
    str_replace(
        '\\',
        '/',
        $root . '/cron.php'
    );

$cronCommand =
    'php -q '
    . $cronPath;

$pageTitle = 'Saúde do Cron';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Saúde do Cron</h1>
        <p class="text-secondary mb-0">
            Diagnóstico do heartbeat, filas, erros e execução das tarefas automáticas do Portal.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
    >
        <i class="bi bi-arrow-left me-1"></i>
        Voltar para Tarefas
    </a>
</div>

<div class="alert alert-<?= e($overallClass) ?> d-flex align-items-start gap-2">
    <i class="bi bi-heart-pulse-fill fs-5 mt-1"></i>
    <div>
        <strong>Estado geral: <?= e($overallLabel) ?>.</strong>

        <?php if ((string)$status['heartbeat']['state'] === 'never'): ?>
            O heartbeat ainda não foi registrado por uma execução normal de <code>cron.php</code>.
        <?php else: ?>
            Último heartbeat:
            <strong><?= e((string)($heartbeatData['seen_at_local'] ?? '—')) ?></strong>
            —
            <?= e(CronHealthService::formatAge(
                is_int($status['heartbeat']['age_seconds'])
                    ? $status['heartbeat']['age_seconds']
                    : null
            )) ?>
            atrás.
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Heartbeat</div>
                <div class="mt-2">
                    <span class="badge text-bg-<?= e($heartbeatClass) ?>">
                        <?= e((string)$status['heartbeat']['label']) ?>
                    </span>
                </div>
                <div class="small text-secondary mt-2">
                    Esperado: execução a cada 5 minutos.
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Tarefas ativas</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$status['tasks']['active'] ?>
                </div>
                <div class="small text-secondary">
                    de <?= (int)$status['tasks']['total'] ?> registradas
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Tarefas vencidas</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$status['tasks']['overdue'] ?>
                </div>
                <div class="small text-secondary">
                    aguardando processamento
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Erros nas últimas 24h</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$status['history']['recent_errors_24h'] ?>
                </div>
                <div class="small text-secondary">
                    execuções com status erro
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($status['issues'])): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">
            Problemas estruturais
        </div>
        <ul class="mb-0">
            <?php foreach ((array)$status['issues'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($status['warnings'])): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-2">
            Pontos para revisar
        </div>
        <ul class="mb-0">
            <?php foreach ((array)$status['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Situação operacional
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-secondary">Tarefas com erros consecutivos</div>
                        <strong><?= (int)$status['tasks']['consecutive_errors'] ?></strong>
                    </div>

                    <div class="col-md-6">
                        <div class="small text-secondary">Ativas que nunca rodaram</div>
                        <strong><?= (int)$status['tasks']['never_run_active'] ?></strong>
                    </div>

                    <div class="col-md-6">
                        <div class="small text-secondary">Execuções órfãs &gt; 60 min</div>
                        <strong><?= (int)$status['history']['stale_running'] ?></strong>
                    </div>

                    <div class="col-md-6">
                        <div class="small text-secondary">Próxima execução programada</div>
                        <strong>
                            <?= !empty($status['tasks']['next_run'])
                                ? e(formatDateBr((string)$status['tasks']['next_run']))
                                : '—' ?>
                        </strong>
                    </div>

                    <div class="col-12">
                        <div class="small text-secondary">Última execução originada pelo cron</div>

                        <?php if ($lastCron): ?>
                            <div class="fw-semibold">
                                <?= e((string)($lastCron['tarefa_slug'] ?? '')) ?>
                            </div>
                            <div class="small text-secondary">
                                <?= e(formatDateBr((string)($lastCron['iniciada_em'] ?? ''))) ?>
                                ·
                                <?= e((string)($lastCron['status'] ?? '')) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-secondary">Nenhuma registrada.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Arquivos e escrita
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span>cron.php</span>
                    <span class="badge <?= !empty($status['filesystem']['cron_exists']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= !empty($status['filesystem']['cron_exists']) ? 'Encontrado' : 'Ausente' ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                    <span>Heartbeat gravável</span>
                    <span class="badge <?= !empty($status['filesystem']['heartbeat_writable']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= !empty($status['filesystem']['heartbeat_writable']) ? 'Sim' : 'Não' ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between gap-3 py-2">
                    <span>Arquivo de lock já criado</span>
                    <span class="badge <?= !empty($status['filesystem']['lock_exists']) ? 'text-bg-light border' : 'text-bg-secondary' ?>">
                        <?= !empty($status['filesystem']['lock_exists']) ? 'Sim' : 'Ainda não' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Configuração recomendada
            </div>

            <div class="card-body">
                <p class="small text-secondary">
                    Cadastre o comando abaixo no painel da hospedagem para rodar a cada 5 minutos:
                </p>

                <textarea
                    class="form-control font-monospace"
                    rows="3"
                    readonly
                ><?= e('*/5 * * * * ' . $cronCommand . ' >/dev/null 2>&1') ?></textarea>

                <div class="small text-secondary mt-3">
                    O heartbeat só é atualizado por <code>cron.php</code> sem <code>--task</code> ou <code>--all</code>.
                    Isso ajuda a diferenciar o Cron Job real de testes manuais.
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Diagnóstico pelo terminal
            </div>

            <div class="card-body">
                <div class="mb-3">
                    <div class="small text-secondary">Somente saúde, sem executar tarefas</div>
                    <code>php cron.php --health</code>
                </div>

                <div>
                    <div class="small text-secondary">Teste estrutural adicional</div>
                    <code>php tests/scheduler.php</code>
                </div>
            </div>
        </div>

        <form
            method="post"
            action="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
            class="card border-0 shadow-sm"
        >
            <div class="card-body">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="run_due">

                <div class="fw-semibold mb-2">
                    Processar fila agora
                </div>

                <p class="small text-secondary">
                    Executa somente tarefas que já estão vencidas.
                </p>

                <button class="btn btn-primary">
                    <i class="bi bi-play-fill me-1"></i>
                    Executar tarefas vencidas
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
