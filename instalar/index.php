<?php

declare(strict_types=1);

$portalRoot = dirname(__DIR__);
$installerRoot = __DIR__;

require_once $installerRoot . '/lib/Installer.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_name('portal_ieclb_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$installer = new PortalInstaller($portalRoot, $installerRoot);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installCsrf(): string
{
    if (empty($_SESSION['_installer_csrf'])) {
        $_SESSION['_installer_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['_installer_csrf'];
}

function verifyInstallCsrf(): void
{
    $sent = (string)($_POST['_token'] ?? '');
    $known = (string)($_SESSION['_installer_csrf'] ?? '');

    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        throw new RuntimeException('Sua sessão do instalador expirou. Atualize a página e tente novamente.');
    }
}

function installerUrl(int $step): string
{
    return 'index.php?etapa=' . max(1, min(6, $step));
}

function redirectInstaller(int $step): never
{
    header('Location: ' . installerUrl($step));
    exit;
}

function readInstallLock(PortalInstaller $installer): array
{
    if (!$installer->isInstalled()) {
        return [];
    }

    $data = json_decode((string)file_get_contents($installer->lockFile()), true);
    return is_array($data) ? $data : [];
}

$step = max(1, min(6, (int)($_GET['etapa'] ?? 1)));
$error = '';
$notice = '';
$progress = [];
$requirements = $installer->requirements();
$requirementsOk = $installer->requirementsOk();
$detectedBaseUrl = $installer->detectBaseUrl($_SERVER);

if ($installer->isInstalled()) {
    $step = 6;
}

if (!isset($_SESSION['install_data'])) {
    $_SESSION['install_data'] = [];
}
$installData = &$_SESSION['install_data'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyInstallCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'requirements') {
            if (!$requirementsOk) {
                throw new RuntimeException('Corrija os requisitos obrigatórios antes de continuar.');
            }
            redirectInstaller(2);
        }

        if ($action === 'database') {
            $postedPassword = (string)($_POST['db_pass'] ?? '');
            if ($postedPassword === '' && !empty($installData['db']['pass'])) {
                $postedPassword = (string)$installData['db']['pass'];
            }

            $db = $installer->validateDatabaseInput([
                'host' => $_POST['db_host'] ?? '',
                'port' => $_POST['db_port'] ?? '3306',
                'name' => $_POST['db_name'] ?? '',
                'user' => $_POST['db_user'] ?? '',
                'pass' => $postedPassword,
                'create' => isset($_POST['db_create']),
            ]);

            $pdo = $installer->connectDatabase($db, true);
            $state = $installer->databaseState($pdo);

            if ($state['tables'] > 0 && !$state['resumable']) {
                throw new RuntimeException(
                    'O banco selecionado já contém ' . $state['tables']
                    . ' tabela(s). Para proteger seus dados, o instalador aceita apenas um banco vazio '
                    . 'ou uma instalação nova iniciada por este próprio assistente.'
                );
            }

            $installData['db'] = $db;
            $notice = $state['resumable']
                ? 'Instalação anterior detectada. O assistente poderá continuar do ponto onde parou.'
                : 'Conexão realizada e banco pronto para a instalação.';
            $_SESSION['installer_notice'] = $notice;
            redirectInstaller(3);
        }

        if ($action === 'site') {
            if (empty($installData['db'])) {
                redirectInstaller(2);
            }

            $siteName = trim((string)($_POST['site_name'] ?? ''));
            $siteDescription = trim((string)($_POST['site_description'] ?? ''));
            $siteEmail = strtolower(trim((string)($_POST['site_email'] ?? '')));
            $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
            $timezone = trim((string)($_POST['timezone'] ?? 'America/Sao_Paulo'));

            if ($siteName === '') {
                throw new RuntimeException('Informe o nome do site.');
            }
            if ($siteEmail !== '' && !filter_var($siteEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido para o site ou deixe o campo vazio.');
            }
            if (!preg_match('#^https?://[^/]+#i', $baseUrl)) {
                throw new RuntimeException('Informe uma URL válida começando com http:// ou https://.');
            }

            try {
                new DateTimeZone($timezone);
            } catch (Throwable $e) {
                throw new RuntimeException('Fuso horário inválido.');
            }

            $installData['site'] = [
                'name' => $siteName,
                'description' => $siteDescription,
                'email' => $siteEmail,
                'base_url' => $baseUrl,
                'timezone' => $timezone,
            ];

            redirectInstaller(4);
        }

        if ($action === 'admin') {
            if (empty($installData['db'])) redirectInstaller(2);
            if (empty($installData['site'])) redirectInstaller(3);

            $name = trim((string)($_POST['admin_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
            $password = (string)($_POST['admin_password'] ?? '');
            $confirmation = (string)($_POST['admin_password_confirmation'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Informe o nome do administrador.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido para o administrador.');
            }
            if (strlen($password) < 10) {
                throw new RuntimeException('A senha deve possuir pelo menos 10 caracteres.');
            }
            if ($password !== $confirmation) {
                throw new RuntimeException('A confirmação da senha não confere.');
            }

            $installData['admin'] = [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ];

            redirectInstaller(5);
        }

        if ($action === 'install') {
            if (!$requirementsOk) redirectInstaller(1);
            if (empty($installData['db'])) redirectInstaller(2);
            if (empty($installData['site'])) redirectInstaller(3);
            if (empty($installData['admin'])) redirectInstaller(4);

            if (empty($_POST['confirm_install'])) {
                throw new RuntimeException('Confirme que deseja iniciar a instalação.');
            }

            @set_time_limit(0);

            $progress[] = 'Conectando ao banco de dados...';
            $pdo = $installer->connectDatabase($installData['db'], true);
            $progress[] = 'Conexão com o banco realizada.';

            $installer->installDatabase(
                $pdo,
                static function (string $message) use (&$progress): void {
                    $progress[] = $message;
                }
            );

            $progress[] = 'Salvando dados do site...';
            $installer->configureSite($pdo, $installData['site']);

            $progress[] = 'Criando administrador...';
            $installer->createAdministrator($pdo, $installData['admin']);

            $progress[] = 'Gerando config/config.php...';
            $installer->writeConfig($installData['db'], $installData['site']);

            $progress[] = 'Bloqueando nova execução do instalador...';
            $installer->writeLock($installData['site']);

            $completed = [
                'site' => $installData['site'],
                'admin_email' => $installData['admin']['email'],
                'progress' => $progress,
            ];

            // Remove senhas e dados de banco da sessão.
            $_SESSION['install_completed'] = $completed;
            unset($_SESSION['install_data']);
            unset($_SESSION['_installer_csrf']);
            session_regenerate_id(true);

            redirectInstaller(6);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!empty($_SESSION['installer_notice'])) {
    $notice = (string)$_SESSION['installer_notice'];
    unset($_SESSION['installer_notice']);
}

$steps = [
    1 => ['Requisitos', 'Servidor'],
    2 => ['Banco de dados', 'MySQL/MariaDB'],
    3 => ['Dados do site', 'Portal'],
    4 => ['Administrador', 'Primeiro acesso'],
    5 => ['Instalar', 'Confirmação'],
    6 => ['Concluído', 'Finalização'],
];

// Evita pular etapas digitando a URL diretamente.
if (!$installer->isInstalled()) {
    if ($step >= 3 && empty($installData['db'])) {
        $step = 2;
    } elseif ($step >= 4 && empty($installData['site'])) {
        $step = 3;
    } elseif ($step >= 5 && empty($installData['admin'])) {
        $step = 4;
    }
}

$lockData = readInstallLock($installer);
$completedData = is_array($_SESSION['install_completed'] ?? null)
    ? $_SESSION['install_completed']
    : [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalação · Portal IECLB Parobé</title>
<style>
:root{
    --blue:#174f87;--blue2:#0f3c6a;--green:#198754;--red:#b42318;--orange:#b54708;
    --bg:#f4f7fa;--card:#fff;--line:#d8e0e8;--text:#182230;--muted:#667085;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
a{color:var(--blue)}
.wrap{max-width:1100px;margin:0 auto;padding:32px 20px 60px}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:28px}
.logo{width:52px;height:52px;border-radius:15px;background:linear-gradient(135deg,var(--blue),#2b78b9);color:#fff;display:grid;place-items:center;font-weight:800;font-size:21px;box-shadow:0 10px 24px rgba(23,79,135,.18)}
.brand h1{font-size:22px;margin:0}.brand p{margin:2px 0 0;color:var(--muted)}
.layout{display:grid;grid-template-columns:265px 1fr;gap:24px}
.steps,.card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 28px rgba(16,24,40,.05)}
.steps{padding:14px;height:max-content}
.step{display:flex;gap:11px;padding:13px;border-radius:12px;color:var(--muted)}
.step.current{background:#eef5fb;color:var(--blue2)}
.step.done{color:var(--green)}
.step-number{flex:0 0 30px;width:30px;height:30px;border:1px solid var(--line);border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:700;background:#fff}
.step.current .step-number{border-color:var(--blue);background:var(--blue);color:#fff}
.step.done .step-number{border-color:#a6d5bc;background:#ecfdf3}
.step strong{display:block;color:inherit}.step small{display:block}
.card{padding:30px}
h2{font-size:26px;margin:0 0 6px}.lead{color:var(--muted);margin:0 0 26px}
.alert{padding:13px 15px;border-radius:11px;margin:0 0 20px;border:1px solid}
.alert.error{background:#fff1f0;border-color:#f5b7b1;color:var(--red)}
.alert.ok{background:#ecfdf3;border-color:#abefc6;color:#067647}
.alert.warn{background:#fffaeb;border-color:#fedf89;color:var(--orange)}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.field{margin-bottom:18px}.field.full{grid-column:1/-1}
label{display:block;font-weight:650;margin-bottom:7px}
input,select,textarea{width:100%;border:1px solid #cbd5df;border-radius:10px;padding:11px 12px;background:#fff;color:var(--text);font:inherit;outline:none}
input:focus,select:focus,textarea:focus{border-color:#65a3d4;box-shadow:0 0 0 3px rgba(43,120,185,.12)}
small.help{color:var(--muted);display:block;margin-top:6px}
.actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:28px;padding-top:22px;border-top:1px solid var(--line)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid transparent;border-radius:10px;padding:11px 17px;text-decoration:none;font-weight:700;cursor:pointer;font:inherit}
.btn.primary{background:var(--blue);color:#fff}.btn.primary:hover{background:var(--blue2)}
.btn.secondary{border-color:#cbd5df;background:#fff;color:var(--text)}
.btn.success{background:var(--green);color:#fff}
.check-list{list-style:none;padding:0;margin:0}
.check-list li{display:grid;grid-template-columns:28px 1fr auto;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid #edf0f3}
.check-list li:last-child{border-bottom:0}
.status{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:900}
.status.yes{background:#dcfae6;color:#067647}.status.no{background:#fee4e2;color:#b42318}.status.warn{background:#fef0c7;color:#b54708}
.pill{border-radius:999px;padding:3px 8px;font-size:12px;background:#f2f4f7;color:#475467}
.summary{border:1px solid var(--line);border-radius:12px;overflow:hidden}
.summary-row{display:grid;grid-template-columns:190px 1fr;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line)}
.summary-row:last-child{border-bottom:0}.summary-row span:first-child{color:var(--muted)}
.checkbox{display:flex;gap:10px;align-items:flex-start}.checkbox input{width:auto;margin-top:4px}
.progress-list{list-style:none;padding:0;margin:18px 0 0}.progress-list li{padding:7px 0;color:#475467}.progress-list li:before{content:"✓";color:var(--green);font-weight:800;margin-right:8px}
.code{background:#101828;color:#e4e7ec;border-radius:10px;padding:12px 14px;font:13px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;overflow:auto}
.locked{text-align:center;padding:18px 0}.locked .big{font-size:52px}
@media(max-width:820px){.layout{grid-template-columns:1fr}.steps{display:grid;grid-template-columns:repeat(3,1fr)}.step small{display:none}.card{padding:22px}.grid{grid-template-columns:1fr}.summary-row{grid-template-columns:1fr}.step{padding:9px}}
@media(max-width:520px){.steps{grid-template-columns:repeat(2,1fr)}.wrap{padding:20px 12px}.brand{align-items:flex-start}}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="logo">IE</div>
        <div>
            <h1>Portal IECLB Parobé</h1>
            <p>Assistente de instalação · v<?=h(PortalInstaller::TARGET_VERSION)?></p>
        </div>
    </div>

    <div class="layout">
        <nav class="steps" aria-label="Etapas da instalação">
            <?php foreach($steps as $number => [$title,$subtitle]):?>
                <div class="step <?=$step === $number ? 'current' : ($step > $number ? 'done' : '')?>">
                    <div class="step-number"><?=$step > $number ? '✓' : $number?></div>
                    <div><strong><?=h($title)?></strong><small><?=h($subtitle)?></small></div>
                </div>
            <?php endforeach;?>
        </nav>

        <main class="card">
            <?php if($error !== ''):?><div class="alert error"><?=h($error)?></div><?php endif;?>
            <?php if($notice !== ''):?><div class="alert ok"><?=h($notice)?></div><?php endif;?>

            <?php if($step === 1):?>
                <h2>1. Verificar requisitos</h2>
                <p class="lead">Antes de instalar, confira se o servidor atende aos requisitos do Portal.</p>

                <ul class="check-list">
                    <?php foreach($requirements as $requirement):?>
                        <li>
                            <span class="status <?=$requirement['ok'] ? 'yes' : ($requirement['required'] ? 'no' : 'warn')?>">
                                <?=$requirement['ok'] ? '✓' : '!'?>
                            </span>
                            <div>
                                <strong><?=h($requirement['label'])?></strong>
                                <small class="help"><?=h($requirement['detail'])?></small>
                            </div>
                            <span class="pill"><?=$requirement['required'] ? 'Obrigatório' : 'Recomendado'?></span>
                        </li>
                    <?php endforeach;?>
                </ul>

                <?php if(!$requirementsOk):?>
                    <div class="alert warn" style="margin-top:20px">
                        Corrija os itens obrigatórios marcados acima e atualize esta página.
                    </div>
                <?php endif;?>

                <form method="post" class="actions">
                    <span></span>
                    <div>
                        <input type="hidden" name="_token" value="<?=h(installCsrf())?>">
                        <input type="hidden" name="action" value="requirements">
                        <button class="btn primary" <?=$requirementsOk ? '' : 'disabled'?>>Continuar →</button>
                    </div>
                </form>

            <?php elseif($step === 2):?>
                <h2>2. Banco de dados</h2>
                <p class="lead">Informe os dados MySQL/MariaDB. O instalador não apaga bancos existentes.</p>

                <?php $db = is_array($installData['db'] ?? null) ? $installData['db'] : []; ?>
                <form method="post">
                    <input type="hidden" name="_token" value="<?=h(installCsrf())?>">
                    <input type="hidden" name="action" value="database">

                    <div class="grid">
                        <div class="field">
                            <label for="db_host">Servidor</label>
                            <input id="db_host" name="db_host" value="<?=h((string)($db['host'] ?? 'localhost'))?>" required>
                            <small class="help">Normalmente localhost no cPanel/HostGator.</small>
                        </div>
                        <div class="field">
                            <label for="db_port">Porta</label>
                            <input id="db_port" name="db_port" inputmode="numeric" value="<?=h((string)($db['port'] ?? '3306'))?>" required>
                        </div>
                        <div class="field">
                            <label for="db_name">Nome do banco</label>
                            <input id="db_name" name="db_name" value="<?=h((string)($db['name'] ?? ''))?>" required autocomplete="off">
                        </div>
                        <div class="field">
                            <label for="db_user">Usuário do banco</label>
                            <input id="db_user" name="db_user" value="<?=h((string)($db['user'] ?? ''))?>" required autocomplete="username">
                        </div>
                        <div class="field full">
                            <label for="db_pass">Senha do banco</label>
                            <input id="db_pass" type="password" name="db_pass" value="" autocomplete="new-password">
                            <small class="help"><?=!empty($db['pass']) ? 'Senha já armazenada nesta sessão; deixe vazio para mantê-la.' : 'A senha não será exibida nas próximas etapas.'?></small>
                        </div>
                        <div class="field full">
                            <label class="checkbox">
                                <input type="checkbox" name="db_create" value="1" <?=!empty($db['create']) ? 'checked' : ''?>>
                                <span>
                                    <strong>Tentar criar o banco se ele não existir</strong>
                                    <small class="help">Requer permissão CREATE DATABASE. Em hospedagem cPanel, normalmente o banco deve ser criado antes no painel.</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="alert warn">
                        Use um <strong>banco vazio</strong>. Se houver tabelas de outro site ou instalação, o assistente bloqueará a operação.
                    </div>

                    <div class="actions">
                        <a class="btn secondary" href="<?=h(installerUrl(1))?>">← Voltar</a>
                        <button class="btn primary">Testar e continuar →</button>
                    </div>
                </form>

            <?php elseif($step === 3):?>
                <?php
                $site = is_array($installData['site'] ?? null) ? $installData['site'] : [];
                ?>
                <h2>3. Dados do site</h2>
                <p class="lead">Defina o nome público, endereço e fuso horário do Portal.</p>

                <form method="post">
                    <input type="hidden" name="_token" value="<?=h(installCsrf())?>">
                    <input type="hidden" name="action" value="site">

                    <div class="field">
                        <label for="site_name">Nome do site</label>
                        <input id="site_name" name="site_name" value="<?=h((string)($site['name'] ?? 'Paróquia Evangélica de Confissão Luterana de Parobé'))?>" required>
                    </div>
                    <div class="field">
                        <label for="site_description">Descrição</label>
                        <textarea id="site_description" name="site_description" rows="3"><?=h((string)($site['description'] ?? 'Portal de notícias e informações da IECLB Parobé'))?></textarea>
                    </div>
                    <div class="grid">
                        <div class="field">
                            <label for="site_email">E-mail público</label>
                            <input id="site_email" type="email" name="site_email" value="<?=h((string)($site['email'] ?? ''))?>" placeholder="secretaria@exemplo.com.br">
                        </div>
                        <div class="field">
                            <label for="timezone">Fuso horário</label>
                            <select id="timezone" name="timezone">
                                <?php $selectedTz = (string)($site['timezone'] ?? 'America/Sao_Paulo'); ?>
                                <?php foreach(['America/Sao_Paulo','America/Porto_Velho','America/Manaus','America/Belem','America/Fortaleza','America/Recife'] as $tz):?>
                                    <option value="<?=h($tz)?>" <?=$selectedTz === $tz ? 'selected' : ''?>><?=h($tz)?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="field full">
                            <label for="base_url">URL do Portal</label>
                            <input id="base_url" name="base_url" value="<?=h((string)($site['base_url'] ?? $detectedBaseUrl))?>" required>
                            <small class="help">Ex.: https://www.seudominio.com.br ou https://www.seudominio.com.br/portal</small>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="btn secondary" href="<?=h(installerUrl(2))?>">← Voltar</a>
                        <button class="btn primary">Continuar →</button>
                    </div>
                </form>

            <?php elseif($step === 4):?>
                <?php $admin = is_array($installData['admin'] ?? null) ? $installData['admin'] : []; ?>
                <h2>4. Administrador</h2>
                <p class="lead">Crie a conta que terá acesso total ao painel administrativo.</p>

                <form method="post">
                    <input type="hidden" name="_token" value="<?=h(installCsrf())?>">
                    <input type="hidden" name="action" value="admin">

                    <div class="field">
                        <label for="admin_name">Nome</label>
                        <input id="admin_name" name="admin_name" value="<?=h((string)($admin['name'] ?? 'Administrador'))?>" required autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="admin_email">E-mail de acesso</label>
                        <input id="admin_email" type="email" name="admin_email" value="<?=h((string)($admin['email'] ?? ''))?>" required autocomplete="email">
                    </div>
                    <div class="grid">
                        <div class="field">
                            <label for="admin_password">Senha</label>
                            <input id="admin_password" type="password" name="admin_password" minlength="10" required autocomplete="new-password">
                            <small class="help">Mínimo de 10 caracteres.</small>
                        </div>
                        <div class="field">
                            <label for="admin_password_confirmation">Confirmar senha</label>
                            <input id="admin_password_confirmation" type="password" name="admin_password_confirmation" minlength="10" required autocomplete="new-password">
                        </div>
                    </div>

                    <div class="actions">
                        <a class="btn secondary" href="<?=h(installerUrl(3))?>">← Voltar</a>
                        <button class="btn primary">Revisar instalação →</button>
                    </div>
                </form>

            <?php elseif($step === 5):?>
                <h2>5. Revisar e instalar</h2>
                <p class="lead">Confira os dados. A senha do banco e a senha do administrador nunca são exibidas.</p>

                <div class="summary">
                    <div class="summary-row"><span>Versão</span><strong><?=h(PortalInstaller::TARGET_VERSION)?></strong></div>
                    <div class="summary-row"><span>Banco</span><strong><?=h((string)$installData['db']['name'])?></strong></div>
                    <div class="summary-row"><span>Servidor DB</span><strong><?=h((string)$installData['db']['host'])?>:<?=h((string)$installData['db']['port'])?></strong></div>
                    <div class="summary-row"><span>Site</span><strong><?=h((string)$installData['site']['name'])?></strong></div>
                    <div class="summary-row"><span>URL</span><strong><?=h((string)$installData['site']['base_url'])?></strong></div>
                    <div class="summary-row"><span>Administrador</span><strong><?=h((string)$installData['admin']['email'])?></strong></div>
                </div>

                <div class="alert warn" style="margin-top:20px">
                    O instalador fará backup do <code>config/config.php</code> atual antes de gerar o arquivo de produção.
                </div>

                <form method="post">
                    <input type="hidden" name="_token" value="<?=h(installCsrf())?>">
                    <input type="hidden" name="action" value="install">

                    <label class="checkbox" style="margin-top:20px">
                        <input type="checkbox" name="confirm_install" value="1" required>
                        <span>
                            <strong>Confirmo a instalação neste banco.</strong>
                            <small class="help">O banco deve estar vazio ou ser uma instalação nova interrompida deste assistente.</small>
                        </span>
                    </label>

                    <?php if($progress):?>
                        <ul class="progress-list">
                            <?php foreach($progress as $item):?><li><?=h($item)?></li><?php endforeach;?>
                        </ul>
                    <?php endif;?>

                    <div class="actions">
                        <a class="btn secondary" href="<?=h(installerUrl(4))?>">← Voltar</a>
                        <button class="btn success">Instalar Portal</button>
                    </div>
                </form>

            <?php else:?>
                <?php
                $finalSite = is_array($completedData['site'] ?? null)
                    ? $completedData['site']
                    : [
                        'base_url' => (string)($lockData['base_url'] ?? $detectedBaseUrl),
                        'name' => (string)($lockData['site_name'] ?? 'Portal IECLB Parobé'),
                    ];
                $finalBase = rtrim((string)($finalSite['base_url'] ?? $detectedBaseUrl), '/');
                ?>
                <div class="locked">
                    <div class="big">✓</div>
                    <h2>Instalação concluída</h2>
                    <p class="lead">
                        O Portal está instalado e o assistente foi bloqueado para novas instalações.
                    </p>
                </div>

                <?php if(!empty($completedData['progress'])):?>
                    <ul class="progress-list">
                        <?php foreach($completedData['progress'] as $item):?><li><?=h((string)$item)?></li><?php endforeach;?>
                    </ul>
                <?php endif;?>

                <div class="summary" style="margin-top:22px">
                    <div class="summary-row"><span>Versão</span><strong><?=h((string)($lockData['version'] ?? PortalInstaller::TARGET_VERSION))?></strong></div>
                    <div class="summary-row"><span>Site</span><strong><?=h((string)($finalSite['name'] ?? 'Portal IECLB Parobé'))?></strong></div>
                    <div class="summary-row"><span>URL</span><strong><?=h($finalBase)?></strong></div>
                    <?php if(!empty($completedData['admin_email'])):?><div class="summary-row"><span>Administrador</span><strong><?=h((string)$completedData['admin_email'])?></strong></div><?php endif;?>
                </div>

                <?php if(!is_file($portalRoot . '/lib/autoload.php')):?>
                    <div class="alert warn" style="margin-top:22px">
                        <strong>Dependências Composer ainda não encontradas.</strong><br>
                        Na raiz do Portal, execute:
                        <div class="code" style="margin-top:10px">composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist</div>
                    </div>
                <?php endif;?>

                <div class="alert ok" style="margin-top:22px">
                    Por segurança, o arquivo <code>storage/installed.lock</code> impede que o instalador seja executado novamente.
                </div>

                <div class="actions">
                    <a class="btn secondary" href="<?=h($finalBase)?>">Abrir site</a>
                    <a class="btn primary" href="<?=h($finalBase . '/admin/login.php')?>">Entrar no painel →</a>
                </div>
            <?php endif;?>
        </main>
    </div>
</div>
</body>
</html>
