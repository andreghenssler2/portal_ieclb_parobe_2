<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('email.gerenciar');

$pdo = Database::connection();

MailRetryQueueService::ensureSchema($pdo);

$error = '';
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action !== 'advanced_test') {
                throw new RuntimeException('Ação inválida.');
            }

            $test = MailOperationsService::advancedTest(
                $pdo,
                (string)($_POST['test_email'] ?? '')
            );

            logAction(
                $pdo,
                'email.v1113.teste_avancado',
                'email',
                null,
                'Destino: ' . (string)($test['recipient'] ?? '')
                    . '; resultado='
                    . (!empty($test['ok']) ? 'ok' : 'falha'),
                !empty($test['ok']) ? 'info' : 'warning'
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$health = MailOperationsService::health($pdo);
$dns = (array)$health['dns'];
$queue = (array)$health['queue'];
$dnsActions = MailOperationsService::dnsActions($dns);

$badge = static fn(string $status): string => match ($status) {
    'ok' => 'success',
    'warning', 'attention' => 'warning',
    'error' => 'danger',
    default => 'secondary',
};

$pageTitle = 'Saúde do E-mail';
require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            E-mail v1.1.13
        </div>

        <h1 class="h3 mb-1">Saúde do E-mail</h1>

        <p class="text-secondary mb-0">
            Revisão do SMTP, teste de envio, falhas, SPF, DKIM, DMARC e fila de reenvio.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/email.php')) ?>">
            Configurações SMTP
        </a>

        <a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/email-dns.php')) ?>">
            DNS
        </a>

        <a class="btn btn-primary" href="<?= e(url('admin/configuracoes/email-fila.php')) ?>">
            <i class="bi bi-inboxes me-1"></i>
            Fila de falhas
        </a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($health['issue'] !== null): ?>
    <div class="alert alert-danger">
        <strong>Configuração incompleta:</strong>
        <?= e((string)$health['issue']) ?>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        Configuração básica de envio validada para
        <strong><?= e((string)$health['transport']) ?></strong>.
    </div>
<?php endif; ?>

<?php foreach ((array)$health['warnings'] as $warning): ?>
    <div class="alert alert-warning py-2">
        <?= e((string)$warning) ?>
    </div>
<?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">Enviados · 24h</div>
            <div class="display-6 fw-semibold"><?= (int)$health['sent_24h'] ?></div>
        </div></div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">Falhas · 24h</div>
            <div class="display-6 fw-semibold"><?= (int)$health['failed_24h'] ?></div>
        </div></div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">Falhas · 7 dias</div>
            <div class="display-6 fw-semibold"><?= (int)$health['failed_7d'] ?></div>
        </div></div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">Fila pendente</div>
            <div class="display-6 fw-semibold"><?= (int)($queue['pendente'] ?? 0) ?></div>
        </div></div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">Fila falhou</div>
            <div class="display-6 fw-semibold"><?= (int)($queue['falhou'] ?? 0) ?></div>
        </div></div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-secondary">DNS técnico</div>
            <div class="display-6 fw-semibold">
                <?= (int)($dns['score'] ?? 0) ?>/<?= (int)($dns['max_score'] ?? 4) ?>
            </div>
        </div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold py-3">
                Teste avançado de envio
            </div>

            <div class="card-body p-4">
                <p class="text-secondary">
                    Faz a revisão da configuração, diagnóstico SMTP, consulta DNS e envio real com código único.
                    O teste não entra na fila automática se falhar.
                </p>

                <form method="post" class="row g-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="advanced_test">

                    <div class="col-12">
                        <label class="form-label">Destinatário do teste</label>
                        <input
                            class="form-control"
                            type="email"
                            name="test_email"
                            required
                            placeholder="seu-email@dominio.com"
                        >
                    </div>

                    <div class="col-12">
                        <button class="btn btn-primary">
                            <i class="bi bi-send-check me-1"></i>
                            Executar teste completo
                        </button>
                    </div>
                </form>

                <?php if (is_array($test)): ?>
                    <hr>

                    <div class="alert <?= !empty($test['ok']) ? 'alert-success' : 'alert-danger' ?> mb-0">
                        <strong>
                            <?= !empty($test['ok']) ? 'Teste aprovado.' : 'Teste com falha.' ?>
                        </strong>

                        Código:
                        <code><?= e((string)$test['token']) ?></code>
                        ·
                        <?= (int)$test['duration_ms'] ?> ms

                        <?php if (!empty($test['configuration_issue'])): ?>
                            <div class="mt-2">
                                <?= e((string)$test['configuration_issue']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($test['send_error'])): ?>
                            <div class="mt-2">
                                <?= e((string)$test['send_error']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold py-3">
                Autenticação do domínio
            </div>

            <div class="card-body p-4">
                <div class="row g-3 mb-4">
                    <?php foreach (['spf'=>'SPF','dkim'=>'DKIM','dmarc'=>'DMARC','mx'=>'MX'] as $key=>$label): ?>
                        <?php $item = (array)($dns[$key] ?? []); ?>
                        <div class="col-6">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= e($label) ?></strong>
                                    <span class="badge text-bg-<?= e($badge((string)($item['status'] ?? ''))) ?>">
                                        <?= e(strtoupper((string)($item['status'] ?? 'N/A'))) ?>
                                    </span>
                                </div>

                                <div class="small text-secondary mt-2">
                                    <?= e((string)($item['message'] ?? '')) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h3 class="h6">Ações recomendadas</h3>

                <ul class="mb-0">
                    <?php foreach ($dnsActions as $action): ?>
                        <li class="mb-2"><?= e($action) ?></li>
                    <?php endforeach; ?>
                </ul>

                <div class="alert alert-info mt-3 mb-0">
                    O Portal valida os registros, mas não altera o DNS da hospedagem.
                    A correção deve ser publicada no provedor do domínio/SMTP.
                </div>
            </div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-body d-flex justify-content-between align-items-center gap-3 py-3">
        <div>
            <div class="fw-semibold">Histórico de falhas</div>
            <div class="small text-secondary">Até 30 falhas mais recentes registradas pelo MailService.</div>
        </div>

        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/configuracoes/email-fila.php')) ?>">
            Abrir fila
        </a>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Destino</th>
                    <th>Assunto</th>
                    <th>Transporte</th>
                    <th>Erro</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($health['recent_failures'])): ?>
                    <tr><td colspan="5" class="text-center text-secondary py-4">Nenhuma falha registrada.</td></tr>
                <?php endif; ?>

                <?php foreach ((array)$health['recent_failures'] as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(formatDateBr((string)$row['created_at'])) ?></td>
                        <td><?= e((string)$row['destinatario']) ?></td>
                        <td><?= e((string)$row['assunto']) ?></td>
                        <td><?= e((string)$row['transport']) ?></td>
                        <td><?= e(portalExcerpt((string)($row['erro'] ?? ''), 180)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
