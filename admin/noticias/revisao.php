<?php

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();

if (
    !Auth::can('noticias.gerenciar')
    && !Auth::can('noticias.revisar')
    && !Auth::can('noticias.publicar')
    && !Auth::isAdmin()
) {
    Session::flash(
        'error',
        'Você não possui permissão para acessar a fila de revisão.'
    );

    header('Location: ' . url('admin/index.php'));
    exit;
}

$pdo = Database::connection();

$canReview =
    Auth::can('noticias.revisar')
    || Auth::isAdmin();

$canPublish =
    Auth::can('noticias.publicar')
    || Auth::isAdmin();

$filter = strtolower(
    trim(
        (string)($_GET['status'] ?? 'pendentes')
    )
);

$allowedFilters = [
    'pendentes',
    'revisao',
    'ajustes',
    'aprovados',
    'todos',
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'pendentes';
}

$where = match ($filter) {
    'revisao' => "p.workflow_status='revisao'",
    'ajustes' => "p.workflow_status='ajustes'",
    'aprovados' => "p.workflow_status='aprovado'",
    'todos' => "p.workflow_status IN ('revisao','ajustes','aprovado')",
    default => "p.workflow_status IN ('revisao','aprovado')",
};

$stmt = $pdo->query(
    "SELECT
        p.id,
        p.titulo,
        p.slug,
        p.status,
        p.workflow_status,
        p.workflow_enviado_em,
        p.workflow_revisado_em,
        p.workflow_observacao,
        p.workflow_hash,
        p.updated_at,
        a.nome AS autor_nome,
        e.nome AS enviado_por_nome,
        r.nome AS revisado_por_nome
     FROM posts p
     LEFT JOIN usuarios a
        ON a.id=p.autor_id
     LEFT JOIN usuarios e
        ON e.id=p.workflow_enviado_por
     LEFT JOIN usuarios r
        ON r.id=p.workflow_revisado_por
     WHERE {$where}
       AND p.status <> 'lixeira'
     ORDER BY
        CASE p.workflow_status
            WHEN 'revisao' THEN 1
            WHEN 'aprovado' THEN 2
            WHEN 'ajustes' THEN 3
            ELSE 4
        END,
        COALESCE(
            p.workflow_enviado_em,
            p.updated_at,
            p.created_at
        ) ASC,
        p.id ASC"
);

$posts = $stmt->fetchAll() ?: [];

$counts = [
    'revisao' => 0,
    'ajustes' => 0,
    'aprovado' => 0,
];

try {
    $countRows = $pdo->query(
        "SELECT workflow_status,COUNT(*) total
         FROM posts
         WHERE workflow_status IN ('revisao','ajustes','aprovado')
           AND status <> 'lixeira'
         GROUP BY workflow_status"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($countRows as $row) {
        $counts[(string)$row['workflow_status']] =
            (int)$row['total'];
    }
} catch (Throwable $ignored) {
}

$pageTitle = 'Revisão editorial';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-uppercase small text-secondary fw-semibold mb-1">
            Notícias
        </div>

        <h1 class="h3 mb-1">
            Fila de revisão editorial
        </h1>

        <p class="text-secondary mb-0">
            Revise, solicite ajustes, aprove e publique conteúdos.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/noticias/index.php')) ?>"
    >
        Todas as notícias
    </a>
</div>

<?php if (!$canReview && !$canPublish): ?>
    <div class="alert alert-info">
        Você pode acompanhar o estado dos conteúdos e enviar seus rascunhos
        para revisão. As ações de aprovação e publicação dependem das
        permissões do perfil.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Aguardando revisão</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$counts['revisao'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Ajustes solicitados</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$counts['ajustes'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Aprovados</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$counts['aprovado'] ?>
                </div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills gap-2 flex-wrap mb-4">
    <?php
    $tabs = [
        'pendentes' => 'Pendentes',
        'revisao' => 'Em revisão',
        'ajustes' => 'Ajustes',
        'aprovados' => 'Aprovados',
        'todos' => 'Todos',
    ];
    ?>

    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a
                class="nav-link <?= $filter === $key ? 'active' : '' ?>"
                href="<?= e(
                    $key === 'pendentes'
                        ? url('admin/noticias/revisao.php')
                        : url(
                            'admin/noticias/revisao.php?status='
                            . rawurlencode($key)
                        )
                ) ?>"
            >
                <?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (!$posts): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body py-5 text-center text-secondary">
            <i class="bi bi-check-circle fs-1 d-block mb-3"></i>
            Nenhum conteúdo nesta etapa do fluxo editorial.
        </div>
    </div>
<?php endif; ?>

<div class="d-grid gap-3">
    <?php foreach ($posts as $post): ?>
        <?php
        $workflowStatus =
            (string)($post['workflow_status'] ?? 'rascunho');

        $approvalCurrent =
            $workflowStatus === EditorialWorkflowService::APPROVED
            && EditorialWorkflowService::isApprovalCurrent(
                $pdo,
                (int)$post['id']
            );
        ?>

        <article class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-xl-row gap-4">
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span
                                class="badge text-bg-<?= e(
                                    EditorialWorkflowService::badgeClass(
                                        $workflowStatus
                                    )
                                ) ?>"
                            >
                                <?= e(
                                    EditorialWorkflowService::label(
                                        $workflowStatus
                                    )
                                ) ?>
                            </span>

                            <?php if (
                                $workflowStatus === EditorialWorkflowService::APPROVED
                                && !$approvalCurrent
                            ): ?>
                                <span class="badge text-bg-danger">
                                    Conteúdo alterado após aprovação
                                </span>
                            <?php endif; ?>

                            <span class="badge text-bg-light border">
                                <?= e((string)$post['status']) ?>
                            </span>
                        </div>

                        <h2 class="h5 mb-2">
                            <?= e((string)$post['titulo']) ?>
                        </h2>

                        <div class="small text-secondary mb-3">
                            Autor:
                            <?= e((string)($post['autor_nome'] ?: 'Usuário')) ?>

                            <?php if (!empty($post['workflow_enviado_em'])): ?>
                                · enviado em
                                <?= e(formatDateBr($post['workflow_enviado_em'])) ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($post['workflow_observacao'])): ?>
                            <div class="alert alert-light border mb-3">
                                <div class="small text-secondary mb-1">
                                    Observação da revisão
                                </div>
                                <?= nl2br(e((string)$post['workflow_observacao'])) ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2">
                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="<?= e(
                                    url(
                                        'admin/noticias/form.php?id='
                                        . (int)$post['id']
                                    )
                                ) ?>"
                            >
                                Abrir editor
                            </a>

                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="<?= e(
                                    url(
                                        'admin/revisoes/index.php?tipo=post&id='
                                        . (int)$post['id']
                                    )
                                ) ?>"
                            >
                                Ver revisões
                            </a>

                            <?php if (
                                (string)$post['status'] === 'publicado'
                                && !empty($post['slug'])
                            ): ?>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    target="_blank"
                                    href="<?= e(
                                        contentUrl(
                                            'noticia',
                                            (string)$post['slug']
                                        )
                                    ) ?>"
                                >
                                    Ver no portal
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="min-width:min(100%,360px)" class="workflow-actions">
                        <?php if (
                            $workflowStatus === EditorialWorkflowService::REVIEW
                            && $canReview
                        ): ?>

                            <div class="card bg-light border-0">
                                <div class="card-body">
                                    <form
                                        method="post"
                                        action="<?= e(url('admin/noticias/workflow.php')) ?>"
                                        class="mb-3"
                                    >
                                        <?= Csrf::field() ?>
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$post['id'] ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="approve"
                                        >

                                        <label class="form-label small fw-semibold">
                                            Observação opcional para aprovação
                                        </label>

                                        <textarea
                                            class="form-control form-control-sm mb-2"
                                            name="observacao"
                                            rows="2"
                                            maxlength="4000"
                                        ></textarea>

                                        <button class="btn btn-success btn-sm w-100">
                                            <i class="bi bi-check2-circle me-1"></i>
                                            Aprovar
                                        </button>
                                    </form>

                                    <form
                                        method="post"
                                        action="<?= e(url('admin/noticias/workflow.php')) ?>"
                                    >
                                        <?= Csrf::field() ?>
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$post['id'] ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="changes"
                                        >

                                        <label class="form-label small fw-semibold">
                                            Solicitar ajustes
                                        </label>

                                        <textarea
                                            class="form-control form-control-sm mb-2"
                                            name="observacao"
                                            rows="3"
                                            maxlength="4000"
                                            placeholder="Explique o que precisa ser corrigido."
                                            required
                                        ></textarea>

                                        <button class="btn btn-outline-danger btn-sm w-100">
                                            Solicitar ajustes
                                        </button>
                                    </form>
                                </div>
                            </div>

                        <?php elseif (
                            $workflowStatus === EditorialWorkflowService::APPROVED
                            && $canPublish
                        ): ?>

                            <form
                                method="post"
                                action="<?= e(url('admin/noticias/workflow.php')) ?>"
                            >
                                <?= Csrf::field() ?>

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$post['id'] ?>"
                                >

                                <?php if ($approvalCurrent): ?>
                                    <button
                                        class="btn btn-primary w-100"
                                        name="action"
                                        value="publish"
                                    >
                                        <i class="bi bi-send-check me-1"></i>
                                        Publicar agora
                                    </button>
                                <?php else: ?>
                                    <div class="alert alert-danger mb-0">
                                        O conteúdo mudou depois da aprovação.
                                        Envie novamente para revisão.
                                    </div>
                                <?php endif; ?>
                            </form>

                        <?php elseif (
                            $workflowStatus === EditorialWorkflowService::CHANGES
                        ): ?>

                            <div class="alert alert-warning mb-0">
                                Aguardando o autor/editor realizar os ajustes
                                e enviar novamente para revisão.
                            </div>

                        <?php else: ?>

                            <div class="text-secondary small">
                                Nenhuma ação disponível para seu perfil nesta etapa.
                            </div>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
