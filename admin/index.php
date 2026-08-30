<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../app/Services/AdminDashboardService.php';

Auth::requireLogin();

$pdo = Database::connection();

$dashboardService =
    new AdminDashboardService($pdo);

$dashboard =
    $dashboardService->build();

$profile =
    $dashboard['profile'] ?? [];

$maintenance =
    maintenanceSettings($pdo);

$pendingOverview = [
    'total' => 0,
    'items' => [],
];

try {
    if (class_exists('AdminPendingService')) {
        $pendingOverview =
            AdminPendingService::overview($pdo);
    }
} catch (Throwable $ignored) {
}

$hour = (int)date('G');

$greeting =
    $hour < 12
        ? 'Bom dia'
        : (
            $hour < 18
                ? 'Boa tarde'
                : 'Boa noite'
        );

$pageTitle = 'Dashboard';

require __DIR__ . '/_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            <?= e(
                (string)(
                    $profile['profile_name']
                    ?: 'Painel administrativo'
                )
            ) ?>
        </div>

        <h1 class="h3 mb-1">
            <?= e($greeting) ?>,
            <?= e(
                (string)(
                    $profile['name']
                    ?: 'usuário'
                )
            ) ?>.
        </h1>

        <p class="text-secondary mb-0">
            Seu painel mostra apenas módulos e ações liberados para o seu perfil.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/pendencias.php')) ?>"
        >
            <i class="bi bi-bell me-1"></i>
            Pendências

            <?php if ((int)($pendingOverview['total'] ?? 0) > 0): ?>
                <span class="badge text-bg-danger ms-1">
                    <?= (int)$pendingOverview['total'] ?>
                </span>
            <?php endif; ?>
        </a>

        <a
            class="btn btn-outline-secondary"
            target="_blank"
            href="<?= e(url('')) ?>"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            Ver portal
        </a>
    </div>
</div>

<?php if ($maintenance['enabled']): ?>
    <div class="alert alert-warning d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <i class="bi bi-cone-striped me-2"></i>
            <strong>Modo manutenção está ativo.</strong>
            O portal público responde com HTTP 503 para visitantes.
        </div>

        <?php if (Auth::can('manutencao.gerenciar')): ?>
            <a
                class="btn btn-sm btn-warning"
                href="<?= e(url('admin/ferramentas/manutencao.php')) ?>"
            >
                Gerenciar manutenção
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pendingOverview['items'])): ?>
    <section class="card border-0 shadow-sm mb-4 overflow-hidden">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
                <div>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span
                            class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center flex-shrink-0"
                            style="width:46px;height:46px"
                        >
                            <i class="bi bi-bell-fill fs-5"></i>
                        </span>

                        <div>
                            <div class="small text-uppercase text-secondary fw-semibold">
                                Prioridades
                            </div>

                            <div class="h4 mb-0">
                                <?= (int)$pendingOverview['total'] ?>
                                item(ns) aguardando atenção
                            </div>
                        </div>
                    </div>

                    <div class="text-secondary">
                        Comece pelos itens mais importantes do seu perfil.
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php foreach (
                        array_slice(
                            (array)$pendingOverview['items'],
                            0,
                            4
                        )
                        as $pendingItem
                    ): ?>
                        <a
                            class="btn btn-sm btn-outline-<?= e((string)$pendingItem['class']) ?>"
                            href="<?= e(url((string)$pendingItem['url'])) ?>"
                        >
                            <?= e((string)$pendingItem['label']) ?>

                            <span class="badge text-bg-<?= e((string)$pendingItem['class']) ?> ms-1">
                                <?= (int)$pendingItem['count'] ?>
                            </span>
                        </a>
                    <?php endforeach; ?>

                    <a
                        class="btn btn-primary btn-sm"
                        href="<?= e(url('admin/pendencias.php')) ?>"
                    >
                        Ver Central
                    </a>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="alert alert-success d-flex align-items-start gap-3 mb-4">
        <i class="bi bi-check-circle-fill fs-5"></i>

        <div>
            <strong>Sem pendências para o seu perfil.</strong>
            Os módulos aos quais você tem acesso não possuem itens urgentes no momento.
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($dashboard['quick_actions'])): ?>
    <section class="mb-4">
        <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1">
                    Atalhos do seu perfil
                </h2>

                <div class="text-secondary small">
                    Ações que você pode executar agora.
                </div>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($dashboard['quick_actions'] as $action): ?>
                <div class="col-sm-6 col-xl-3">
                    <a
                        class="card border-0 shadow-sm h-100 text-decoration-none text-reset"
                        href="<?= e(url((string)$action['url'])) ?>"
                    >
                        <div class="card-body d-flex gap-3 align-items-start">
                            <span
                                class="rounded-circle bg-<?= e((string)$action['class']) ?>-subtle text-<?= e((string)$action['class']) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:44px;height:44px"
                            >
                                <i class="bi <?= e((string)$action['icon']) ?> fs-5"></i>
                            </span>

                            <div>
                                <div class="fw-semibold mb-1">
                                    <?= e((string)$action['label']) ?>
                                </div>

                                <div class="small text-secondary">
                                    <?= e((string)$action['description']) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($dashboard['summary'])): ?>
    <section class="mb-4">
        <div class="mb-3">
            <h2 class="h5 mb-1">
                Visão geral
            </h2>

            <div class="text-secondary small">
                Indicadores dos módulos liberados para você.
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($dashboard['summary'] as $card): ?>
                <div class="col-sm-6 col-lg-4 col-xxl-3">
                    <a
                        class="card border-0 shadow-sm h-100 text-decoration-none text-reset"
                        href="<?= e(url((string)$card['url'])) ?>"
                    >
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="text-secondary small mb-2">
                                        <?= e((string)$card['label']) ?>
                                    </div>

                                    <div class="display-6 fw-semibold lh-1">
                                        <?= (int)$card['value'] ?>
                                    </div>
                                </div>

                                <span
                                    class="rounded-circle bg-<?= e((string)$card['class']) ?>-subtle text-<?= e((string)$card['class']) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:42px;height:42px"
                                >
                                    <i class="bi <?= e((string)$card['icon']) ?>"></i>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="row g-4">
    <?php if (!empty($dashboard['news'])): ?>
        <div class="col-xl-7">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="fw-semibold">
                            Conteúdo recente
                        </div>

                        <div class="small text-secondary">
                            Últimas notícias alteradas ou publicadas.
                        </div>
                    </div>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/noticias/index.php')) ?>"
                    >
                        Todas as notícias
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dashboard['news'] as $post): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?= e((string)$post['titulo']) ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-secondary">
                                            <?= e((string)$post['status']) ?>
                                        </span>

                                        <?php if (
                                            !empty($post['workflow_status'])
                                            && (string)$post['workflow_status'] !== 'publicado'
                                            && class_exists('EditorialWorkflowService')
                                        ): ?>
                                            <div class="mt-1">
                                                <span
                                                    class="badge text-bg-<?= e(
                                                        EditorialWorkflowService::badgeClass(
                                                            (string)$post['workflow_status']
                                                        )
                                                    ) ?>"
                                                >
                                                    <?= e(
                                                        EditorialWorkflowService::label(
                                                            (string)$post['workflow_status']
                                                        )
                                                    ) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= e(
                                            formatDateBr(
                                                $post['publicado_em']
                                                ?: $post['updated_at']
                                                ?: $post['created_at']
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <a
                                            class="btn btn-sm btn-outline-secondary"
                                            href="<?= e(
                                                url(
                                                    'admin/noticias/form.php?id='
                                                    . (int)$post['id']
                                                )
                                            ) ?>"
                                        >
                                            Abrir
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboard['events'])): ?>
        <div class="col-xl-5">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="fw-semibold">
                            Próximos eventos e cultos
                        </div>

                        <div class="small text-secondary">
                            Agenda futura publicada.
                        </div>
                    </div>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/eventos/index.php')) ?>"
                    >
                        Ver agenda
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($dashboard['events'] as $event): ?>
                        <a
                            class="list-group-item list-group-item-action"
                            href="<?= e(
                                url(
                                    'admin/eventos/form.php?id='
                                    . (int)$event['id']
                                )
                            ) ?>"
                        >
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <div class="fw-semibold">
                                        <?= e((string)$event['titulo']) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= e((string)($event['comunidade_nome'] ?: $event['tipo'])) ?>

                                        <?php if (!empty($event['santa_ceia'])): ?>
                                            · Santa Ceia
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="small text-secondary text-nowrap">
                                    <?= e(formatDateBr($event['data_inicio'])) ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboard['comments'])): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Comentários pendentes
                        </div>

                        <div class="small text-secondary">
                            Mensagens aguardando moderação.
                        </div>
                    </div>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/comentarios/index.php?status=pendente')) ?>"
                    >
                        Moderar
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($dashboard['comments'] as $comment): ?>
                        <a
                            class="list-group-item list-group-item-action"
                            href="<?= e(url('admin/comentarios/index.php?status=pendente')) ?>"
                        >
                            <div class="fw-semibold">
                                <?= e((string)$comment['autor_nome']) ?>

                                <span class="fw-normal text-secondary">
                                    em <?= e((string)$comment['post_titulo']) ?>
                                </span>
                            </div>

                            <div class="small text-secondary">
                                <?= e(
                                    portalExcerpt(
                                        (string)$comment['conteudo'],
                                        130
                                    )
                                ) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboard['forms'])): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Respostas novas
                        </div>

                        <div class="small text-secondary">
                            Formulários que receberam respostas recentes.
                        </div>
                    </div>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/formularios/index.php')) ?>"
                    >
                        Formulários
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($dashboard['forms'] as $response): ?>
                        <a
                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
                            href="<?= e(
                                url(
                                    'admin/formularios/respostas.php?id='
                                    . (int)$response['formulario_id']
                                )
                            ) ?>"
                        >
                            <div>
                                <div class="fw-semibold">
                                    <?= e((string)$response['formulario_titulo']) ?>
                                </div>

                                <small class="text-secondary">
                                    Nova resposta recebida
                                </small>
                            </div>

                            <small class="text-secondary text-nowrap">
                                <?= e(formatDateBr($response['created_at'])) ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboard['audit'])): ?>
        <div class="col-12">
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Atividade administrativa
                        </div>

                        <div class="small text-secondary">
                            Últimas ações registradas na auditoria.
                        </div>
                    </div>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/auditoria/index.php')) ?>"
                    >
                        Abrir auditoria
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Ação</th>
                                <th>Usuário</th>
                                <th>Nível</th>
                                <th>Data</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dashboard['audit'] as $log): ?>
                                <?php
                                $level =
                                    (string)($log['nivel'] ?? 'info');

                                $levelClass = match ($level) {
                                    'critical' => 'danger',
                                    'warning' => 'warning',
                                    default => 'secondary',
                                };
                                ?>

                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <?= e((string)$log['acao']) ?>
                                        </div>

                                        <?php if (!empty($log['detalhes'])): ?>
                                            <div class="small text-secondary">
                                                <?= e(
                                                    portalExcerpt(
                                                        (string)$log['detalhes'],
                                                        110
                                                    )
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            (string)(
                                                $log['usuario_nome']
                                                ?: 'Sistema'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= e($levelClass) ?>">
                                            <?= e($level) ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= e(formatDateBr($log['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php if (
    empty($dashboard['summary'])
    && empty($dashboard['quick_actions'])
): ?>
    <div class="alert alert-light border mt-4">
        Seu perfil ainda não possui módulos administrativos liberados.
        Você pode acessar sua conta ou visualizar o portal público.
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
