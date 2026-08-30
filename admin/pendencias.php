<?php

require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo = Database::connection();

$overview =
    AdminPendingService::overview($pdo);

$editorial =
    AdminPendingService::editorialQueue($pdo);

$comments =
    AdminPendingService::pendingComments($pdo);

$formResponses =
    AdminPendingService::newFormResponses($pdo);

$security =
    AdminPendingService::securityAlerts($pdo);

$scheduled =
    AdminPendingService::scheduledPosts($pdo);

$pageTitle = 'Central de Pendências';

require __DIR__ . '/_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <div class="text-uppercase small text-secondary fw-semibold mb-1">
            Administração
        </div>

        <h1 class="h3 mb-1">
            Central de Pendências
        </h1>

        <p class="text-secondary mb-0">
            Tudo que merece atenção no painel, reunido em uma única tela.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/index.php')) ?>"
    >
        <i class="bi bi-speedometer2 me-1"></i>
        Voltar ao Dashboard
    </a>
</div>

<?php if ((int)$overview['total'] === 0): ?>
    <div class="alert alert-success d-flex align-items-start gap-3 shadow-sm">
        <i class="bi bi-check-circle-fill fs-3"></i>

        <div>
            <div class="fw-semibold fs-5">
                Nenhuma pendência encontrada
            </div>

            <div>
                Não há itens pendentes nos módulos aos quais seu perfil possui acesso.
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-primary d-flex align-items-start gap-3 shadow-sm">
        <i class="bi bi-bell-fill fs-3"></i>

        <div>
            <div class="fw-semibold fs-5">
                <?= (int)$overview['total'] ?>
                item(ns) aguardando atenção
            </div>

            <div>
                A contagem respeita as permissões do seu perfil.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($overview['items']): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($overview['items'] as $item): ?>
            <div class="col-sm-6 col-xl-4">
                <a
                    class="card border-0 shadow-sm h-100 text-decoration-none text-reset"
                    href="<?= e(url((string)$item['url'])) ?>"
                >
                    <div class="card-body d-flex gap-3 align-items-start">
                        <div
                            class="rounded-circle bg-<?= e((string)$item['class']) ?>-subtle text-<?= e((string)$item['class']) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                            style="width:44px;height:44px"
                        >
                            <i class="bi <?= e((string)$item['icon']) ?> fs-5"></i>
                        </div>

                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="fw-semibold">
                                    <?= e((string)$item['label']) ?>
                                </div>

                                <span class="badge text-bg-<?= e((string)$item['class']) ?>">
                                    <?= (int)$item['count'] ?>
                                </span>
                            </div>

                            <div class="small text-secondary mt-1">
                                <?= e((string)$item['description']) ?>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($editorial): ?>
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">
                    Fluxo editorial
                </div>

                <div class="small text-secondary">
                    Revisões, ajustes e conteúdos aprovados.
                </div>
            </div>

            <a
                class="btn btn-sm btn-outline-primary"
                href="<?= e(url('admin/noticias/revisao.php')) ?>"
            >
                Abrir fila de revisão
            </a>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Notícia</th>
                        <th>Autor</th>
                        <th>Etapa</th>
                        <th>Atualização</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($editorial as $post): ?>
                        <?php
                        $workflow =
                            (string)($post['workflow_status'] ?? 'rascunho');
                        ?>

                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    <?= e((string)$post['titulo']) ?>
                                </div>

                                <?php if (!empty($post['workflow_observacao'])): ?>
                                    <div class="small text-secondary">
                                        <?= e(
                                            portalExcerpt(
                                                (string)$post['workflow_observacao'],
                                                110
                                            )
                                        ) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e((string)($post['autor_nome'] ?: 'Usuário')) ?>
                            </td>

                            <td>
                                <span
                                    class="badge text-bg-<?= e(
                                        EditorialWorkflowService::badgeClass(
                                            $workflow
                                        )
                                    ) ?>"
                                >
                                    <?= e(
                                        EditorialWorkflowService::label(
                                            $workflow
                                        )
                                    ) ?>
                                </span>
                            </td>

                            <td class="text-nowrap">
                                <?= e(
                                    formatDateBr(
                                        $post['workflow_revisado_em']
                                        ?: $post['workflow_enviado_em']
                                        ?: $post['updated_at']
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
<?php endif; ?>

<div class="row g-4">
    <?php if ($comments): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        Comentários pendentes
                    </span>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/comentarios/index.php?status=pendente')) ?>"
                    >
                        Moderar
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($comments as $comment): ?>
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

    <?php if ($formResponses): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        Respostas novas
                    </span>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/formularios/index.php')) ?>"
                    >
                        Formulários
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($formResponses as $response): ?>
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

    <?php if ($scheduled): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        Próximas notícias agendadas
                    </span>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/noticias/index.php?status=agenda')) ?>"
                    >
                        Ver agenda
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($scheduled as $post): ?>
                        <a
                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
                            href="<?= e(
                                url(
                                    'admin/noticias/form.php?id='
                                    . (int)$post['id']
                                )
                            ) ?>"
                        >
                            <div class="fw-semibold">
                                <?= e((string)$post['titulo']) ?>
                            </div>

                            <small class="text-secondary text-nowrap">
                                <?= e(formatDateBr($post['publicado_em'])) ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($security): ?>
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        Alertas de segurança
                    </span>

                    <a
                        class="small text-decoration-none"
                        href="<?= e(url('admin/auditoria/index.php?nivel=warning')) ?>"
                    >
                        Auditoria
                    </a>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($security as $alert): ?>
                        <a
                            class="list-group-item list-group-item-action"
                            href="<?= e(
                                url(
                                    'admin/auditoria/index.php?q='
                                    . rawurlencode(
                                        (string)$alert['acao']
                                    )
                                )
                            ) ?>"
                        >
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <div class="fw-semibold">
                                        <span
                                            class="badge <?= (string)($alert['nivel'] ?? 'warning') === 'critical' ? 'text-bg-danger' : 'text-bg-warning' ?> me-1"
                                        >
                                            <?= e((string)($alert['nivel'] ?? 'warning')) ?>
                                        </span>

                                        <?= e((string)$alert['acao']) ?>
                                    </div>

                                    <?php if (!empty($alert['detalhes'])): ?>
                                        <div class="small text-secondary">
                                            <?= e(
                                                portalExcerpt(
                                                    (string)$alert['detalhes'],
                                                    120
                                                )
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <small class="text-secondary text-nowrap">
                                    <?= e(formatDateBr($alert['created_at'])) ?>
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
