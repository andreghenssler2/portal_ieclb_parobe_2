<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('newsletter.gerenciar');
$pdo = Database::connection();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM newsletter_campanhas WHERE id=:id LIMIT 1');
$stmt->execute(['id' => $id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    http_response_code(404);
    exit('Campanha não encontrada.');
}
$active = (int) $pdo->query("SELECT COUNT(*) FROM newsletter_assinantes WHERE status='ativo'")->fetchColumn();
$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null))
        $error = 'Token de segurança inválido.';
    elseif ((string) ($_POST['confirmacao'] ?? '') !== 'ENVIAR')
        $error = 'Digite ENVIAR para confirmar.';
    else {
        try {
            $result = NewsletterService::sendCampaignBatch($pdo, $id, 30);
            logAction($pdo, 'newsletter.campanha.lote', 'newsletter_campanhas', $id, 'Enviados: ' . $result['sent'] . '; falhas: ' . $result['failed'] . '; restantes: ' . $result['remaining']);
            $stmt->execute(['id' => $id]);
            $campaign = $stmt->fetch();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$statsStmt = $pdo->prepare("SELECT status,COUNT(*) total FROM newsletter_envios WHERE campanha_id=:id GROUP BY status");
$statsStmt->execute(['id' => $id]);
$stats = ['enviado' => 0, 'falhou' => 0];
foreach ($statsStmt->fetchAll() as $s)
    $stats[$s['status']] = (int) $s['total'];
$pageTitle = 'Enviar newsletter';
require __DIR__ . '/../_header.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Enviar campanha</h1>
    <p class="text-secondary mb-0"><?= e($campaign['assunto']) ?></p>
</div>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><?php if ($result): ?>
    <div class="alert alert-info">Lote processado: <?= (int) $result['processed'] ?> · enviados: <?= (int) $result['sent'] ?>
        · falhas: <?= (int) $result['failed'] ?> · restantes: <?= (int) $result['remaining'] ?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5">Resumo</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Assinantes ativos</dt>
                    <dd class="col-sm-7"><?= $active ?></dd>
                    <dt class="col-sm-5">Enviados</dt>
                    <dd class="col-sm-7"><?= $stats['enviado'] ?></dd>
                    <dt class="col-sm-5">Falhas</dt>
                    <dd class="col-sm-7"><?= $stats['falhou'] ?></dd>
                    <dt class="col-sm-5">Status</dt>
                    <dd class="col-sm-7"><?= e($campaign['status']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4"><?php if ($campaign['status'] === 'enviado'): ?>
                    <div class="alert alert-success mb-0">Campanha finalizada em
                        <?= e(formatDateBr($campaign['enviado_em'])) ?>.</div><?php else: ?>
                    <p>O envio acontece em lotes de até <strong>30 mensagens</strong>. Repita até não haver mais
                        destinatários pendentes.</p>
                    <form method="post"><?= Csrf::field() ?><label class="form-label">Digite
                            <strong>ENVIAR</strong></label><input class="form-control mb-3" name="confirmacao"
                            autocomplete="off" required><button class="btn btn-primary w-100">Enviar próximo lote</button>
                    </form><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="mt-4"><a class="btn btn-outline-secondary" href="<?= e(url('admin/newsletter/campanhas.php')) ?>">Voltar às
        campanhas</a></div>
<?php require __DIR__ . '/../_footer.php'; ?>