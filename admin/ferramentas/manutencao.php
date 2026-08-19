<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('manutencao.gerenciar');
$pdo = Database::connection();

$settings = maintenanceSettings($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $enabled = isset($_POST['maintenance_enabled']) ? '1' : '0';
            $title = trim((string)($_POST['maintenance_title'] ?? ''));
            $message = trim((string)($_POST['maintenance_message'] ?? ''));
            $expectedEnd = trim((string)($_POST['maintenance_expected_end'] ?? ''));
            $allowAdmins = isset($_POST['maintenance_allow_admins']) ? '1' : '0';
            $allowedIpsRaw = trim((string)($_POST['maintenance_allowed_ips'] ?? ''));

            if ($title === '') $title = 'Portal temporariamente em manutenção';
            if ($message === '') $message = 'Estamos realizando melhorias. Tente novamente em alguns instantes.';
            if ($expectedEnd !== '') {
                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $expectedEnd);
                if (!$dt) throw new RuntimeException('A previsão de retorno é inválida.');
                $expectedEnd = $dt->format('Y-m-d H:i:s');
            }

            $validIps = [];
            foreach (preg_split('/[\s,;]+/', $allowedIpsRaw) ?: [] as $ip) {
                $ip = trim($ip);
                if ($ip === '') continue;
                if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('IP inválido: ' . $ip);
                $validIps[] = $ip;
            }

            saveSiteConfig($pdo, 'maintenance_enabled', $enabled, 'booleano');
            saveSiteConfig($pdo, 'maintenance_title', mb_substr($title, 0, 180), 'texto');
            saveSiteConfig($pdo, 'maintenance_message', mb_substr($message, 0, 2000), 'texto');
            saveSiteConfig($pdo, 'maintenance_expected_end', $expectedEnd, 'texto');
            saveSiteConfig($pdo, 'maintenance_allow_admins', $allowAdmins, 'booleano');
            saveSiteConfig($pdo, 'maintenance_allowed_ips', implode("\n", array_unique($validIps)), 'texto');
            if ($enabled === '1') {
                saveSiteConfig($pdo, 'maintenance_enabled_at', date('Y-m-d H:i:s'), 'texto');
            } else {
                saveSiteConfig($pdo, 'maintenance_enabled_at', '', 'texto');
            }

            logAction($pdo, $enabled === '1' ? 'manutencao.ativar' : 'manutencao.desativar', 'configuracoes', null, $title, $enabled === '1' ? 'warning' : 'info');
            Session::flash('success', $enabled === '1' ? 'Modo manutenção ativado. O painel administrativo continua acessível.' : 'Modo manutenção desativado. O portal público voltou ao ar.');
            header('Location: ' . url('admin/ferramentas/manutencao.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$settings = maintenanceSettings($pdo);
$expectedLocal = '';
if ($settings['expected_end'] !== '') {
    try { $expectedLocal = (new DateTime($settings['expected_end']))->format('Y-m-d\TH:i'); } catch (Throwable $e) {}
}
$currentIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$pageTitle = 'Modo Manutenção';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h1 class="h3 mb-1">Modo Manutenção</h1><p class="text-secondary mb-0">Suspenda temporariamente o portal público sem bloquear o painel administrativo.</p></div>
    <a class="btn btn-outline-primary" href="<?= e(url()) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Testar portal</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($settings['enabled']): ?>
<div class="alert alert-warning d-flex align-items-start gap-2"><i class="bi bi-cone-striped mt-1"></i><div><strong>Modo manutenção ativo.</strong> Visitantes recebem HTTP 503 e não veem o conteúdo público. Administradores continuam acessando normalmente.</div></div>
<?php else: ?>
<div class="alert alert-success d-flex align-items-start gap-2"><i class="bi bi-check-circle-fill mt-1"></i><div><strong>Portal público disponível.</strong> O modo manutenção está desativado.</div></div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Configuração</div>
    <div class="card-body">
        <?= Csrf::field() ?>
        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" role="switch" id="maintenance_enabled" name="maintenance_enabled" <?= $settings['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="maintenance_enabled">Ativar modo manutenção</label>
        </div>
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Título</label><input class="form-control" name="maintenance_title" maxlength="180" value="<?= e($settings['title']) ?>"></div>
            <div class="col-12"><label class="form-label">Mensagem</label><textarea class="form-control" name="maintenance_message" rows="5" maxlength="2000"><?= e($settings['message']) ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Previsão de retorno <span class="text-secondary fw-normal">(opcional)</span></label><input class="form-control" type="datetime-local" name="maintenance_expected_end" value="<?= e($expectedLocal) ?>"></div>
            <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="maintenance_allow_admins" name="maintenance_allow_admins" <?= $settings['allow_admins'] ? 'checked' : '' ?>><label class="form-check-label" for="maintenance_allow_admins">Administradores logados podem visualizar o portal público</label></div></div>
            <div class="col-12"><label class="form-label">IPs liberados <span class="text-secondary fw-normal">(um por linha)</span></label><textarea class="form-control font-monospace" name="maintenance_allowed_ips" rows="4" placeholder="192.0.2.10"><?= e(implode("\n", $settings['allowed_ips'])) ?></textarea><div class="form-text">Seu IP atual: <code><?= e($currentIp ?: 'não identificado') ?></code></div></div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end"><button class="btn <?= $settings['enabled'] ? 'btn-warning' : 'btn-primary' ?> px-4"><i class="bi bi-floppy me-1"></i>Salvar</button></div>
</form>
<?php require __DIR__ . '/../_footer.php'; ?>
