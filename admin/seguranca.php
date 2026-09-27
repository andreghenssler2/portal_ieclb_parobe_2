<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

Auth::requirePermission(
    'seguranca.gerenciar'
);

$pdo =
    Database::connection();

$actorUserId =
    (int)Auth::id();

$timeout =
    max(
        5,
        min(
            1440,
            (int)siteConfig(
                $pdo,
                'security_session_timeout_minutes',
                '60'
            )
        )
    );

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
        Session::flash(
            'error',
            'Token de segurança inválido.'
        );
    } else {
        $action =
            trim(
                (string)(
                    $_POST['action']
                    ?? ''
                )
            );

        if ($action === 'revoke_session') {
            $sessionId =
                max(
                    0,
                    (int)(
                        $_POST['session_id']
                        ?? 0
                    )
                );

            $ok =
                SessionSecurityService::revokeSession(
                    $pdo,
                    $sessionId,
                    $actorUserId,
                    null
                );

            if ($ok) {
                logAction(
                    $pdo,
                    'seguranca.central.sessao.encerrar',
                    'user_sessions',
                    $sessionId,
                    'Sessão remota encerrada pela Central de Segurança.',
                    'warning'
                );

                Session::flash(
                    'success',
                    'Sessão encerrada remotamente.'
                );
            } else {
                Session::flash(
                    'error',
                    'Não foi possível encerrar a sessão. A sessão atual é protegida.'
                );
            }
        } elseif ($action === 'revoke_user') {
            $targetUserId =
                max(
                    0,
                    (int)(
                        $_POST['user_id']
                        ?? 0
                    )
                );

            $keepCurrent =
                $targetUserId
                === $actorUserId;

            $count =
                SessionSecurityService::revokeAllForUser(
                    $pdo,
                    $targetUserId,
                    $actorUserId,
                    $keepCurrent
                );

            logAction(
                $pdo,
                'seguranca.central.usuario.sessoes.encerrar',
                'usuarios',
                $targetUserId,
                $count
                . ' sessão(ões) encerrada(s) pela Central de Segurança.',
                'warning'
            );

            Session::flash(
                'success',
                $count > 0
                    ? $count
                        . ' sessão(ões) encerrada(s).'
                    : 'Nenhuma outra sessão ativa foi encontrada.'
            );
        } else {
            Session::flash(
                'error',
                'Ação de segurança inválida.'
            );
        }
    }

    header(
        'Location: '
        . url('admin/seguranca.php')
    );

    exit;
}

$security =
    (
        new SecurityCenterService(
            $pdo
        )
    )->snapshot();

$stats =
    (array)(
        $security['stats']
        ?? []
    );

$twoFactor =
    (array)(
        $security['two_factor']
        ?? []
    );

$sessions =
    (array)(
        $security['sessions']
        ?? []
    );

$lockouts =
    (array)(
        $security['lockouts']
        ?? []
    );

$loginHistory =
    (array)(
        $security['login_history']
        ?? []
    );

$audit =
    (array)(
        $security['audit']
        ?? []
    );

$pageTitle =
    'Central de Segurança';

require __DIR__ . '/_header.php';

$loginActionLabel =
    static function (
        string $action
    ): string {
        return
            match ($action) {
                'login.sucesso' =>
                    'Login realizado',
                'login.falhou' =>
                    'Falha de login',
                'login.bloqueado' =>
                    'Login bloqueado',
                'login.2fa.solicitado' =>
                    '2FA solicitado',
                'login.2fa.falhou' =>
                    'Falha no 2FA',
                'logout' =>
                    'Logout',
                default =>
                    $action,
            };
    };

$levelClass =
    static function (
        string $level
    ): string {
        return
            match ($level) {
                'critical' =>
                    'text-bg-danger',
                'warning' =>
                    'text-bg-warning',
                default =>
                    'text-bg-secondary',
            };
    };
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Segurança v1.1.11
        </div>

        <h1 class="h3 mb-1">
            Central de Segurança
        </h1>

        <p class="text-secondary mb-0">
            Acompanhe logins, bloqueios, sessões, autenticação em dois fatores e auditoria administrativa.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/configuracoes/seguranca.php')) ?>"
        >
            <i class="bi bi-sliders me-1"></i>
            Configurações
        </a>

        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/minha-conta-2fa.php')) ?>"
        >
            <i class="bi bi-shield-lock me-1"></i>
            Meu 2FA
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/auditoria/index.php')) ?>"
        >
            <i class="bi bi-journal-text me-1"></i>
            Auditoria
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Sessões ativas
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($stats['sessions'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Falhas · 24h
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($stats['failed_24h'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Bloqueios atuais
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($stats['lockouts'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Contas com 2FA
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($stats['two_factor_enabled'] ?? 0) ?>
                </div>

                <div class="small text-secondary">
                    de <?= (int)($stats['two_factor_total'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Alertas · 24h
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($stats['warnings_24h'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    2FA coberto
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)($twoFactor['coverage_percent'] ?? 0) ?>%
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-3 py-3">
                <div>
                    <div class="fw-semibold">
                        Sessões abertas
                    </div>

                    <div class="small text-secondary">
                        Encerramento remoto disponível. A sessão atual não pode ser encerrada daqui.
                    </div>
                </div>

                <a
                    class="btn btn-sm btn-outline-secondary"
                    href="<?= e(url('admin/configuracoes/sessoes.php')) ?>"
                >
                    Gerenciar todas
                </a>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Dispositivo</th>
                            <th>IP</th>
                            <th>Último acesso</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$sessions): ?>
                            <tr>
                                <td
                                    colspan="5"
                                    class="text-center text-secondary py-4"
                                >
                                    Nenhuma sessão ativa.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach (array_slice($sessions, 0, 12) as $session): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)(
                                                $session['usuario_nome']
                                                ?? 'Usuário'
                                            )
                                        ) ?>

                                        <?php if (!empty($session['is_current'])): ?>
                                            <span class="badge text-bg-success ms-1">
                                                Você
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= e(
                                            (string)(
                                                $session['usuario_email']
                                                ?? ''
                                            )
                                        ) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= e(
                                        (string)(
                                            $session['device_label']
                                            ?? 'Dispositivo'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        (string)(
                                            $session['ip']
                                            ?: '—'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        formatDateBr(
                                            (string)$session['last_seen_at']
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-2">
                                        <?php if (empty($session['is_current'])): ?>
                                            <form
                                                method="post"
                                                onsubmit="return confirm('Encerrar esta sessão remotamente?');"
                                            >
                                                <?= Csrf::field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="revoke_session"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="session_id"
                                                    value="<?= (int)$session['id'] ?>"
                                                >

                                                <button class="btn btn-sm btn-outline-danger">
                                                    Encerrar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Encerrar as outras sessões deste usuário?');"
                                        >
                                            <?= Csrf::field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="revoke_user"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int)$session['user_id'] ?>"
                                            >

                                            <button class="btn btn-sm btn-outline-secondary">
                                                Encerrar usuário
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body py-3">
                <div class="fw-semibold">
                    Bloqueios temporários
                </div>

                <div class="small text-secondary">
                    Janela de
                    <?= (int)($security['lock_minutes'] ?? 15) ?>
                    min; limite de
                    <?= (int)($security['max_attempts'] ?? 5) ?>
                    falhas por e-mail.
                </div>
            </div>

            <div class="list-group list-group-flush">
                <?php if (!$lockouts): ?>
                    <div class="list-group-item py-4 text-secondary">
                        Nenhum bloqueio ativo no momento.
                    </div>
                <?php endif; ?>

                <?php foreach ($lockouts as $lock): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold">
                                    <?= e(
                                        (string)(
                                            $lock['identificador_exibicao']
                                            ?? 'Identificador'
                                        )
                                    ) ?>
                                </div>

                                <div class="small text-secondary">
                                    <?= e(
                                        (string)(
                                            $lock['tipo']
                                            ?? ''
                                        )
                                    ) ?>
                                    ·
                                    <?= (int)($lock['falhas'] ?? 0) ?>
                                    tentativa(s)
                                </div>
                            </div>

                            <div class="small text-secondary text-nowrap">
                                <?= !empty($lock['ultima_tentativa'])
                                    ? e(
                                        formatDateBr(
                                            (string)$lock['ultima_tentativa']
                                        )
                                    )
                                    : '—' ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card-footer bg-body small text-secondary">
                O bloqueio é liberado automaticamente quando as tentativas saem da janela configurada.
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-3 py-3">
                <div>
                    <div class="fw-semibold">
                        Histórico de login
                    </div>

                    <div class="small text-secondary">
                        Eventos de entrada, falhas, bloqueios, 2FA e logout registrados na auditoria.
                    </div>
                </div>

                <a
                    class="btn btn-sm btn-outline-secondary"
                    href="<?= e(url('admin/auditoria/index.php?acao=login.sucesso')) ?>"
                >
                    Abrir auditoria
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Evento</th>
                            <th>Usuário</th>
                            <th>IP</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$loginHistory): ?>
                            <tr>
                                <td
                                    colspan="4"
                                    class="text-center text-secondary py-4"
                                >
                                    Nenhum histórico de login encontrado.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($loginHistory as $entry): ?>
                            <tr>
                                <td class="text-nowrap">
                                    <?= e(
                                        formatDateBr(
                                            (string)$entry['created_at']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <span class="badge <?= e(
                                        $levelClass(
                                            (string)($entry['nivel'] ?? 'info')
                                        )
                                    ) ?>">
                                        <?= e(
                                            $loginActionLabel(
                                                (string)$entry['acao']
                                            )
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e(
                                        (string)(
                                            $entry['usuario_nome']
                                            ?: $entry['usuario_email']
                                            ?: 'Visitante'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        (string)(
                                            $entry['ip']
                                            ?: '—'
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body d-flex justify-content-between align-items-center gap-3 py-3">
                <div>
                    <div class="fw-semibold">
                        Autenticação em dois fatores
                    </div>

                    <div class="small text-secondary">
                        TOTP opcional com códigos de recuperação.
                    </div>
                </div>

                <span class="badge <?= !empty($twoFactor['current_user_enabled'])
                    ? 'text-bg-success'
                    : 'text-bg-warning' ?>">
                    <?= !empty($twoFactor['current_user_enabled'])
                        ? 'Seu 2FA ativo'
                        : 'Seu 2FA inativo' ?>
                </span>
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
                    <div>
                        <div class="display-6 fw-semibold">
                            <?= (int)($twoFactor['coverage_percent'] ?? 0) ?>%
                        </div>

                        <div class="text-secondary">
                            <?= (int)($twoFactor['enabled_users'] ?? 0) ?>
                            de
                            <?= (int)($twoFactor['active_users'] ?? 0) ?>
                            conta(s) ativa(s)
                        </div>
                    </div>

                    <a
                        class="btn btn-primary"
                        href="<?= e(url('admin/minha-conta-2fa.php')) ?>"
                    >
                        Meu 2FA
                    </a>
                </div>

                <?php if (!empty($twoFactor['without_2fa'])): ?>
                    <hr>

                    <div class="small fw-semibold mb-2">
                        Contas sem 2FA
                    </div>

                    <div class="list-group list-group-flush">
                        <?php foreach ((array)$twoFactor['without_2fa'] as $userWithout2fa): ?>
                            <div class="list-group-item px-0">
                                <div class="fw-semibold">
                                    <?= e((string)$userWithout2fa['nome']) ?>
                                </div>

                                <div class="small text-secondary">
                                    <?= e((string)$userWithout2fa['email']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-3 py-3">
        <div>
            <div class="fw-semibold">
                Auditoria de segurança
            </div>

            <div class="small text-secondary">
                Ações sensíveis, acessos negados e alterações relacionadas à segurança.
            </div>
        </div>

        <a
            class="btn btn-sm btn-outline-secondary"
            href="<?= e(url('admin/auditoria/index.php')) ?>"
        >
            Auditoria completa
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Nível</th>
                    <th>Usuário</th>
                    <th>Ação</th>
                    <th>Detalhes</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$audit): ?>
                    <tr>
                        <td
                            colspan="5"
                            class="text-center text-secondary py-4"
                        >
                            Nenhum evento de segurança encontrado.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($audit as $entry): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= e(
                                formatDateBr(
                                    (string)$entry['created_at']
                                )
                            ) ?>
                        </td>

                        <td>
                            <span class="badge <?= e(
                                $levelClass(
                                    (string)($entry['nivel'] ?? 'info')
                                )
                            ) ?>">
                                <?= e((string)($entry['nivel'] ?? 'info')) ?>
                            </span>
                        </td>

                        <td>
                            <?= e(
                                (string)(
                                    $entry['usuario_nome']
                                    ?: 'Sistema/visitante'
                                )
                            ) ?>
                        </td>

                        <td>
                            <code>
                                <?= e((string)$entry['acao']) ?>
                            </code>
                        </td>

                        <td>
                            <?= !empty($entry['detalhes'])
                                ? e(
                                    portalExcerpt(
                                        (string)$entry['detalhes'],
                                        160
                                    )
                                )
                                : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
