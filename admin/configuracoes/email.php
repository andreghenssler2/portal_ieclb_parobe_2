<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('email.gerenciar');
$pdo = Database::connection();

$defaults = [
    'mail_transport' => 'smtp',
    'mail_from_name' => siteConfig($pdo, 'site_nome', defined('APP_NAME') ? APP_NAME : 'Portal IECLB Parobé'),
    'mail_from_email' => siteConfig($pdo, 'site_email', ''),
    'mail_reply_to' => siteConfig($pdo, 'site_email', ''),
    'mail_smtp_host' => '',
    'mail_smtp_port' => '587',
    'mail_smtp_encryption' => 'tls',
    'mail_smtp_auth' => '1',
    'mail_smtp_username' => '',
    'mail_smtp_verify_peer' => '1',
    'mail_timeout_seconds' => '15',
    'mail_log_retention_days' => '90',
];
$settings = array_merge($defaults, siteConfigAll($pdo));
$error = '';
$testSuccess = '';
$diagnostic = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');
            $transport = strtolower(trim((string)($_POST['mail_transport'] ?? 'smtp')));
            if (!in_array($transport, ['mail', 'smtp'], true)) {
                throw new RuntimeException('Transporte de e-mail inválido.');
            }

            $fromName = trim((string)($_POST['mail_from_name'] ?? ''));
            $fromEmail = strtolower(trim((string)($_POST['mail_from_email'] ?? '')));
            $replyTo = strtolower(trim((string)($_POST['mail_reply_to'] ?? '')));
            if ($fromName === '') {
                throw new RuntimeException('Informe o nome do remetente.');
            }
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail remetente válido.');
            }
            if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail de resposta válido.');
            }

            $host = trim((string)($_POST['mail_smtp_host'] ?? ''));
            $port = max(1, min(65535, (int)($_POST['mail_smtp_port'] ?? 587)));
            $encryption = strtolower(trim((string)($_POST['mail_smtp_encryption'] ?? 'tls')));
            if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
                throw new RuntimeException('Tipo de criptografia SMTP inválido.');
            }
            $auth = isset($_POST['mail_smtp_auth']) ? '1' : '0';
            $username = trim((string)($_POST['mail_smtp_username'] ?? ''));
            $verifyPeer = isset($_POST['mail_smtp_verify_peer']) ? '1' : '0';
            $timeout = max(3, min(60, (int)($_POST['mail_timeout_seconds'] ?? 15)));
            $retention = max(7, min(3650, (int)($_POST['mail_log_retention_days'] ?? 90)));

            if ($transport === 'smtp') {
                if ($host === '') {
                    throw new RuntimeException('Informe o servidor SMTP.');
                }
                if ($auth === '1' && $username === '') {
                    throw new RuntimeException('Informe o usuário SMTP ou desative a autenticação.');
                }
            }

            $save = [
                'mail_transport' => [$transport, 'texto'],
                'mail_from_name' => [$fromName, 'texto'],
                'mail_from_email' => [$fromEmail, 'texto'],
                'mail_reply_to' => [$replyTo, 'texto'],
                'mail_smtp_host' => [$host, 'texto'],
                'mail_smtp_port' => [(string)$port, 'numero'],
                'mail_smtp_encryption' => [$encryption, 'texto'],
                'mail_smtp_auth' => [$auth, 'booleano'],
                'mail_smtp_username' => [$username, 'texto'],
                'mail_smtp_verify_peer' => [$verifyPeer, 'booleano'],
                'mail_timeout_seconds' => [(string)$timeout, 'numero'],
                'mail_log_retention_days' => [(string)$retention, 'numero'],
            ];
            foreach ($save as $key => [$value, $type]) {
                saveSiteConfig($pdo, $key, $value, $type);
            }

            if (isset($_POST['clear_smtp_password'])) {
                MailService::setSmtpPassword($pdo, '');
            } else {
                $newPassword = (string)($_POST['mail_smtp_password'] ?? '');
                if ($newPassword !== '') {
                    MailService::setSmtpPassword($pdo, $newPassword);
                }
            }

            $settings = array_merge($defaults, siteConfigAll($pdo, true));
            $issue = MailService::configurationIssue($pdo);
            if ($transport === 'smtp' && $issue !== null) {
                throw new RuntimeException($issue);
            }

            logAction($pdo, 'email.configuracoes.atualizar', 'configuracoes', null, 'Transporte: ' . $transport);

            if ($action === 'test') {
                $testEmail = strtolower(trim((string)($_POST['test_email'] ?? '')));
                if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Informe um e-mail válido para o teste.');
                }
                $html = '<h2>Teste de e-mail concluído</h2>'
                    . '<p>Esta mensagem foi enviada pelo <strong>' . e(siteConfig($pdo, 'site_nome', APP_NAME)) . '</strong>.</p>'
                    . '<p>Transporte: <strong>' . e(MailService::transportLabel($pdo)) . '</strong><br>Data: ' . e(date('d/m/Y H:i:s')) . '</p>';
                if (!MailService::sendHtml($pdo, $testEmail, 'Teste de e-mail - ' . siteConfig($pdo, 'site_nome', APP_NAME), $html)) {
                    throw new RuntimeException(MailService::lastError() ?: 'Não foi possível enviar o e-mail de teste.');
                }
                $testSuccess = 'E-mail de teste enviado para ' . $testEmail . ' usando ' . MailService::transportLabel($pdo) . '.';
                logAction($pdo, 'email.teste.enviado', 'email', null, 'Destino: ' . $testEmail);
            } elseif ($action === 'diagnostic') {
                if ($transport !== 'smtp') {
                    throw new RuntimeException('O diagnóstico de conexão é exclusivo para SMTP. Selecione SMTP e salve a configuração.');
                }
                $diagnostic = MailService::diagnoseSmtp($pdo);
                logAction($pdo, 'email.smtp.diagnostico', 'email', null, $diagnostic['ok'] ? 'Conexão OK' : 'Falha de conexão');
            } else {
                Session::flash('success', 'Configurações de e-mail atualizadas.');
                header('Location: ' . url('admin/configuracoes/email.php'));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$settings = array_merge($defaults, siteConfigAll($pdo, true));
$configIssue = MailService::configurationIssue($pdo);
$configWarnings = MailService::configurationWarnings($pdo);
$hasPassword = MailService::hasSmtpPassword($pdo);
$recent = [];
try {
    MailService::cleanupLogs($pdo);
    $recent = $pdo->query('SELECT * FROM email_envios ORDER BY id DESC LIMIT 15')->fetchAll();
} catch (Throwable $ignored) {
}

$pageTitle = 'Configurações de E-mail';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">E-mail</h1>
        <p class="text-secondary mb-0">Envio centralizado com PHPMailer <?= e(MailService::libraryVersion()) ?> · gerenciado pelo Composer em <code>/lib</code>.</p>
    </div>
    <span class="badge text-bg-<?= $configIssue === null ? 'success' : 'warning' ?> fs-6"><?= e(MailService::transportLabel($pdo)) ?></span>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($testSuccess): ?><div class="alert alert-success"><?= e($testSuccess) ?></div><?php endif; ?>
<?php if ($configIssue !== null): ?><div class="alert alert-warning"><strong>Atenção:</strong> <?= e($configIssue) ?></div><?php endif; ?>
<?php foreach ($configWarnings as $warning): ?>
    <div class="alert alert-warning py-2"><strong>Configuração SMTP:</strong> <?= e($warning) ?></div>
<?php endforeach; ?>

<?php if ($diagnostic !== null): ?>
<div class="card border-<?= $diagnostic['ok'] ? 'success' : 'danger' ?> shadow-sm mb-4">
    <div class="card-header bg-<?= $diagnostic['ok'] ? 'success-subtle' : 'danger-subtle' ?> fw-semibold">
        Diagnóstico SMTP — <?= $diagnostic['ok'] ? 'concluído com sucesso' : 'falhou' ?>
    </div>
    <div class="card-body">
        <p class="mb-2"><strong><?= e($diagnostic['summary']) ?></strong></p>
        <div class="small text-secondary mb-3">
            Servidor: <code><?= e($diagnostic['host']) ?></code> · Porta: <code><?= (int)$diagnostic['port'] ?></code> · Criptografia: <code><?= e(strtoupper($diagnostic['encryption'])) ?></code>
            <?php if (!empty($diagnostic['ips'])): ?> · IP: <code><?= e(implode(', ', $diagnostic['ips'])) ?></code><?php endif; ?>
        </div>
        <?php if (!empty($diagnostic['debug'])): ?>
            <details>
                <summary class="fw-semibold" style="cursor:pointer">Ver diagnóstico técnico</summary>
                <pre class="bg-dark text-light rounded p-3 mt-3 small" style="white-space:pre-wrap;max-height:360px;overflow:auto"><?= e(implode("\n", $diagnostic['debug'])) ?></pre>
            </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm mb-4" autocomplete="off">
    <div class="card-body p-4">
        <?= Csrf::field() ?>
        <div class="row g-4">
            <div class="col-md-4">
                <label class="form-label">Método de envio</label>
                <select class="form-select" name="mail_transport" id="mailTransport">
                    <option value="smtp" <?= $settings['mail_transport'] === 'smtp' ? 'selected' : '' ?>>SMTP via PHPMailer (recomendado)</option>
                    <option value="mail" <?= $settings['mail_transport'] === 'mail' ? 'selected' : '' ?>>PHP mail() via PHPMailer</option>
                </select>
                <div class="form-text">SMTP é recomendado. PHP mail() ainda depende de um serviço local de e-mail configurado no servidor.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Nome do remetente</label>
                <input class="form-control" name="mail_from_name" maxlength="150" value="<?= e($settings['mail_from_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">E-mail do remetente</label>
                <input class="form-control" type="email" name="mail_from_email" maxlength="190" value="<?= e($settings['mail_from_email']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Responder para</label>
                <input class="form-control" type="email" name="mail_reply_to" maxlength="190" value="<?= e($settings['mail_reply_to']) ?>" placeholder="contato@dominio.org.br">
            </div>
            <div class="col-md-3">
                <label class="form-label">Timeout</label>
                <div class="input-group"><input class="form-control" type="number" min="3" max="60" name="mail_timeout_seconds" value="<?= e($settings['mail_timeout_seconds']) ?>"><span class="input-group-text">s</span></div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Reter histórico</label>
                <div class="input-group"><input class="form-control" type="number" min="7" max="3650" name="mail_log_retention_days" value="<?= e($settings['mail_log_retention_days']) ?>"><span class="input-group-text">dias</span></div>
            </div>
        </div>

        <div class="smtp-settings border-top mt-4 pt-4" id="smtpSettings">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">Servidor SMTP</h2>
                <div class="btn-group btn-group-sm" role="group" aria-label="Configurações comuns de SMTP">
                    <button class="btn btn-outline-secondary" type="button" id="preset587">587 / STARTTLS</button>
                    <button class="btn btn-outline-secondary" type="button" id="preset465">465 / SSL-TLS</button>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label">Servidor</label>
                    <input class="form-control" name="mail_smtp_host" value="<?= e($settings['mail_smtp_host']) ?>" placeholder="mail.seudominio.org.br">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Porta</label>
                    <input class="form-control" id="smtpPort" type="number" min="1" max="65535" name="mail_smtp_port" value="<?= e($settings['mail_smtp_port']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Criptografia</label>
                    <select class="form-select" id="smtpEncryption" name="mail_smtp_encryption">
                        <option value="tls" <?= $settings['mail_smtp_encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (normalmente porta 587)</option>
                        <option value="ssl" <?= $settings['mail_smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS direto (normalmente porta 465)</option>
                        <option value="none" <?= $settings['mail_smtp_encryption'] === 'none' ? 'selected' : '' ?>>Sem criptografia</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Usuário SMTP</label>
                    <input class="form-control" name="mail_smtp_username" value="<?= e($settings['mail_smtp_username']) ?>" autocomplete="username">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Senha SMTP</label>
                    <input class="form-control" type="password" name="mail_smtp_password" value="" autocomplete="new-password" placeholder="<?= $hasPassword ? 'Senha já configurada — deixe vazio para manter' : 'Informe a senha SMTP' ?>">
                    <?php if ($hasPassword): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="clear_smtp_password" id="clearPass"><label class="form-check-label" for="clearPass">Remover senha SMTP armazenada</label></div><?php endif; ?>
                    <div class="form-text">A senha é criptografada antes de ser gravada e não volta a ser exibida no painel.</div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="mail_smtp_auth" id="smtpAuth" <?= $settings['mail_smtp_auth'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="smtpAuth">Usar autenticação SMTP</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="mail_smtp_verify_peer" id="verifyPeer" <?= $settings['mail_smtp_verify_peer'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="verifyPeer">Verificar certificado TLS</label>
                    </div>
                    <div class="form-text">Mantenha ativado em produção. Desative apenas temporariamente para diagnóstico.</div>
                </div>
            </div>
        </div>

        <div class="border-top mt-4 pt-4">
            <h2 class="h5">Teste e diagnóstico</h2>
            <p class="text-secondary small">“Diagnosticar SMTP” testa DNS, conexão, TLS e autenticação sem enviar e-mail.</p>
            <div class="row g-3 align-items-end">
                <div class="col-md-6"><label class="form-label">Enviar teste para</label><input class="form-control" type="email" name="test_email" placeholder="seuemail@dominio.com"></div>
                <div class="col-md-6 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" name="action" value="save">Salvar configurações</button>
                    <button class="btn btn-outline-primary" name="action" value="test">Salvar e enviar teste</button>
                    <button class="btn btn-outline-secondary" name="action" value="diagnostic">Diagnosticar SMTP</button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Últimos envios</span><span class="text-secondary small">até 15 registros</span></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Data</th><th>Destino</th><th>Assunto</th><th>Transporte</th><th>Status</th><th>Detalhe</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?><tr><td colspan="6" class="text-secondary text-center py-4">Nenhum envio registrado.</td></tr><?php endif; ?>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td class="text-nowrap"><?= e(formatDateBr((string)$row['created_at'])) ?></td>
                    <td><?= e((string)$row['destinatario']) ?></td>
                    <td><?= e((string)$row['assunto']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e(strtoupper((string)$row['transport'])) ?></span></td>
                    <td><span class="badge text-bg-<?= $row['status'] === 'enviado' ? 'success' : 'danger' ?>"><?= e((string)$row['status']) ?></span></td>
                    <td class="small text-danger"><?= e((string)($row['erro'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(() => {
    const transport = document.getElementById('mailTransport');
    const smtp = document.getElementById('smtpSettings');
    const port = document.getElementById('smtpPort');
    const encryption = document.getElementById('smtpEncryption');
    const toggle = () => smtp.classList.toggle('d-none', transport.value !== 'smtp');
    transport.addEventListener('change', toggle); toggle();

    document.getElementById('preset587')?.addEventListener('click', () => {
        port.value = '587';
        encryption.value = 'tls';
    });
    document.getElementById('preset465')?.addEventListener('click', () => {
        port.value = '465';
        encryption.value = 'ssl';
    });
})();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
