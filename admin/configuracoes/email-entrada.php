<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'email.gerenciar'
);

$pdo =
    Database::connection();

InboundMailService::ensureSchema(
    $pdo
);

$settings =
    InboundMailService::settings(
        $pdo
    );

$error = '';
$diagnostic = null;
$syncResult = null;

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $error =
            'Token de segurança inválido.';
    } else {
        try {
            $action =
                trim(
                    (string)(
                        $_POST['action']
                        ?? 'save'
                    )
                );

            $enabled =
                isset(
                    $_POST['inbound_mail_enabled']
                )
                    ? '1'
                    : '0';

            $address =
                strtolower(
                    trim(
                        (string)(
                            $_POST['inbound_mail_address']
                            ?? ''
                        )
                    )
                );

            if (
                $address !== ''
                && !filter_var(
                    $address,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                throw new RuntimeException(
                    'Informe um endereço de e-mail válido para receber as respostas.'
                );
            }

            $host =
                trim(
                    (string)(
                        $_POST['inbound_imap_host']
                        ?? ''
                    )
                );

            $port =
                max(
                    1,
                    min(
                        65535,
                        (int)(
                            $_POST['inbound_imap_port']
                            ?? 993
                        )
                    )
                );

            $encryption =
                strtolower(
                    trim(
                        (string)(
                            $_POST['inbound_imap_encryption']
                            ?? 'ssl'
                        )
                    )
                );

            if (
                !in_array(
                    $encryption,
                    ['ssl', 'tls', 'none'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Criptografia IMAP inválida.'
                );
            }

            $validateCert =
                isset(
                    $_POST['inbound_imap_validate_cert']
                )
                    ? '1'
                    : '0';

            $folder =
                trim(
                    (string)(
                        $_POST['inbound_imap_folder']
                        ?? 'INBOX'
                    )
                );

            if ($folder === '') {
                $folder = 'INBOX';
            }

            $syncLimit =
                max(
                    10,
                    min(
                        200,
                        (int)(
                            $_POST['inbound_sync_limit']
                            ?? 50
                        )
                    )
                );

            if (
                $enabled === '1'
                && $host === ''
            ) {
                throw new RuntimeException(
                    'Informe o servidor IMAP.'
                );
            }

            $save = [
                'inbound_mail_enabled' =>
                    [$enabled, 'booleano'],
                'inbound_mail_address' =>
                    [$address, 'texto'],
                'inbound_imap_host' =>
                    [$host, 'texto'],
                'inbound_imap_port' =>
                    [(string)$port, 'numero'],
                'inbound_imap_encryption' =>
                    [$encryption, 'texto'],
                'inbound_imap_validate_cert' =>
                    [$validateCert, 'booleano'],
                'inbound_imap_folder' =>
                    [$folder, 'texto'],
                'inbound_sync_limit' =>
                    [(string)$syncLimit, 'numero'],
            ];

            foreach (
                $save
                as $key => [$value, $type]
            ) {
                saveSiteConfig(
                    $pdo,
                    $key,
                    $value,
                    $type
                );
            }

            $settings =
                InboundMailService::settings(
                    $pdo
                );

            if ($action === 'diagnostic') {
                $diagnostic =
                    InboundMailService::diagnose(
                        $pdo
                    );

                logAction(
                    $pdo,
                    'email.entrada.diagnostico',
                    'email',
                    null,
                    !empty(
                        $diagnostic['ok']
                    )
                        ? 'IMAP OK'
                        : 'Falha IMAP'
                );
            } elseif ($action === 'sync') {
                $syncResult =
                    InboundMailService::sync(
                        $pdo,
                        true
                    );

                logAction(
                    $pdo,
                    'email.entrada.sincronizar',
                    'email',
                    null,
                    (string)(
                        $syncResult['message']
                        ?? ''
                    )
                );
            } else {
                logAction(
                    $pdo,
                    'email.entrada.configuracoes',
                    'configuracoes',
                    null,
                    'Recebimento IMAP: '
                    . (
                        $enabled === '1'
                            ? 'ativo'
                            : 'inativo'
                    )
                );

                Session::flash(
                    'success',
                    'Configurações de recebimento por e-mail atualizadas.'
                );

                header(
                    'Location: '
                    . url(
                        'admin/configuracoes/email-entrada.php'
                    )
                );

                exit;
            }
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$settings =
    InboundMailService::settings(
        $pdo
    );

$imapAvailable =
    InboundMailService::extensionAvailable();

$smtpUsername =
    trim(
        siteConfig(
            $pdo,
            'mail_smtp_username',
            ''
        )
    );

$recentIncoming = [];

try {
    $recentIncoming =
        $pdo
            ->query(
                "SELECT
                    e.*,
                    f.titulo AS formulario_titulo
                 FROM formulario_resposta_entradas e
                 LEFT JOIN formularios f
                    ON f.id=e.formulario_id
                 ORDER BY e.id DESC
                 LIMIT 15"
            )
            ->fetchAll(
                PDO::FETCH_ASSOC
            )
        ?: [];
} catch (Throwable $ignored) {
}

$pageTitle =
    'E-mail de Entrada';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Configurações · E-mail
        </div>

        <h1 class="h3 mb-1">
            Respostas recebidas por e-mail
        </h1>

        <p class="text-secondary mb-0">
            Sincroniza respostas dos visitantes com as conversas dos formulários.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/configuracoes/email.php')) ?>"
    >
        Configuração SMTP
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$imapAvailable): ?>
    <div class="alert alert-danger">
        <strong>PHP IMAP não está habilitado.</strong>
        Esta função precisa da extensão <code>imap</code>.
        Na hospedagem/cPanel, habilite a extensão IMAP para a versão do PHP usada pelo Portal.
    </div>
<?php endif; ?>

<?php if ($diagnostic !== null): ?>
    <div class="alert <?= !empty($diagnostic['ok']) ? 'alert-success' : 'alert-danger' ?>">
        <strong>
            <?= !empty($diagnostic['ok']) ? 'Conexão IMAP OK.' : 'Falha na conexão IMAP.' ?>
        </strong>
        <?= e((string)($diagnostic['message'] ?? '')) ?>

        <?php if (!empty($diagnostic['ok'])): ?>
            <div class="small mt-2">
                Caixa:
                <code><?= e((string)$diagnostic['mailbox']) ?></code>
                · usuário:
                <code><?= e((string)$diagnostic['username']) ?></code>
                · mensagens:
                <?= (int)$diagnostic['messages'] ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($syncResult !== null): ?>
    <div class="alert <?= ($syncResult['status'] ?? '') === 'ok' ? 'alert-success' : 'alert-warning' ?>">
        <?= e((string)($syncResult['message'] ?? '')) ?>
        <div class="small mt-2">
            Verificadas:
            <?= (int)($syncResult['checked'] ?? 0) ?>
            · importadas:
            <?= (int)($syncResult['imported'] ?? 0) ?>
            · ignoradas:
            <?= (int)($syncResult['ignored'] ?? 0) ?>
        </div>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <strong>Como funciona:</strong>
    quando você responde pelo Portal, o assunto recebe um identificador interno
    como <code>[IECLB-R123]</code>. Se a pessoa responder esse e-mail, o Portal
    reconhece o identificador e só importa a mensagem se o remetente for o mesmo
    endereço informado no formulário original.
</div>

<form
    method="post"
    class="card border-0 shadow-sm mb-4"
    autocomplete="off"
>
    <div class="card-body p-4">
        <?= Csrf::field() ?>

        <div class="form-check form-switch mb-4">
            <input
                class="form-check-input"
                type="checkbox"
                name="inbound_mail_enabled"
                id="inboundEnabled"
                <?= $settings['inbound_mail_enabled'] === '1' ? 'checked' : '' ?>
            >

            <label
                class="form-check-label fw-semibold"
                for="inboundEnabled"
            >
                Receber automaticamente respostas dos contatos
            </label>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <label class="form-label">
                    E-mail monitorado / Responder para
                </label>

                <input
                    class="form-control"
                    type="email"
                    name="inbound_mail_address"
                    value="<?= e($settings['inbound_mail_address']) ?>"
                    placeholder="contato@seudominio.org.br"
                >

                <div class="form-text">
                    Os e-mails enviados pelo Portal usarão este endereço como Reply-To.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Usuário de autenticação
                </label>

                <input
                    class="form-control"
                    value="<?= e($smtpUsername) ?>"
                    readonly
                >

                <div class="form-text">
                    A v0.79 usa o mesmo usuário e senha protegidos em Configurações → E-mail.
                </div>
            </div>

            <div class="col-md-5">
                <label class="form-label">
                    Servidor IMAP
                </label>

                <input
                    class="form-control"
                    name="inbound_imap_host"
                    value="<?= e($settings['inbound_imap_host']) ?>"
                    placeholder="mail.seudominio.org.br"
                >
            </div>

            <div class="col-md-2">
                <label class="form-label">
                    Porta
                </label>

                <input
                    class="form-control"
                    type="number"
                    min="1"
                    max="65535"
                    name="inbound_imap_port"
                    value="<?= e($settings['inbound_imap_port']) ?>"
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">
                    Criptografia
                </label>

                <select
                    class="form-select"
                    name="inbound_imap_encryption"
                >
                    <option
                        value="ssl"
                        <?= $settings['inbound_imap_encryption'] === 'ssl' ? 'selected' : '' ?>
                    >
                        SSL/TLS
                    </option>

                    <option
                        value="tls"
                        <?= $settings['inbound_imap_encryption'] === 'tls' ? 'selected' : '' ?>
                    >
                        STARTTLS
                    </option>

                    <option
                        value="none"
                        <?= $settings['inbound_imap_encryption'] === 'none' ? 'selected' : '' ?>
                    >
                        Sem criptografia
                    </option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">
                    Por ciclo
                </label>

                <input
                    class="form-control"
                    type="number"
                    min="10"
                    max="200"
                    name="inbound_sync_limit"
                    value="<?= e($settings['inbound_sync_limit']) ?>"
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Pasta IMAP
                </label>

                <input
                    class="form-control"
                    name="inbound_imap_folder"
                    value="<?= e($settings['inbound_imap_folder']) ?>"
                    placeholder="INBOX"
                >
            </div>

            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="inbound_imap_validate_cert"
                        id="imapValidateCert"
                        <?= $settings['inbound_imap_validate_cert'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="imapValidateCert"
                    >
                        Validar certificado TLS do servidor IMAP
                    </label>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button
                class="btn btn-primary"
                name="action"
                value="save"
            >
                Salvar configurações
            </button>

            <button
                class="btn btn-outline-secondary"
                name="action"
                value="diagnostic"
                <?= !$imapAvailable ? 'disabled' : '' ?>
            >
                Testar conexão IMAP
            </button>

            <button
                class="btn btn-outline-primary"
                name="action"
                value="sync"
                <?= !$imapAvailable ? 'disabled' : '' ?>
            >
                Verificar respostas agora
            </button>
        </div>
    </div>
</form>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="fw-semibold">
            Últimas respostas importadas
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Formulário</th>
                    <th>Remetente</th>
                    <th>Assunto</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$recentIncoming): ?>
                    <tr>
                        <td
                            colspan="5"
                            class="text-secondary py-4"
                        >
                            Nenhuma resposta por e-mail importada ainda.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recentIncoming as $item): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= e(
                                formatDateBr(
                                    (string)(
                                        $item['received_at']
                                        ?: $item['created_at']
                                    )
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= e(
                                (string)(
                                    $item['formulario_titulo']
                                    ?? 'Formulário'
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= e((string)$item['remetente']) ?>
                        </td>

                        <td class="text-break">
                            <?= e((string)($item['assunto'] ?: 'Sem assunto')) ?>
                        </td>

                        <td class="text-end">
                            <a
                                class="btn btn-sm btn-outline-primary"
                                href="<?= e(
                                    url(
                                        'admin/formularios/resposta.php?id='
                                        . (int)$item['resposta_id']
                                    )
                                ) ?>"
                            >
                                Abrir conversa
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
