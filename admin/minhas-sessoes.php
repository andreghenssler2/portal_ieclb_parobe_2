<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo =
    Database::connection();

$userId =
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
    $userId,
    $timeout
);

if (!Auth::check()) {
    header(
        'Location: '
        . url('admin/login.php')
    );
    exit;
}

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
                    $userId,
                    $userId
                );

            if ($ok) {
                logAction(
                    $pdo,
                    'sessao.encerrar',
                    'user_sessions',
                    $sessionId,
                    'Usuário encerrou uma de suas outras sessões.'
                );

                Session::flash(
                    'success',
                    'Sessão encerrada.'
                );
            } else {
                Session::flash(
                    'error',
                    'Não foi possível encerrar essa sessão. A sessão atual não pode ser encerrada por esta tela.'
                );
            }
        } elseif ($action === 'revoke_others') {
            $count =
                SessionSecurityService::revokeOtherSessions(
                    $pdo,
                    $userId,
                    $userId,
                    'usuario_encerrar_outras'
                );

            logAction(
                $pdo,
                'sessao.encerrar_outras',
                'user_sessions',
                null,
                $count
                . ' outra(s) sessão(ões) encerrada(s).'
            );

            Session::flash(
                'success',
                $count > 0
                    ? $count
                        . ' outra(s) sessão(ões) encerrada(s).'
                    : 'Não havia outras sessões ativas.'
            );
        }
    }

    header(
        'Location: '
        . url('admin/minhas-sessoes.php')
    );
    exit;
}

$sessions =
    SessionSecurityService::sessionsForUser(
        $pdo,
        $userId,
        $timeout,
        50
    );

$active = [];
$recent = [];

foreach ($sessions as $session) {
    if (empty($session['revoked_at'])) {
        $active[] = $session;
    } else {
        $recent[] = $session;
    }
}

$recent =
    array_slice(
        $recent,
        0,
        10
    );

$pageTitle =
    'Minhas sessões';

require __DIR__ . '/_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Minhas sessões
        </h1>

        <p class="text-secondary mb-0">
            Veja onde sua conta está conectada e encerre acessos que você não reconhece.
        </p>
    </div>

    <form
        method="post"
        onsubmit="return confirm('Encerrar todas as outras sessões da sua conta?');"
    >
        <?= Csrf::field() ?>

        <input
            type="hidden"
            name="action"
            value="revoke_others"
        >

        <button class="btn btn-outline-danger">
            <i class="bi bi-shield-x me-1"></i>
            Encerrar todas as outras sessões
        </button>
    </form>
</div>

<div class="alert alert-info d-flex gap-3 align-items-start">
    <i class="bi bi-shield-check fs-4"></i>

    <div>
        <strong>A sessão atual fica protegida.</strong>
        Ao encerrar outra sessão, aquele dispositivo será desconectado na próxima requisição ao Portal.
        O identificador da sessão PHP também é renovado periodicamente.
    </div>
</div>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div>
            <div class="fw-semibold">
                Sessões ativas
            </div>

            <div class="small text-secondary">
                <?= count($active) ?> sessão(ões)
            </div>
        </div>
    </div>

    <div class="list-group list-group-flush">
        <?php if (!$active): ?>
            <div class="list-group-item py-4 text-secondary">
                Nenhuma sessão ativa encontrada.
            </div>
        <?php endif; ?>

        <?php foreach ($active as $session): ?>
            <div class="list-group-item p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div>
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <strong>
                                <?= e(
                                    (string)(
                                        $session['device_label']
                                        ?? 'Dispositivo'
                                    )
                                ) ?>
                            </strong>

                            <?php if (!empty($session['is_current'])): ?>
                                <span class="badge text-bg-success">
                                    Sessão atual
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="small text-secondary">
                            IP:
                            <?= e(
                                (string)(
                                    $session['ip']
                                    ?: 'não disponível'
                                )
                            ) ?>
                        </div>

                        <div class="small text-secondary">
                            Iniciada:
                            <?= e(
                                formatDateBr(
                                    (string)$session['created_at']
                                )
                            ) ?>
                            · último acesso:
                            <?= e(
                                formatDateBr(
                                    (string)$session['last_seen_at']
                                )
                            ) ?>
                        </div>
                    </div>

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
                                Encerrar sessão
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="small text-success fw-semibold">
                            <i class="bi bi-check-circle me-1"></i>
                            Você está usando este acesso
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($recent): ?>
    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="fw-semibold">
                Encerradas recentemente
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Dispositivo</th>
                        <th>IP</th>
                        <th>Último acesso</th>
                        <th>Motivo</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($recent as $session): ?>
                        <tr>
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

                            <td>
                                <?= e(
                                    formatDateBr(
                                        (string)$session['last_seen_at']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string)(
                                        $session['revoke_reason']
                                        ?: 'encerrada'
                                    )
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
