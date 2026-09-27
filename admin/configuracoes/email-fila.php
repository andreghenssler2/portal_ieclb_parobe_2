<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('email.gerenciar');

$pdo = Database::connection();

MailRetryQueueService::ensureSchema($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'process') {
                $result = MailRetryQueueService::processDue($pdo, 50);

                Session::flash(
                    'success',
                    'Fila processada: '
                    . (int)$result['processed']
                    . ' item(ns); '
                    . (int)$result['sent']
                    . ' enviado(s).'
                );

                logAction(
                    $pdo,
                    'email.fila.processar',
                    'email_fila_falhas',
                    null,
                    json_encode($result, JSON_UNESCAPED_UNICODE)
                );
            } elseif ($action === 'retry') {
                $id = max(0, (int)($_POST['id'] ?? 0));

                if (!MailRetryQueueService::retryNow($pdo, $id)) {
                    throw new RuntimeException('Não foi possível recolocar o item na fila.');
                }

                Session::flash('success', 'Item recolocado para tentativa imediata.');
            } elseif ($action === 'discard') {
                $id = max(0, (int)($_POST['id'] ?? 0));

                if (!MailRetryQueueService::discard($pdo, $id)) {
                    throw new RuntimeException('Não foi possível descartar o item.');
                }

                Session::flash('success', 'Item descartado.');
            } elseif ($action === 'settings') {
                $enabled = isset($_POST['mail_queue_enabled']) ? '1' : '0';
                $maxAttempts = max(1, min(20, (int)($_POST['mail_queue_max_attempts'] ?? 5)));

                saveSiteConfig($pdo, 'mail_queue_enabled', $enabled, 'booleano');
                saveSiteConfig($pdo, 'mail_queue_max_attempts', (string)$maxAttempts, 'numero');

                Session::flash('success', 'Configurações da fila atualizadas.');
            } else {
                throw new RuntimeException('Ação inválida.');
            }

            header('Location: ' . url('admin/configuracoes/email-fila.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$stats = MailRetryQueueService::stats($pdo);
$items = MailRetryQueueService::items($pdo, 150);
$enabled = siteConfig($pdo, 'mail_queue_enabled', '1') === '1';
$maxAttempts = max(1, min(20, (int)siteConfig($pdo, 'mail_queue_max_attempts', '5')));

$pageTitle = 'Fila de E-mails';
require __DIR__ . '/../_header.php';

$statusBadge = static fn(string $status): string => match ($status) {
    'pendente' => 'warning',
    'processando' => 'info',
    'enviado' => 'success',
    'falhou' => 'danger',
    default => 'secondary',
};
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">E-mail v1.1.13</div>
        <h1 class="h3 mb-1">Fila de E-mails</h1>
        <p class="text-secondary mb-0">Reenvio automático de mensagens que falharam por erro de transporte.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/email.php')) ?>">E-mail</a>
        <a class="btn btn-outline-primary" href="<?= e(url('admin/configuracoes/email-saude.php')) ?>">Saúde do E-mail</a>

        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="process">
            <button class="btn btn-primary">
                <i class="bi bi-arrow-repeat me-1"></i>
                Processar agora
            </button>
        </form>
    </div>
</div>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach (
        [
            'pendente' => 'Pendentes',
            'processando' => 'Processando',
            'falhou' => 'Falharam',
            'enviado' => 'Enviados',
        ]
        as $key => $label
    ): ?>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-secondary"><?= e($label) ?></div>
                <div class="display-6 fw-semibold"><?= (int)($stats[$key] ?? 0) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold py-3">Configuração da fila</div>
    <div class="card-body p-4">
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="settings">

            <div class="col-md-5">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="queueEnabled"
                        name="mail_queue_enabled"
                        <?= $enabled ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="queueEnabled">
                        Colocar falhas de transporte na fila automaticamente
                    </label>
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Máximo de tentativas</label>
                <input
                    class="form-control"
                    type="number"
                    min="1"
                    max="20"
                    name="mail_queue_max_attempts"
                    value="<?= (int)$maxAttempts ?>"
                >
            </div>

            <div class="col-md-4">
                <button class="btn btn-primary">Salvar fila</button>
            </div>
        </form>

        <div class="form-text mt-3">
            O reenvio usa espera progressiva: 5, 10, 20, 40 minutos e assim por diante, limitada a 6 horas.
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Destino</th>
                    <th>Assunto</th>
                    <th>Tentativas</th>
                    <th>Próxima</th>
                    <th>Erro</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-5">Fila vazia.</td></tr>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= (int)$item['id'] ?></td>
                        <td>
                            <span class="badge text-bg-<?= e($statusBadge((string)$item['status'])) ?>">
                                <?= e((string)$item['status']) ?>
                            </span>
                        </td>
                        <td><?= e((string)$item['destinatario']) ?></td>
                        <td><?= e(portalExcerpt((string)$item['assunto'], 90)) ?></td>
                        <td><?= (int)$item['tentativas'] ?>/<?= (int)$item['max_tentativas'] ?></td>
                        <td class="text-nowrap">
                            <?= !empty($item['proxima_tentativa_em'])
                                ? e(formatDateBr((string)$item['proxima_tentativa_em']))
                                : '—' ?>
                        </td>
                        <td><?= e(portalExcerpt((string)($item['ultimo_erro'] ?? ''), 140)) ?></td>
                        <td class="text-end">
                            <?php if (in_array((string)$item['status'], ['pendente','falhou'], true)): ?>
                                <div class="d-flex justify-content-end gap-2">
                                    <form method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="retry">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="btn btn-sm btn-outline-primary">Reenviar</button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Descartar este item da fila?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="discard">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Descartar</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
