<?php
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();

$cards = [];
$ultimasNoticias = [];
$proximosEventos = [];
$comentariosPendentes = [];
$securityAlerts = [];
$maintenance = maintenanceSettings($pdo);

if (Auth::can('noticias.gerenciar')) {
    $cards[] = ['Notícias publicadas', (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'publicado'")->fetchColumn()];
    $cards[] = ['Rascunhos', (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'rascunho'")->fetchColumn()];
    $ultimasNoticias = $pdo->query("SELECT id, titulo, status, publicado_em, created_at FROM posts WHERE status <> 'lixeira' ORDER BY id DESC LIMIT 5")->fetchAll();
}
if (Auth::can('comentarios.gerenciar')) {
    try {
        $cards[] = ['Comentários pendentes', (int)$pdo->query("SELECT COUNT(*) FROM comentarios WHERE status='pendente'")->fetchColumn()];
        $comentariosPendentes = $pdo->query("SELECT co.id,co.autor_nome,co.conteudo,co.created_at,p.titulo AS post_titulo FROM comentarios co INNER JOIN posts p ON p.id=co.post_id WHERE co.status='pendente' AND p.status <> 'lixeira' ORDER BY co.created_at DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        // Atualização v0.15.0 ainda não executada.
    }
}
if (Auth::can('paginas.gerenciar')) {
    $cards[] = ['Páginas', (int)$pdo->query("SELECT COUNT(*) FROM paginas WHERE status = 'publicado'")->fetchColumn()];
}
if (Auth::can('eventos.gerenciar')) {
    $cards[] = ['Próximos na agenda', (int)$pdo->query("SELECT COUNT(*) FROM eventos WHERE status = 'publicado' AND data_inicio >= NOW()")->fetchColumn()];
    $proximosEventos = $pdo->query(
        "SELECT e.id, e.tipo, e.titulo, e.data_inicio, e.santa_ceia, c.nome AS comunidade_nome
         FROM eventos e
         LEFT JOIN comunidades c ON c.id = e.comunidade_id
         WHERE e.status = 'publicado' AND e.data_inicio >= NOW()
         ORDER BY e.data_inicio ASC
         LIMIT 6"
    )->fetchAll();
}
if (Auth::can('comunidades.gerenciar')) {
    $cards[] = ['Comunidades', (int)$pdo->query("SELECT COUNT(*) FROM comunidades WHERE ativa = 1")->fetchColumn()];
}
if (Auth::can('midias.gerenciar')) {
    $cards[] = ['Mídias', (int)$pdo->query("SELECT COUNT(*) FROM midias")->fetchColumn()];
}
if (Auth::can('galerias.gerenciar')) {
    try {
        $cards[] = ['Galerias', (int)$pdo->query("SELECT COUNT(*) FROM galerias WHERE status = 'publicado'")->fetchColumn()];
    } catch (Throwable $e) {
        // Atualização v0.7.0 ainda não executada.
    }
}
if (Auth::can('banners.gerenciar')) {
    try {
        $cards[] = ['Banners ativos', (int)$pdo->query("SELECT COUNT(*) FROM banners WHERE ativo = 1")->fetchColumn()];
    } catch (Throwable $e) {
        // Atualização v0.7.0 ainda não executada.
    }
}
if (Auth::can('menus.gerenciar')) {
    try {
        $cards[] = ['Itens de menu', (int)$pdo->query("SELECT COUNT(*) FROM menu_itens WHERE ativo = 1")->fetchColumn()];
    } catch (Throwable $e) {
        // Atualização v0.6.0 ainda não executada.
    }
}
if (Auth::can('formularios.gerenciar')) {
    try {
        $cards[] = ['Respostas novas', (int)$pdo->query("SELECT COUNT(*) FROM formulario_respostas WHERE status = 'nova'")->fetchColumn()];
    } catch (Throwable $e) {
        // Atualização v0.8.0 ainda não executada.
    }
}
if (Auth::can('usuarios.gerenciar')) {
    $cards[] = ['Usuários ativos', (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn()];
}
if (Auth::can('auditoria.visualizar')) {
    try {
        $cards[] = ['Alertas de segurança · 24h', (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE COALESCE(nivel,'info') IN ('warning','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn()];
        $securityAlerts = $pdo->query(
            "SELECT l.id,l.acao,l.detalhes,l.ip,l.nivel,l.created_at,u.nome AS usuario_nome
             FROM logs l
             LEFT JOIN usuarios u ON u.id=l.usuario_id
             WHERE COALESCE(l.nivel,'info') IN ('warning','critical')
             ORDER BY l.id DESC
             LIMIT 5"
        )->fetchAll();
    } catch (Throwable $e) {
        // Migração v0.20.0 ainda não executada.
    }
}

$pageTitle = 'Dashboard';
require __DIR__ . '/_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-secondary mb-0">Bem-vindo, <?= e(Auth::user()['nome'] ?? '') ?>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (Auth::can('backups.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/backups.php')) ?>">Backups</a><?php endif; ?>
        <?php if (Auth::can('manutencao.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/manutencao.php')) ?>">Manutenção</a><?php endif; ?>
        <?php if (Auth::can('formularios.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/formularios/index.php')) ?>">Formulários</a><?php endif; ?>
        <?php if (Auth::can('auditoria.visualizar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/auditoria/index.php')) ?>">Auditoria</a><?php endif; ?>
        <?php if (Auth::can('configuracoes.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/index.php')) ?>">Configurações</a><?php endif; ?>
        <?php if (Auth::can('menus.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/menus/index.php')) ?>">Menus</a><?php endif; ?>
        <?php if (Auth::can('banners.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/banners/form.php')) ?>">Novo banner</a><?php endif; ?>
        <?php if (Auth::can('galerias.gerenciar')): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/galerias/form.php')) ?>">Nova galeria</a><?php endif; ?>
        <?php if (Auth::can('eventos.gerenciar')): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/eventos/form.php')) ?>">Novo evento/culto</a><?php endif; ?>
        <?php if (Auth::can('paginas.gerenciar')): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/paginas/form.php')) ?>">Nova página</a><?php endif; ?>
        <?php if (Auth::can('comentarios.gerenciar')): ?><a class="btn btn-outline-primary" href="<?= e(url('admin/comentarios/index.php')) ?>">Moderar comentários</a><?php endif; ?>
        <?php if (Auth::can('noticias.gerenciar')): ?><a class="btn btn-primary" href="<?= e(url('admin/noticias/form.php')) ?>">Nova notícia</a><?php endif; ?>
    </div>
</div>

<?php if ($maintenance['enabled']): ?>
<div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><i class="bi bi-cone-striped me-2"></i><strong>Modo manutenção está ativo.</strong> O portal público está respondendo com HTTP 503 para visitantes.</div>
    <?php if (Auth::can('manutencao.gerenciar')): ?><a class="btn btn-sm btn-warning" href="<?= e(url('admin/ferramentas/manutencao.php')) ?>">Gerenciar manutenção</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cards): ?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as [$label, $value]): ?>
        <div class="col-sm-6 col-lg-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small"><?= e($label) ?></div>
                    <div class="display-6 fw-semibold"><?= (int)$value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div class="alert alert-light border mb-4">Seu perfil ainda não possui módulos administrativos liberados. Você pode acessar sua conta ou visualizar o portal público.</div>
<?php endif; ?>

<div class="row g-4">
    <?php if (Auth::can('noticias.gerenciar')): ?>
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Últimas notícias</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead><tr><th>Título</th><th>Status</th><th>Data</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$ultimasNoticias): ?><tr><td colspan="4" class="text-secondary">Nenhuma notícia cadastrada.</td></tr><?php endif; ?>
                    <?php foreach ($ultimasNoticias as $post): ?>
                        <tr>
                            <td><?= e($post['titulo']) ?></td>
                            <td><span class="badge text-bg-secondary"><?= e($post['status']) ?></span></td>
                            <td><?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/noticias/form.php?id='.(int)$post['id'])) ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (Auth::can('eventos.gerenciar')): ?>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Próximos eventos e cultos</span>
                <a class="small text-decoration-none" href="<?= e(url('admin/eventos/index.php')) ?>">Ver agenda</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$proximosEventos): ?><div class="list-group-item text-secondary">Nenhum item futuro publicado.</div><?php endif; ?>
                <?php foreach ($proximosEventos as $evento): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('admin/eventos/form.php?id=' . (int)$evento['id'])) ?>">
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold"><?= e($evento['titulo']) ?></div>
                                <small class="text-secondary"><?= e($evento['comunidade_nome'] ?: 'Paroquial') ?><?php if ((int)$evento['santa_ceia'] === 1): ?> · Santa Ceia<?php endif; ?></small>
                            </div>
                            <div class="text-end text-nowrap">
                                <div class="small fw-semibold"><?= e(formatDateOnlyBr($evento['data_inicio'])) ?></div>
                                <div class="small text-secondary"><?= e(formatTimeBr($evento['data_inicio'])) ?></div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (Auth::can('comentarios.gerenciar') && $comentariosPendentes): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Comentários aguardando moderação</span><a class="small text-decoration-none" href="<?= e(url('admin/comentarios/index.php')) ?>">Ver todos</a></div>
    <div class="list-group list-group-flush">
        <?php foreach($comentariosPendentes as $comentario): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('admin/comentarios/index.php?status=pendente')) ?>">
                <div class="d-flex justify-content-between gap-3"><div><div class="fw-semibold"><?= e($comentario['autor_nome']) ?> <span class="fw-normal text-secondary">em <?= e($comentario['post_titulo']) ?></span></div><div class="small text-secondary"><?= e(portalExcerpt($comentario['conteudo'], 140)) ?></div></div><small class="text-nowrap text-secondary"><?= e(formatDateBr($comentario['created_at'])) ?></small></div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if (Auth::can('auditoria.visualizar') && $securityAlerts): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Alertas recentes de segurança</span>
        <a class="small text-decoration-none" href="<?= e(url('admin/auditoria/index.php?nivel=warning')) ?>">Abrir auditoria</a>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach($securityAlerts as $alert): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('admin/auditoria/index.php?q=' . rawurlencode((string)$alert['acao']))) ?>">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <div class="fw-semibold"><span class="badge <?= ($alert['nivel']??'warning')==='critical'?'text-bg-danger':'text-bg-warning' ?> me-2"><?= e((string)($alert['nivel']??'warning')) ?></span><?= e((string)$alert['acao']) ?></div>
                        <div class="small text-secondary"><?= e(portalExcerpt((string)($alert['detalhes'] ?? ''), 140)) ?><?= !empty($alert['ip']) ? ' · IP ' . e((string)$alert['ip']) : '' ?></div>
                    </div>
                    <small class="text-nowrap text-secondary"><?= e(formatDateBr((string)$alert['created_at'])) ?></small>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
