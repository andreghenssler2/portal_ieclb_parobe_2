<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo =
    Database::connection();

$userId =
    (int)Auth::id();

if (
    !class_exists(
        'AdminNotificationService'
    )
) {
    Session::flash(
        'error',
        'A Central de notificações ainda não está disponível.'
    );

    header(
        'Location: '
        . url('admin/index.php')
    );
    exit;
}

AdminNotificationService::ensureSchema(
    $pdo
);

AdminNotificationService::syncCurrentUser(
    $pdo,
    true
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

        header(
            'Location: '
            . url('admin/notificacoes.php')
        );
        exit;
    }

    $action =
        trim(
            (string)(
                $_POST['action']
                ?? ''
            )
        );

    $notificationId =
        max(
            0,
            (int)(
                $_POST['notification_id']
                ?? 0
            )
        );

    if (
        $action === 'mark_read'
        && $notificationId > 0
    ) {
        AdminNotificationService::markRead(
            $pdo,
            $userId,
            $notificationId
        );
    } elseif (
        $action === 'mark_unread'
        && $notificationId > 0
    ) {
        AdminNotificationService::markUnread(
            $pdo,
            $userId,
            $notificationId
        );
    } elseif (
        $action === 'mark_all_read'
    ) {
        $count =
            AdminNotificationService::markAllRead(
                $pdo,
                $userId
            );

        Session::flash(
            'success',
            $count > 0
                ? $count
                    . ' notificação(ões) marcada(s) como lida(s).'
                : 'Não havia notificações não lidas.'
        );
    } elseif (
        $action === 'delete_read_resolved'
    ) {
        $count =
            AdminNotificationService::deleteReadResolved(
                $pdo,
                $userId
            );

        Session::flash(
            'success',
            $count > 0
                ? $count
                    . ' notificação(ões) antiga(s) removida(s).'
                : 'Não havia notificações antigas para remover.'
        );
    } elseif (
        $action === 'open'
        && $notificationId > 0
    ) {
        $notification =
            AdminNotificationService::findForUser(
                $pdo,
                $userId,
                $notificationId
            );

        if ($notification) {
            AdminNotificationService::markRead(
                $pdo,
                $userId,
                $notificationId
            );

            $target =
                trim(
                    (string)(
                        $notification['target_url']
                        ?? ''
                    )
                );

            if (
                $target !== ''
                && !str_contains(
                    $target,
                    '://'
                )
            ) {
                header(
                    'Location: '
                    . url(
                        ltrim(
                            $target,
                            '/'
                        )
                    )
                );
                exit;
            }
        }
    }

    $filter =
        trim(
            (string)(
                $_POST['return_filter']
                ?? 'all'
            )
        );

    if (
        !in_array(
            $filter,
            [
                'all',
                'active',
                'unread',
            ],
            true
        )
    ) {
        $filter =
            'all';
    }

    header(
        'Location: '
        . url(
            'admin/notificacoes.php?filtro='
            . rawurlencode(
                $filter
            )
        )
    );
    exit;
}

$filter =
    trim(
        (string)(
            $_GET['filtro']
            ?? 'all'
        )
    );

if (
    !in_array(
        $filter,
        [
            'all',
            'active',
            'unread',
        ],
        true
    )
) {
    $filter =
        'all';
}

$notifications =
    AdminNotificationService::listForUser(
        $pdo,
        $userId,
        $filter,
        120
    );

$unread =
    AdminNotificationService::unreadCount(
        $pdo,
        $userId
    );

$active =
    AdminNotificationService::activeCount(
        $pdo,
        $userId
    );

$pageTitle =
    'Central de Notificações';

require __DIR__ . '/_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <div class="text-uppercase small text-secondary fw-semibold mb-1">
            Administração
        </div>

        <h1 class="h3 mb-1">
            Central de Notificações
        </h1>

        <p class="text-secondary mb-0">
            Alertas e informações importantes do painel, organizados para sua conta.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/pendencias.php')) ?>"
        >
            <i class="bi bi-clipboard-check me-1"></i>
            Central de Pendências
        </a>

        <form method="post">
            <?= Csrf::field() ?>

            <input
                type="hidden"
                name="action"
                value="mark_all_read"
            >

            <input
                type="hidden"
                name="return_filter"
                value="<?= e($filter) ?>"
            >

            <button class="btn btn-primary">
                <i class="bi bi-check2-all me-1"></i>
                Marcar todas como lidas
            </button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Não lidas
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$unread ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Ativas
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$active ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Exibidas neste filtro
                </div>

                <div class="display-6 fw-semibold">
                    <?= count($notifications) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
    <div class="btn-group">
        <a
            class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>"
            href="<?= e(url('admin/notificacoes.php?filtro=all')) ?>"
        >
            Todas
        </a>

        <a
            class="btn btn-sm <?= $filter === 'active' ? 'btn-primary' : 'btn-outline-primary' ?>"
            href="<?= e(url('admin/notificacoes.php?filtro=active')) ?>"
        >
            Ativas
        </a>

        <a
            class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary' ?>"
            href="<?= e(url('admin/notificacoes.php?filtro=unread')) ?>"
        >
            Não lidas
            <?php if ($unread > 0): ?>
                <span class="badge text-bg-light ms-1">
                    <?= (int)$unread ?>
                </span>
            <?php endif; ?>
        </a>
    </div>

    <form
        method="post"
        onsubmit="return confirm('Remover notificações antigas que já foram lidas e resolvidas?');"
    >
        <?= Csrf::field() ?>

        <input
            type="hidden"
            name="action"
            value="delete_read_resolved"
        >

        <input
            type="hidden"
            name="return_filter"
            value="<?= e($filter) ?>"
        >

        <button class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-trash3 me-1"></i>
            Limpar antigas
        </button>
    </form>
</div>

<section class="card border-0 shadow-sm overflow-hidden">
    <div class="list-group list-group-flush">
        <?php if (!$notifications): ?>
            <div class="list-group-item p-5 text-center">
                <i class="bi bi-bell-slash fs-1 text-secondary"></i>

                <div class="fw-semibold mt-3">
                    Nenhuma notificação neste filtro
                </div>

                <div class="text-secondary small">
                    Quando houver algo importante, aparecerá aqui.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($notifications as $notification): ?>
            <?php
            $isRead =
                (int)(
                    $notification['is_read']
                    ?? 0
                ) === 1;

            $resolved =
                !empty(
                    $notification['resolved_at']
                );

            $level =
                in_array(
                    (string)($notification['level'] ?? ''),
                    [
                        'primary',
                        'secondary',
                        'success',
                        'danger',
                        'warning',
                        'info',
                    ],
                    true
                )
                    ? (string)$notification['level']
                    : 'primary';
            ?>

            <div class="list-group-item p-4 <?= !$isRead && !$resolved ? 'bg-primary-subtle bg-opacity-10' : '' ?>">
                <div class="d-flex flex-column flex-lg-row gap-3">
                    <div
                        class="rounded-circle bg-<?= e($level) ?>-subtle text-<?= e($level) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                        style="width:46px;height:46px"
                    >
                        <i class="bi <?= e((string)($notification['icon'] ?: 'bi-bell')) ?> fs-5"></i>
                    </div>

                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="fw-semibold">
                                <?= e((string)$notification['title']) ?>
                            </div>

                            <?php if (!$isRead && !$resolved): ?>
                                <span class="badge text-bg-primary">
                                    Nova
                                </span>
                            <?php endif; ?>

                            <?php if ($resolved): ?>
                                <span class="badge text-bg-success">
                                    Resolvida
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($notification['message'])): ?>
                            <div class="text-secondary mt-1">
                                <?= e((string)$notification['message']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="small text-secondary mt-2">
                            Atualizada em
                            <?= e(formatDateBr((string)$notification['updated_at'])) ?>

                            <?php if ($isRead && !empty($notification['read_at'])): ?>
                                · lida em
                                <?= e(formatDateBr((string)$notification['read_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <?php if (
                            !$resolved
                            && !empty($notification['target_url'])
                        ): ?>
                            <form method="post">
                                <?= Csrf::field() ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="open"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= (int)$notification['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_filter"
                                    value="<?= e($filter) ?>"
                                >

                                <button class="btn btn-sm btn-outline-primary">
                                    Abrir
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$isRead): ?>
                            <form method="post">
                                <?= Csrf::field() ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="mark_read"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= (int)$notification['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_filter"
                                    value="<?= e($filter) ?>"
                                >

                                <button class="btn btn-sm btn-outline-secondary">
                                    Marcar como lida
                                </button>
                            </form>
                        <?php elseif (!$resolved): ?>
                            <form method="post">
                                <?= Csrf::field() ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="mark_unread"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= (int)$notification['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_filter"
                                    value="<?= e($filter) ?>"
                                >

                                <button class="btn btn-sm btn-link text-decoration-none">
                                    Marcar como não lida
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
