<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/SiteHealthService.php';

Auth::requirePermission('saude.visualizar');
$pdo = Database::connection();
$pageTitle = 'Saúde do Portal';

$diagnoseSmtp = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido. Atualize a página e tente novamente.');
        header('Location: ' . url('admin/ferramentas/saude.php'));
        exit;
    }
    $action = (string)($_POST['action'] ?? 'refresh');
    $diagnoseSmtp = $action === 'smtp';
    if ($diagnoseSmtp) {
        logAction($pdo, 'saude.smtp.diagnosticar', 'sistema', null, 'Diagnóstico SMTP solicitado pela Saúde do Portal.');
    }
}

$service = new SiteHealthService($pdo, dirname(__DIR__, 2));
$report = $service->run($diagnoseSmtp);
$summary = $report['summary'];

$overall = (string)$summary['overall'];
$overallLabel = match ($overall) {
    'error' => 'Problemas críticos encontrados',
    'warn' => 'Portal funcionando com recomendações',
    default => 'Portal saudável',
};
$overallClass = match ($overall) {
    'error' => 'danger',
    'warn' => 'warning',
    default => 'success',
};
$overallIcon = match ($overall) {
    'error' => 'bi-x-octagon-fill',
    'warn' => 'bi-exclamation-triangle-fill',
    default => 'bi-check-circle-fill',
};

$statusMeta = [
    'ok' => ['class' => 'success', 'icon' => 'bi-check-circle-fill', 'label' => 'OK'],
    'warn' => ['class' => 'warning', 'icon' => 'bi-exclamation-triangle-fill', 'label' => 'Atenção'],
    'error' => ['class' => 'danger', 'icon' => 'bi-x-octagon-fill', 'label' => 'Erro'],
    'info' => ['class' => 'secondary', 'icon' => 'bi-info-circle-fill', 'label' => 'Info'],
];

include __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Saúde do Portal</h1>
        <p class="text-muted mb-0">Diagnóstico do PHP, banco de dados, arquivos, segurança, URLs e e-mail.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="refresh">
            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar diagnóstico</button>
        </form>
        <?php if (class_exists('MailService') && MailService::transport($pdo) === 'smtp'): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="smtp">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-envelope-check me-1"></i>Diagnosticar SMTP</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-<?= e($overallClass) ?> d-flex align-items-start gap-3 shadow-sm" role="alert">
    <i class="bi <?= e($overallIcon) ?> fs-3"></i>
    <div>
        <div class="fw-semibold fs-5"><?= e($overallLabel) ?></div>
        <div>
            <?= (int)$summary['ok'] ?> OK ·
            <?= (int)$summary['warn'] ?> atenção ·
            <?= (int)$summary['error'] ?> erro(s) ·
            <?= (int)$summary['info'] ?> informativo(s)
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Versão do Portal</div>
            <div class="fs-4 fw-semibold"><?= e(defined('APP_VERSION') ? (string)APP_VERSION : 'não definida') ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">PHP</div>
            <div class="fs-4 fw-semibold"><?= e(PHP_VERSION) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Ambiente</div>
            <div class="fs-4 fw-semibold"><?= e(defined('APP_ENV') ? (string)APP_ENV : 'development') ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Itens verificados</div>
            <div class="fs-4 fw-semibold"><?= (int)$summary['total'] ?></div>
        </div></div>
    </div>
</div>

<?php foreach ($report['sections'] as $section): ?>
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0"><?= e((string)$section['title']) ?></h2>
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($section['checks'] as $check):
                $status = (string)($check['status'] ?? 'info');
                $meta = $statusMeta[$status] ?? $statusMeta['info'];
            ?>
                <div class="list-group-item py-3">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi <?= e($meta['icon']) ?> text-<?= e($meta['class']) ?> fs-5 mt-1"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <strong><?= e((string)$check['label']) ?></strong>
                                <span class="badge text-bg-<?= e($meta['class']) ?>"><?= e($meta['label']) ?></span>
                            </div>
                            <div><?= e((string)$check['detail']) ?></div>
                            <?php if (!empty($check['help'])): ?>
                                <div class="small text-muted mt-1"><?= e((string)$check['help']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<div class="alert alert-light border small">
    <strong>Observação:</strong> o diagnóstico não altera configurações. O botão <em>Diagnosticar SMTP</em> testa DNS, conexão, TLS e autenticação, mas não envia uma mensagem.
</div>

<?php include __DIR__ . '/../_footer.php'; ?>
