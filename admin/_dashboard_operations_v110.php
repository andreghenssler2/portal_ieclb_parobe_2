<?php
$adminOperations =
    is_array($adminOperations ?? null)
        ? $adminOperations
        : [];

$opsReadiness =
    (array)(
        $adminOperations['readiness']
        ?? []
    );

$opsMedia =
    (array)(
        $adminOperations['media']
        ?? []
    );
?>

<?php if ($adminOperations): ?>
<section class="mb-4" id="central-operacional-v110">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-3">
        <div>
            <div class="small text-uppercase text-secondary fw-semibold mb-1">
                Administração v1.1.10
            </div>

            <h2 class="h5 mb-1">
                Central operacional
            </h2>

            <div class="text-secondary small">
                Números do Portal, acessos, conteúdo pendente e saúde dos serviços.
            </div>
        </div>

        <?php if (!empty($opsReadiness['available'])): ?>
            <?php
            $readinessClass =
                (int)($opsReadiness['blockers'] ?? 0) > 0
                    ? 'danger'
                    : (
                        (int)($opsReadiness['warnings'] ?? 0) > 0
                            ? 'warning'
                            : 'success'
                    );
            ?>

            <a
                class="btn btn-sm btn-outline-<?= e($readinessClass) ?>"
                href="<?= e(url('admin/ferramentas/diagnostico.php')) ?>"
            >
                <i class="bi bi-heart-pulse me-1"></i>
                Saúde do Portal:
                <?= (int)($opsReadiness['score'] ?? 0) ?>%
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($adminOperations['numbers'])): ?>
        <div class="row g-3 mb-4">
            <?php foreach ((array)$adminOperations['numbers'] as $card): ?>
                <div class="col-6 col-lg-4 col-xxl-2">
                    <a
                        class="card border-0 shadow-sm h-100 text-decoration-none text-reset"
                        href="<?= e(url((string)$card['url'])) ?>"
                    >
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="small text-secondary mb-2">
                                        <?= e((string)$card['label']) ?>
                                    </div>

                                    <div class="h2 mb-0">
                                        <?= (int)$card['value'] ?>
                                    </div>
                                </div>

                                <span
                                    class="rounded-circle bg-<?= e((string)$card['class']) ?>-subtle text-<?= e((string)$card['class']) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:38px;height:38px"
                                >
                                    <i class="bi <?= e((string)$card['icon']) ?>"></i>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($adminOperations['alerts'])): ?>
        <div class="row g-3 mb-4">
            <?php foreach ((array)$adminOperations['alerts'] as $alert): ?>
                <div class="col-md-6 col-xl-3">
                    <a
                        class="card border-<?= e((string)$alert['status']) ?> h-100 text-decoration-none text-reset"
                        href="<?= e(url((string)$alert['url'])) ?>"
                    >
                        <div class="card-body p-3">
                            <div class="d-flex gap-3">
                                <span
                                    class="rounded-circle bg-<?= e((string)$alert['status']) ?>-subtle text-<?= e((string)$alert['status']) ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:40px;height:40px"
                                >
                                    <i class="bi <?= e((string)$alert['icon']) ?>"></i>
                                </span>

                                <div>
                                    <div class="fw-semibold">
                                        <?= e((string)$alert['label']) ?>
                                    </div>

                                    <div class="small text-secondary mt-1">
                                        <?= e((string)$alert['detail']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (!empty($adminOperations['waiting_news'])): ?>
            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">
                                Notícias aguardando publicação
                            </div>

                            <div class="small text-secondary">
                                Rascunhos, revisão e conteúdos agendados.
                            </div>
                        </div>

                        <a
                            class="small text-decoration-none"
                            href="<?= e(url('admin/noticias/index.php')) ?>"
                        >
                            Ver notícias
                        </a>
                    </div>

                    <div class="list-group list-group-flush">
                        <?php foreach ((array)$adminOperations['waiting_news'] as $post): ?>
                            <?php
                            $statusText =
                                (string)($post['workflow_status'] ?: $post['status']);

                            if (
                                !empty($post['publicado_em'])
                                && strtotime((string)$post['publicado_em']) > time()
                            ) {
                                $statusText =
                                    'Agendada';
                            }
                            ?>

                            <a
                                class="list-group-item list-group-item-action"
                                href="<?= e(
                                    url(
                                        'admin/noticias/form.php?id='
                                        . (int)$post['id']
                                    )
                                ) ?>"
                            >
                                <div class="d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= e((string)$post['titulo']) ?>
                                        </div>

                                        <div class="small text-secondary">
                                            <?= e($statusText) ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($post['publicado_em'])): ?>
                                        <div class="small text-secondary text-nowrap">
                                            <?= e(formatDateBr((string)$post['publicado_em'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if (!empty($adminOperations['events'])): ?>
            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">
                                Próximos eventos
                            </div>

                            <div class="small text-secondary">
                                Agenda publicada a partir de agora.
                            </div>
                        </div>

                        <a
                            class="small text-decoration-none"
                            href="<?= e(url('admin/eventos/index.php')) ?>"
                        >
                            Agenda
                        </a>
                    </div>

                    <div class="list-group list-group-flush">
                        <?php foreach ((array)$adminOperations['events'] as $event): ?>
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
                                            <?= e(
                                                (string)(
                                                    $event['comunidade_nome']
                                                    ?: $event['local']
                                                    ?: $event['tipo']
                                                )
                                            ) ?>
                                        </div>
                                    </div>

                                    <div class="small text-secondary text-nowrap">
                                        <?= e(formatDateBr((string)$event['data_inicio'])) ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if (!empty($adminOperations['accesses'])): ?>
            <div class="col-xl-7">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">
                                Últimos acessos administrativos
                            </div>

                            <div class="small text-secondary">
                                Usuários ativos ordenados pelo último login registrado.
                            </div>
                        </div>

                        <?php if (Auth::can('usuarios.gerenciar') || Auth::isAdmin()): ?>
                            <a
                                class="small text-decoration-none"
                                href="<?= e(url('admin/usuarios/index.php')) ?>"
                            >
                                Usuários
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Usuário</th>
                                    <th>Último acesso</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ((array)$adminOperations['accesses'] as $access): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">
                                                <?= e((string)$access['nome']) ?>
                                            </div>

                                            <?php if (!empty($access['email'])): ?>
                                                <div class="small text-secondary">
                                                    <?= e((string)$access['email']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-nowrap">
                                            <?= e(formatDateBr((string)$access['ultimo_login'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if (
            (int)($opsMedia['count'] ?? 0) > 0
            || Auth::can('midias.gerenciar')
            || Auth::isAdmin()
        ): ?>
            <div class="col-xl-5">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-body py-3">
                        <div class="fw-semibold">
                            Espaço usado por mídias
                        </div>

                        <div class="small text-secondary">
                            Total cadastrado na Biblioteca de Mídia.
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <div class="display-6 fw-semibold mb-1">
                            <?= e(
                                formatBytes(
                                    (int)($opsMedia['bytes'] ?? 0)
                                )
                            ) ?>
                        </div>

                        <div class="text-secondary mb-4">
                            <?= (int)($opsMedia['count'] ?? 0) ?> arquivo(s)
                        </div>

                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0">
                                        <?= (int)($opsMedia['images'] ?? 0) ?>
                                    </div>
                                    <div class="small text-secondary">Imagens</div>
                                </div>
                            </div>

                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0">
                                        <?= (int)($opsMedia['videos'] ?? 0) ?>
                                    </div>
                                    <div class="small text-secondary">Vídeos</div>
                                </div>
                            </div>

                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0">
                                        <?= (int)($opsMedia['documents'] ?? 0) ?>
                                    </div>
                                    <div class="small text-secondary">Docs</div>
                                </div>
                            </div>
                        </div>

                        <a
                            class="btn btn-outline-secondary w-100 mt-4"
                            href="<?= e(url('admin/midias/index.php')) ?>"
                        >
                            Abrir Biblioteca de Mídia
                        </a>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($adminOperations['quick_links'])): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body p-4">
                <div class="fw-semibold mb-3">
                    Atalhos rápidos da administração
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ((array)$adminOperations['quick_links'] as $link): ?>
                        <a
                            class="btn btn-outline-secondary"
                            href="<?= e(url((string)$link['url'])) ?>"
                        >
                            <i class="bi <?= e((string)$link['icon']) ?> me-1"></i>
                            <?= e((string)$link['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
