<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('tarefas.gerenciar');

$pdo = Database::connection();
SchedulerService::ensureRegistry($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
        header('Location: ' . url('admin/ferramentas/tarefas-agendadas.php'));
        exit;
    }

    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            SchedulerService::saveSettings(
                $pdo,
                (array)($_POST['ativas'] ?? []),
                (array)($_POST['intervalos'] ?? [])
            );
            logAction($pdo, 'tarefas.configuracoes.atualizar', 'tarefas_agendadas', null, 'Configurações das tarefas atualizadas');
            Session::flash('success', 'Configurações das tarefas agendadas salvas.');
        } elseif ($action === 'run_due') {
            $run = SchedulerService::runDue($pdo, 'manual');
            $errors = array_filter($run['results'], static fn(array $r): bool => ($r['status'] ?? '') === 'erro');
            Session::flash(
                $errors ? 'error' : 'success',
                $run['message'] . ($errors ? ' Algumas tarefas apresentaram erro; consulte o histórico.' : '')
            );
            logAction($pdo, 'tarefas.executar_vencidas', 'tarefas_agendadas', null, $run['message']);
        } elseif ($action === 'run_one') {
            $slug = (string)($_POST['slug'] ?? '');
            $result = SchedulerService::runOne($pdo, $slug, 'manual');
            $flash = ($result['status'] ?? '') === 'erro' ? 'error' : 'success';
            Session::flash($flash, $result['name'] . ': ' . $result['message']);
            logAction($pdo, 'tarefas.executar_manual', 'tarefas_agendadas', null, $slug . ' · ' . $result['status']);
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (Throwable $e) {
        Session::flash('error', $e->getMessage());
    }

    header('Location: ' . url('admin/ferramentas/tarefas-agendadas.php'));
    exit;
}

$tasks = SchedulerService::tasks($pdo);
$history = SchedulerService::history($pdo, 50);
$cronPath = str_replace('\\', '/', dirname(__DIR__, 2) . '/cron.php');
$cronCommand = 'php -q ' . $cronPath;
$pageTitle = 'Tarefas Agendadas';
require __DIR__ . '/../_header.php';

$statusClass = static function(?string $status): string {
    return match ($status) {
        'ok' => 'success',
        'erro' => 'danger',
        'ignorado' => 'secondary',
        'executando' => 'warning',
        default => 'light',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Tarefas Agendadas</h1>
        <p class="text-secondary mb-0">Cron central do Portal para publicação programada e rotinas automáticas.</p>
    </div>
    <form method="post">
        <?=Csrf::field()?>
        <input type="hidden" name="action" value="run_due">
        <button class="btn btn-primary"><i class="bi bi-play-fill me-1"></i>Executar tarefas vencidas agora</button>
    </form>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <div class="fw-semibold mb-2"><i class="bi bi-terminal me-1"></i>Configuração recomendada no cPanel / HostGator</div>
    <p class="mb-2">Cadastre um Cron Job para executar a cada 5 minutos:</p>
    <div class="input-group">
        <input class="form-control font-monospace" value="<?=e('*/5 * * * * ' . $cronCommand . ' >/dev/null 2>&1')?>" readonly>
        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value)">Copiar</button>
    </div>
    <div class="small mt-2">O arquivo <code>cron.php</code> aceita somente execução por linha de comando; acessos pelo navegador recebem 404.</div>
    <div class="mt-3">
        <a
            class="btn btn-sm btn-outline-primary"
            href="<?=e(url('admin/ferramentas/cron-saude.php'))?>"
        >
            <i class="bi bi-heart-pulse me-1"></i>
            Ver saúde do cron
        </a>
    </div>
</div>

<form method="post" class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 py-3">
        <strong>Rotinas</strong>
        <button class="btn btn-sm btn-outline-primary" name="action" value="save">Salvar configurações</button>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th style="width:80px">Ativa</th><th>Tarefa</th><th style="width:170px">Intervalo</th><th>Última execução</th><th>Próxima</th><th></th></tr></thead>
            <tbody>
            <?php foreach($tasks as $task):?>
                <tr>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="ativas[]" value="<?=e($task['slug'])?>" <?=!empty($task['ativa'])?'checked':''?> aria-label="Ativar <?=e($task['nome'])?>">
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold"><?=e($task['nome'])?></div>
                        <div class="small text-secondary"><?=e($task['descricao'])?></div>
                        <?php if($task['ultimo_status']):?><span class="badge text-bg-<?=$statusClass((string)$task['ultimo_status'])?> mt-2"><?=e($task['ultimo_status'])?></span><?php endif;?>
                        <?php if($task['ultima_mensagem']):?><span class="small text-secondary ms-2"><?=e(portalExcerpt((string)$task['ultima_mensagem'],180))?></span><?php endif;?>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <input class="form-control" type="number" min="1" max="10080" name="intervalos[<?=e($task['slug'])?>]" value="<?=(int)$task['intervalo_minutos']?>">
                            <span class="input-group-text">min</span>
                        </div>
                    </td>
                    <td>
                        <?php if($task['ultima_execucao_em']):?><?=e(formatDateBr((string)$task['ultima_execucao_em']))?><?php else:?><span class="text-secondary">Nunca</span><?php endif;?>
                        <?php if((int)$task['erros_consecutivos']>0):?><div class="small text-danger"><?=(int)$task['erros_consecutivos']?> erro(s) consecutivo(s)</div><?php endif;?>
                    </td>
                    <td><?php if($task['proxima_execucao_em']):?><?=e(formatDateBr((string)$task['proxima_execucao_em']))?><?php else:?><span class="text-secondary">—</span><?php endif;?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" name="action" value="run_one" formaction="<?=e(url('admin/ferramentas/tarefas-agendadas.php'))?>" formmethod="post" onclick="this.form.querySelector('[name=slug]').value='<?=e($task['slug'])?>'">Executar agora</button>
                    </td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <?=Csrf::field()?>
    <input type="hidden" name="slug" value="">
</form>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3"><strong>Histórico recente</strong></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Tarefa</th><th>Origem</th><th>Status</th><th>Início</th><th>Duração</th><th>Mensagem</th></tr></thead>
            <tbody>
            <?php if(!$history):?><tr><td colspan="6" class="text-secondary py-4">Nenhuma execução registrada ainda.</td></tr><?php endif;?>
            <?php foreach($history as $run):?>
                <tr>
                    <td class="fw-semibold"><?=e($run['tarefa_nome'] ?: $run['tarefa_slug'])?></td>
                    <td><span class="badge text-bg-light border"><?=e($run['origem'])?></span></td>
                    <td><span class="badge text-bg-<?=$statusClass((string)$run['status'])?>"><?=e($run['status'])?></span></td>
                    <td><?=e(formatDateBr((string)$run['iniciada_em']))?></td>
                    <td><?=number_format(((int)$run['duracao_ms'])/1000,2,',','.')?> s</td>
                    <td class="small"><?=e((string)($run['mensagem'] ?? ''))?></td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
