<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('email.gerenciar');

$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $selector = strtolower(trim((string)($_POST['mail_dkim_selector'] ?? '')));
            if ($selector !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $selector)) {
                throw new RuntimeException('Seletor DKIM inválido.');
            }

            saveSiteConfig($pdo, 'mail_dkim_selector', $selector, 'texto');
            logAction($pdo, 'email.dns.configurar', 'configuracoes', null, 'DKIM selector=' . ($selector ?: 'não informado'));
            Session::flash('success', 'Configuração de autenticação de domínio atualizada.');
            header('Location: ' . url('admin/configuracoes/email-dns.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$report = MailDnsHealthService::report($pdo);
$pageTitle = 'Autenticação de E-mail';
require __DIR__ . '/../_header.php';

$badge = static fn(string $status): string => match ($status) {
    'ok' => 'success',
    'warning' => 'warning',
    'error' => 'danger',
    default => 'secondary',
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Autenticação de domínio</h1>
        <p class="text-secondary mb-0">Verificação DNS de SPF, DKIM, DMARC e MX do domínio usado como remetente.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/email.php')) ?>">Voltar para E-mail</a>
</div>

<?php if ($msg = Session::flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="alert alert-info">
    <strong>Somente leitura DNS.</strong> O Portal não altera registros no provedor de domínio. A criação/correção de SPF, DKIM e DMARC deve ser feita no DNS da hospedagem.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-secondary">Domínio do remetente</div>
        <div class="fw-semibold"><?= e($report['domain'] ?: 'Não configurado') ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-secondary">Pontuação técnica</div>
        <div class="display-6 fw-semibold"><?= (int)$report['score'] ?>/<?= (int)$report['max_score'] ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-secondary">Consulta DNS pelo PHP</div>
        <span class="badge text-bg-<?= $report['dns_available'] ? 'success' : 'danger' ?> mt-2"><?= $report['dns_available'] ? 'Disponível' : 'Indisponível' ?></span>
    </div></div></div>
</div>

<form method="post" class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">DKIM</div>
    <div class="card-body">
        <?= Csrf::field() ?>
        <label class="form-label">Seletor DKIM</label>
        <div class="input-group">
            <input class="form-control" name="mail_dkim_selector" value="<?= e(MailDnsHealthService::dkimSelector($pdo)) ?>" placeholder="default">
            <button class="btn btn-primary">Salvar e verificar</button>
        </div>
        <div class="form-text">Use exatamente o seletor informado pelo provedor de e-mail. Exemplos comuns: <code>default</code>, <code>selector1</code>, <code>google</code>.</div>
        <?php if (!empty($report['dkim']['host'])): ?>
            <div class="small mt-2">Host consultado: <code><?= e((string)$report['dkim']['host']) ?></code></div>
        <?php endif; ?>
    </div>
</form>

<div class="row g-3 mb-4">
<?php foreach (['spf'=>'SPF','dkim'=>'DKIM','dmarc'=>'DMARC','mx'=>'MX'] as $key=>$label): $item=$report[$key]; ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong><?= e($label) ?></strong>
                <span class="badge text-bg-<?= e($badge((string)$item['status'])) ?>"><?= e(strtoupper((string)$item['status'])) ?></span>
            </div>
            <div class="card-body">
                <p><?= e((string)$item['message']) ?></p>
                <?php if ($key === 'dmarc' && !empty($item['policy'])): ?><div class="mb-2">Política: <code>p=<?= e((string)$item['policy']) ?></code></div><?php endif; ?>
                <?php if (!empty($item['records'])): ?>
                    <details>
                        <summary>Ver registros encontrados</summary>
                        <pre class="bg-light border rounded p-3 mt-2 small" style="white-space:pre-wrap"><?php
                        foreach ((array)$item['records'] as $record) {
                            echo e(is_array($record) ? json_encode($record, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : (string)$record) . "\n";
                        }
                        ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($report['errors']): ?>
<div class="alert alert-danger"><strong>Erros:</strong><ul class="mb-0"><?php foreach ($report['errors'] as $m): ?><li><?= e((string)$m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($report['warnings']): ?>
<div class="alert alert-warning"><strong>Pontos para revisar:</strong><ul class="mb-0"><?php foreach ($report['warnings'] as $m): ?><li><?= e((string)$m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Próximos passos no DNS</div>
    <div class="card-body">
        <p class="mb-2">Use os valores fornecidos pelo seu provedor SMTP/hospedagem. Não publique dois registros SPF separados.</p>
        <p class="mb-2">DMARC começa normalmente com <code>v=DMARC1;</code>. Comece com política compatível com sua operação e endureça somente depois de validar os envios legítimos.</p>
        <p class="mb-0">DKIM depende do seletor e da chave pública fornecidos pelo provedor de e-mail.</p>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
