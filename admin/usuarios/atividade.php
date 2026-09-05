<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();

$pdo =
    Database::connection();

if (
    !class_exists(
        'UserActivityService'
    )
) {
    $fallbackFiles = [
        __DIR__
        . '/../../app/Services/UserActivityService.php',

        __DIR__
        . '/../../app/Services/UserActivityServiceV87R2.php',
    ];

    foreach ($fallbackFiles as $fallback) {
        if (
            is_file($fallback)
            && !class_exists('UserActivityService')
        ) {
            require_once $fallback;
        }
    }
}

if (
    !class_exists(
        'UserActivityService'
    )
) {
    Session::flash(
        'error',
        'O histórico de atividades ainda não está disponível.'
    );

    header(
        'Location: '
        . url('admin/index.php')
    );
    exit;
}

$currentUserId =
    (int)Auth::id();

$targetUserId =
    max(
        0,
        (int)(
            $_GET['id']
            ?? $currentUserId
        )
    );

if ($targetUserId <= 0) {
    $targetUserId =
        $currentUserId;
}

$canViewOthers =
    Auth::can(
        'auditoria.visualizar'
    )
    || Auth::can(
        'usuarios.gerenciar'
    )
    || Auth::isAdmin();

if (
    $targetUserId !== $currentUserId
    && !$canViewOthers
) {
    http_response_code(403);

    exit(
        'Você não possui permissão para visualizar a atividade deste usuário.'
    );
}

$service =
    new UserActivityService(
        $pdo
    );

$targetUser =
    $service->user(
        $targetUserId
    );

if (!$targetUser) {
    http_response_code(404);

    exit(
        'Usuário não encontrado.'
    );
}

$filters = [
    'q' =>
        trim(
            (string)(
                $_GET['q']
                ?? ''
            )
        ),

    'categoria' =>
        trim(
            (string)(
                $_GET['categoria']
                ?? ''
            )
        ),

    'nivel' =>
        trim(
            (string)(
                $_GET['nivel']
                ?? ''
            )
        ),

    'data_de' =>
        trim(
            (string)(
                $_GET['data_de']
                ?? ''
            )
        ),

    'data_ate' =>
        trim(
            (string)(
                $_GET['data_ate']
                ?? ''
            )
        ),
];

$page =
    max(
        1,
        (int)(
            $_GET['page']
            ?? 1
        )
    );

$timeline =
    $service->timeline(
        $targetUserId,
        $filters,
        $page,
        40
    );

$summary =
    $service->summary(
        $targetUserId
    );

$users =
    $canViewOthers
        ? $service->users()
        : [];

$categories =
    UserActivityService::categories();

$pageTitle =
    'Atividade de '
    . (string)$targetUser['nome'];

require __DIR__ . '/../_header.php';

$levelClass =
    static function (
        string $level
    ): string {
        return
            match ($level) {
                'critical' =>
                    'danger',
                'warning' =>
                    'warning',
                default =>
                    'secondary',
            };
    };

$queryWithoutPage =
    $_GET;

unset(
    $queryWithoutPage['page']
);
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="text-uppercase small text-secondary fw-semibold mb-1">
            Usuários · Histórico
        </div>

        <h1 class="h3 mb-1">
            Atividade de
            <?= e((string)$targetUser['nome']) ?>
        </h1>

        <div class="text-secondary">
            <?= e((string)$targetUser['email']) ?>

            <?php if (!empty($targetUser['perfil_nome'])): ?>
                ·
                <?= e((string)$targetUser['perfil_nome']) ?>
            <?php endif; ?>

            ·
            <span class="<?= (int)$targetUser['ativo'] === 1 ? 'text-success' : 'text-secondary' ?>">
                <?= (int)$targetUser['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
            </span>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if (
            $canViewOthers
            && Auth::can(
                'usuarios.gerenciar'
            )
        ): ?>
            <a
                class="btn btn-outline-secondary"
                href="<?= e(
                    url(
                        'admin/usuarios/form.php?id='
                        . $targetUserId
                    )
                ) ?>"
            >
                <i class="bi bi-person-gear me-1"></i>
                Editar usuário
            </a>
        <?php endif; ?>

        <?php if (
            Auth::can(
                'auditoria.visualizar'
            )
        ): ?>
            <a
                class="btn btn-outline-primary"
                href="<?= e(
                    url(
                        'admin/auditoria/index.php?usuario_id='
                        . $targetUserId
                    )
                ) ?>"
            >
                <i class="bi bi-journal-text me-1"></i>
                Auditoria completa
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($canViewOthers): ?>
    <form
        method="get"
        class="card border-0 shadow-sm mb-4"
    >
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-8">
                    <label class="form-label">
                        Visualizar outro usuário
                    </label>

                    <select
                        class="form-select"
                        name="id"
                        onchange="this.form.submit()"
                    >
                        <?php foreach ($users as $userOption): ?>
                            <option
                                value="<?= (int)$userOption['id'] ?>"
                                <?= (int)$userOption['id'] === $targetUserId ? 'selected' : '' ?>
                            >
                                <?= e((string)$userOption['nome']) ?>
                                ·
                                <?= e((string)$userOption['email']) ?>
                                <?php if (!empty($userOption['perfil_nome'])): ?>
                                    ·
                                    <?= e((string)$userOption['perfil_nome']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4">
                    <div class="small text-secondary">
                        A troca de usuário mantém esta tela separada da Auditoria geral,
                        facilitando a análise cronológica de uma conta específica.
                    </div>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Hoje
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['today'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Últimos 30 dias
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['last_30_days'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Alertas · 30d
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['warnings_30'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Críticos · 30d
                </div>

                <div class="display-6 fw-semibold text-danger">
                    <?= (int)$summary['critical_30'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Sessões ativas
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['active_sessions'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Total auditado
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['total_logs'] ?>
                </div>
            </div>
        </div>
    </div>
</div>

<form
    method="get"
    class="card border-0 shadow-sm mb-4"
>
    <div class="card-body">
        <input
            type="hidden"
            name="id"
            value="<?= $targetUserId ?>"
        >

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label">
                    Buscar na atividade
                </label>

                <input
                    class="form-control"
                    name="q"
                    value="<?= e((string)$filters['q']) ?>"
                    placeholder="Ação, detalhes, IP ou rota"
                >
            </div>

            <div class="col-md-6 col-lg-2">
                <label class="form-label">
                    Categoria
                </label>

                <select
                    class="form-select"
                    name="categoria"
                >
                    <?php foreach ($categories as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $filters['categoria'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-2">
                <label class="form-label">
                    Nível
                </label>

                <select
                    class="form-select"
                    name="nivel"
                >
                    <option value="">
                        Todos
                    </option>

                    <option
                        value="info"
                        <?= $filters['nivel'] === 'info' ? 'selected' : '' ?>
                    >
                        Info
                    </option>

                    <option
                        value="warning"
                        <?= $filters['nivel'] === 'warning' ? 'selected' : '' ?>
                    >
                        Alerta
                    </option>

                    <option
                        value="critical"
                        <?= $filters['nivel'] === 'critical' ? 'selected' : '' ?>
                    >
                        Crítico
                    </option>
                </select>
            </div>

            <div class="col-md-6 col-lg-2">
                <label class="form-label">
                    De
                </label>

                <input
                    class="form-control"
                    type="date"
                    name="data_de"
                    value="<?= e((string)$filters['data_de']) ?>"
                >
            </div>

            <div class="col-md-6 col-lg-2">
                <label class="form-label">
                    Até
                </label>

                <input
                    class="form-control"
                    type="date"
                    name="data_ate"
                    value="<?= e((string)$filters['data_ate']) ?>"
                >
            </div>

            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i>
                    Filtrar
                </button>

                <a
                    class="btn btn-outline-secondary"
                    href="<?= e(
                        url(
                            'admin/usuarios/atividade.php?id='
                            . $targetUserId
                        )
                    ) ?>"
                >
                    Limpar filtros
                </a>
            </div>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center gap-3 mb-3">
    <div>
        <strong>
            <?= number_format(
                (int)$timeline['total'],
                0,
                ',',
                '.'
            ) ?>
            evento(s)
        </strong>

        <span class="text-secondary">
            na linha do tempo
        </span>
    </div>

    <?php if (!empty($summary['last_activity'])): ?>
        <small class="text-secondary">
            Última atividade:
            <?= e(
                formatDateBr(
                    (string)$summary['last_activity']
                )
            ) ?>
        </small>
    <?php endif; ?>
</div>

<section class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$timeline['items']): ?>
            <div class="p-5 text-center">
                <i class="bi bi-clock-history fs-1 text-secondary"></i>

                <h2 class="h5 mt-3">
                    Nenhuma atividade encontrada
                </h2>

                <p class="text-secondary mb-0">
                    Tente remover algum filtro ou escolher outro período.
                </p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($timeline['items'] as $item): ?>
                    <?php
                    $level =
                        (string)(
                            $item['level']
                            ?? 'info'
                        );

                    $badge =
                        $levelClass(
                            $level
                        );
                    ?>

                    <div class="list-group-item p-3 p-lg-4">
                        <div class="d-flex gap-3 align-items-start">
                            <span
                                class="rounded-circle bg-<?= e($badge) ?>-subtle text-<?= e($badge) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:46px;height:46px"
                            >
                                <i class="bi <?= e((string)$item['icon']) ?>"></i>
                            </span>

                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <div class="fw-semibold">
                                        <?= e((string)$item['title']) ?>
                                    </div>

                                    <span class="badge text-bg-light border">
                                        <?= e((string)$item['category_label']) ?>
                                    </span>

                                    <?php if ($level !== 'info'): ?>
                                        <span class="badge text-bg-<?= e($badge) ?>">
                                            <?= e($level) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="small text-secondary mt-1">
                                    <?= e(
                                        date(
                                            'd/m/Y H:i:s',
                                            strtotime(
                                                (string)$item['created_at']
                                            )
                                        )
                                    ) ?>

                                    <?php if (!empty($item['action'])): ?>
                                        ·
                                        <code>
                                            <?= e((string)$item['action']) ?>
                                        </code>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($item['details'])): ?>
                                    <div class="mt-2">
                                        <?= e(
                                            portalExcerpt(
                                                (string)$item['details'],
                                                300
                                            )
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (
                                    !empty($item['entity'])
                                    || !empty($item['ip'])
                                    || !empty($item['route'])
                                    || !empty($item['request_id'])
                                    || !empty($item['user_agent'])
                                ): ?>
                                    <details class="mt-2">
                                        <summary class="small text-secondary">
                                            Dados técnicos
                                        </summary>

                                        <div class="small mt-2 text-secondary">
                                            <?php if (!empty($item['entity'])): ?>
                                                <div>
                                                    <strong>Entidade:</strong>
                                                    <?= e((string)$item['entity']) ?>
                                                    <?php if ((int)$item['entity_id'] > 0): ?>
                                                        #<?= (int)$item['entity_id'] ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['route'])): ?>
                                                <div>
                                                    <strong>Rota:</strong>
                                                    <?= e((string)$item['route']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['ip'])): ?>
                                                <div>
                                                    <strong>IP:</strong>
                                                    <?= e((string)$item['ip']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['request_id'])): ?>
                                                <div>
                                                    <strong>Request:</strong>
                                                    <?= e((string)$item['request_id']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['user_agent'])): ?>
                                                <div>
                                                    <strong>Navegador:</strong>
                                                    <?= e((string)$item['user_agent']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <strong>Fonte:</strong>
                                                <?= $item['source'] === 'session'
                                                    ? 'Sessões v0.83'
                                                    : 'Auditoria' ?>
                                            </div>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ((int)$timeline['pages'] > 1): ?>
    <nav
        class="mt-4"
        aria-label="Paginação da atividade"
    >
        <ul class="pagination flex-wrap">
            <?php
            $start =
                max(
                    1,
                    (int)$timeline['page'] - 2
                );

            $end =
                min(
                    (int)$timeline['pages'],
                    (int)$timeline['page'] + 2
                );
            ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <?php
                $query =
                    $queryWithoutPage;

                $query['id'] =
                    $targetUserId;

                $query['page'] =
                    $i;
                ?>

                <li
                    class="page-item <?= $i === (int)$timeline['page'] ? 'active' : '' ?>"
                >
                    <a
                        class="page-link"
                        href="?<?= e(http_build_query($query)) ?>"
                    >
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../_footer.php'; ?>
