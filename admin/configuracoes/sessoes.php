<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

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

SessionSecurityService::ensureSchema($pdo);
SessionSecurityService::validateAndTouch(
    $pdo,
    $actorUserId,
    $timeout
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

        if ($action === 'revoke_one') {
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
                    'seguranca.sessao.encerrar',
                    'user_sessions',
                    $sessionId,
                    'Sessão encerrada pelo administrador.'
                );

                Session::flash(
                    'success',
                    'Sessão encerrada.'
                );
            } else {
                Session::flash(
                    'error',
                    'Não foi possível encerrar a sessão. A sessão administrativa atual é protegida.'
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
                $targetUserId === $actorUserId;

            $count =
                SessionSecurityService::revokeAllForUser(
                    $pdo,
                    $targetUserId,
                    $actorUserId,
                    $keepCurrent
                );

            logAction(
                $pdo,
                'seguranca.sessoes.usuario.encerrar',
                'usuarios',
                $targetUserId,
                $count
                . ' sessão(ões) encerrada(s).'
            );

            Session::flash(
                'success',
                $count > 0
                    ? $count
                        . ' sessão(ões) do usuário encerrada(s).'
                    : 'Nenhuma outra sessão ativa foi encontrada.'
            );
        }
    }

    header(
        'Location: '
        . url('admin/configuracoes/sessoes.php')
    );
    exit;
}

$sessions =
    SessionSecurityService::allActiveSessions(
        $pdo,
        $timeout,
        300
    );

$userIds = [];

foreach ($sessions as $session) {
    $userIds[
        (int)$session['user_id']
    ] = true;
}

$pageTitle =
    'Sessões e Acessos';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Configurações · Segurança
        </div>

        <h1 class="h3 mb-1">
            Sessões e acessos
        </h1>

        <p class="text-secondary mb-0">
            Acompanhe os acessos administrativos ativos e encerre sessões remotamente.
        </p>
    </div>

    <a
        class="btn btn-outline-primary"
        href="<?= e(url('admin/minhas-sessoes.php')) ?>"
    >
        <i class="bi bi-person-lock me-1"></i>
        Minhas sessões
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Sessões ativas
                </div>

                <div class="display-6 fw-semibold">
                    <?= count($sessions) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Usuários conectados
                </div>

                <div class="display-6 fw-semibold">
                    <?= count($userIds) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">
                    Expiração por inatividade
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$timeout ?>
                    <span class="fs-6 fw-normal">
                        min
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <strong>Proteção da v0.83:</strong>
    cada login é registrado separadamente. Encerrar uma sessão aqui não altera a senha
    e não desconecta os demais acessos do usuário, a menos que você escolha encerrar
    todas as sessões daquele usuário.
</div>

<section class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Usuário</th>
                    <th>Dispositivo</th>
                    <th>IP</th>
                    <th>Início</th>
                    <th>Último acesso</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$sessions): ?>
                    <tr>
                        <td
                            colspan="6"
                            class="text-secondary py-4"
                        >
                            Nenhuma sessão administrativa ativa.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($sessions as $session): ?>
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
                                <?php if (!empty($session['perfil_nome'])): ?>
                                    ·
                                    <?= e(
                                        (string)$session['perfil_nome']
                                    ) ?>
                                <?php endif; ?>
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

                        <td>
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
                                    (string)$session['created_at']
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
                                        onsubmit="return confirm('Encerrar esta sessão?');"
                                    >
                                        <?= Csrf::field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="revoke_one"
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
                                    onsubmit="return confirm('Encerrar todas as sessões deste usuário<?= !empty($session['is_current']) ? ', exceto a atual' : '' ?>?');"
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

<?php require __DIR__ . '/../_footer.php'; ?>
