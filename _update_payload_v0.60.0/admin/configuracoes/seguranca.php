<?php

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('seguranca.gerenciar');

$pdo = Database::connection();

$defaults = [
    'security_session_timeout_minutes' => '60',
    'security_max_login_attempts' => '5',
    'security_lockout_minutes' => '15',
    'security_audit_retention_days' => '180',
    'security_log_failed_logins' => '1',
];

$settings = array_merge(
    $defaults,
    siteConfigAll($pdo)
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $settings['security_session_timeout_minutes'] =
                (string)max(
                    5,
                    min(
                        1440,
                        (int)($_POST['security_session_timeout_minutes'] ?? 60)
                    )
                );

            $settings['security_max_login_attempts'] =
                (string)max(
                    3,
                    min(
                        20,
                        (int)($_POST['security_max_login_attempts'] ?? 5)
                    )
                );

            $settings['security_lockout_minutes'] =
                (string)max(
                    1,
                    min(
                        180,
                        (int)($_POST['security_lockout_minutes'] ?? 15)
                    )
                );

            $settings['security_audit_retention_days'] =
                (string)max(
                    30,
                    min(
                        3650,
                        (int)($_POST['security_audit_retention_days'] ?? 180)
                    )
                );

            $settings['security_log_failed_logins'] =
                isset($_POST['security_log_failed_logins'])
                    ? '1'
                    : '0';

            foreach ($defaults as $key => $_) {
                $type =
                    $key === 'security_log_failed_logins'
                        ? 'booleano'
                        : 'numero';

                saveSiteConfig(
                    $pdo,
                    $key,
                    $settings[$key],
                    $type
                );
            }

            logAction(
                $pdo,
                'seguranca.configuracoes.atualizar',
                'configuracoes',
                null,
                'Configurações de segurança atualizadas.'
            );

            Session::flash(
                'success',
                'Configurações de segurança atualizadas.'
            );

            header(
                'Location: '
                . url('admin/configuracoes/seguranca.php')
            );

            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$attempts24h = 0;
$blockedCandidates = 0;
$activeUsers = 0;
$twoFactorUsers = 0;

try {
    $attempts24h = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM login_tentativas
         WHERE sucesso=0
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchColumn();

    $maxAttempts = (int)$settings['security_max_login_attempts'];
    $lockMinutes = (int)$settings['security_lockout_minutes'];

    $sql =
        "SELECT COUNT(*)
         FROM (
            SELECT email
            FROM login_tentativas
            WHERE sucesso=0
              AND created_at >= DATE_SUB(
                    NOW(),
                    INTERVAL {$lockMinutes} MINUTE
              )
            GROUP BY email
            HAVING COUNT(*) >= {$maxAttempts}
         ) x";

    $blockedCandidates =
        (int)$pdo->query($sql)->fetchColumn();
} catch (Throwable $e) {
    // Migração antiga ainda não executada.
}

try {
    $activeUsers = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM usuarios
         WHERE ativo=1"
    )->fetchColumn();

    $twoFactorUsers = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM usuarios
         WHERE ativo=1
           AND totp_enabled_at IS NOT NULL
           AND totp_secret IS NOT NULL
           AND totp_secret <> ''"
    )->fetchColumn();
} catch (Throwable $e) {
    // A atualização v0.60.0 ainda não foi concluída.
}

$pageTitle = 'Configurações de Segurança';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Segurança</h1>
        <p class="text-secondary mb-0">
            Proteja o acesso administrativo e defina a retenção da auditoria.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/minha-conta-2fa.php')) ?>"
        >
            <i class="bi bi-shield-lock me-1"></i>
            Meu 2FA
        </a>

        <?php if (Auth::can('auditoria.visualizar')): ?>
            <a
                class="btn btn-outline-secondary"
                href="<?= e(url('admin/auditoria/index.php')) ?>"
            >
                <i class="bi bi-shield-check me-1"></i>
                Ver auditoria
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Falhas de login · 24h
                </div>
                <div class="display-6 fw-semibold">
                    <?= (int)$attempts24h ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    E-mails no limite atual
                </div>
                <div class="display-6 fw-semibold">
                    <?= (int)$blockedCandidates ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Usuários ativos
                </div>
                <div class="display-6 fw-semibold">
                    <?= (int)$activeUsers ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Contas com 2FA
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$twoFactorUsers ?>
                </div>

                <?php if ($activeUsers > 0): ?>
                    <div class="small text-secondary">
                        de <?= (int)$activeUsers ?> usuário(s) ativo(s)
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-3">
    <i class="bi bi-shield-lock fs-4"></i>
    <div>
        <strong>Novidade da v0.60.0:</strong>
        cada usuário pode ativar autenticação em dois fatores em
        <a href="<?= e(url('admin/minha-conta-2fa.php')) ?>">
            Minha conta
        </a>.
        O segundo fator usa TOTP padrão e inclui códigos de recuperação.
    </div>
</div>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>

        <div class="row g-4">
            <div class="col-md-6">
                <label class="form-label">
                    Expirar sessão após inatividade
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="5"
                        max="1440"
                        name="security_session_timeout_minutes"
                        value="<?= e($settings['security_session_timeout_minutes']) ?>"
                        required
                    >

                    <span class="input-group-text">
                        minutos
                    </span>
                </div>

                <div class="form-text">
                    A conta será desconectada automaticamente após esse período sem atividade.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Máximo de tentativas de login
                </label>

                <input
                    class="form-control"
                    type="number"
                    min="3"
                    max="20"
                    name="security_max_login_attempts"
                    value="<?= e($settings['security_max_login_attempts']) ?>"
                    required
                >

                <div class="form-text">
                    O bloqueio é aplicado por e-mail; o IP possui uma margem maior
                    para redes compartilhadas.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Tempo de bloqueio
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="1"
                        max="180"
                        name="security_lockout_minutes"
                        value="<?= e($settings['security_lockout_minutes']) ?>"
                        required
                    >

                    <span class="input-group-text">
                        minutos
                    </span>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Reter auditoria por
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="30"
                        max="3650"
                        name="security_audit_retention_days"
                        value="<?= e($settings['security_audit_retention_days']) ?>"
                        required
                    >

                    <span class="input-group-text">
                        dias
                    </span>
                </div>

                <div class="form-text">
                    Registros mais antigos são removidos automaticamente,
                    no máximo uma vez por dia por sessão.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="security_log_failed_logins"
                        id="logFailed"
                        <?= $settings['security_log_failed_logins'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="logFailed"
                    >
                        Registrar falhas de login na Auditoria
                    </label>
                </div>

                <div class="form-text">
                    O e-mail é mascarado nos detalhes do log.
                    O histórico técnico de tentativas continua sendo usado
                    para o bloqueio temporário.
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button class="btn btn-primary px-4">
                Salvar segurança
            </button>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_footer.php'; ?>
