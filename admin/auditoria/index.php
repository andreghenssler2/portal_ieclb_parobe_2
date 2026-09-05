<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('auditoria.visualizar');
$pdo = Database::connection();

$q = trim((string)($_GET['q'] ?? ''));
$nivel = (string)($_GET['nivel'] ?? '');
$usuarioId = max(0, (int)($_GET['usuario_id'] ?? 0));
$acao = trim((string)($_GET['acao'] ?? ''));
$dataDe = trim((string)($_GET['data_de'] ?? ''));
$dataAte = trim((string)($_GET['data_ate'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(l.acao LIKE :q OR l.entidade LIKE :q OR l.detalhes LIKE :q OR l.ip LIKE :q OR u.nome LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if (in_array($nivel, ['info','warning','critical'], true)) {
    $where[] = 'COALESCE(l.nivel, "info") = :nivel';
    $params['nivel'] = $nivel;
}
if ($usuarioId > 0) {
    $where[] = 'l.usuario_id = :usuario_id';
    $params['usuario_id'] = $usuarioId;
}
if ($acao !== '') {
    $where[] = 'l.acao = :acao';
    $params['acao'] = $acao;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) {
    $where[] = 'l.created_at >= :data_de';
    $params['data_de'] = $dataDe . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) {
    $where[] = 'l.created_at <= :data_ate';
    $params['data_ate'] = $dataAte . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM logs l LEFT JOIN usuarios u ON u.id=l.usuario_id WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT l.*, u.nome AS usuario_nome, u.email AS usuario_email
        FROM logs l
        LEFT JOIN usuarios u ON u.id=l.usuario_id
        WHERE {$whereSql}
        ORDER BY l.id DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$users = $pdo->query('SELECT id,nome,email FROM usuarios ORDER BY nome')->fetchAll();
$actions = $pdo->query('SELECT DISTINCT acao FROM logs ORDER BY acao')->fetchAll(PDO::FETCH_COLUMN);

$stats = ['hoje'=>0,'warning24'=>0,'critical30'=>0,'usuarios24'=>0];
try {
    $stats['hoje'] = (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE created_at >= CURDATE()")->fetchColumn();
    $stats['warning24'] = (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE COALESCE(nivel,'info')='warning' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $stats['critical30'] = (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE COALESCE(nivel,'info')='critical' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $stats['usuarios24'] = (int)$pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM logs WHERE usuario_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
} catch (Throwable $e) {}

$query = $_GET;
unset($query['page']);
$exportUrl = url('admin/auditoria/exportar.php') . ($query ? '?' . http_build_query($query) : '');

$pageTitle = 'Auditoria';
require __DIR__ . '/../_header.php';

$badgeClass = static function(string $level): string {
    return match ($level) {
        'critical' => 'text-bg-danger',
        'warning' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Auditoria</h1>
        <p class="text-secondary mb-0">Histórico de ações administrativas, acessos e eventos de segurança.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (Auth::can('seguranca.gerenciar')): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/configuracoes/seguranca.php')) ?>"><i class="bi bi-shield-lock me-1"></i> Segurança</a><?php endif; ?>
                <a
            class="btn btn-outline-secondary"
            href="<?= e(
                url(
                    'admin/usuarios/atividade.php'
                    . (
                        $usuarioId > 0
                            ? '?id=' . (int)$usuarioId
                            : ''
                    )
                )
            ) ?>"
        >
            <i class="bi bi-clock-history me-1"></i>
            Atividade por usuário
        </a>
<a class="btn btn-outline-primary" href="<?= e($exportUrl) ?>"><i class="bi bi-download me-1"></i> Exportar CSV</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Eventos hoje</div><div class="display-6 fw-semibold"><?= $stats['hoje'] ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Alertas · 24h</div><div class="display-6 fw-semibold"><?= $stats['warning24'] ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Críticos · 30d</div><div class="display-6 fw-semibold"><?= $stats['critical30'] ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Usuários ativos · 24h</div><div class="display-6 fw-semibold"><?= $stats['usuarios24'] ?></div></div></div></div>
</div>

<form method="get" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-4"><label class="form-label">Buscar</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Ação, detalhes, usuário ou IP"></div>
            <div class="col-md-4 col-lg-2"><label class="form-label">Nível</label><select class="form-select" name="nivel"><option value="">Todos</option><option value="info" <?= $nivel==='info'?'selected':'' ?>>Info</option><option value="warning" <?= $nivel==='warning'?'selected':'' ?>>Alerta</option><option value="critical" <?= $nivel==='critical'?'selected':'' ?>>Crítico</option></select></div>
            <div class="col-md-8 col-lg-3"><label class="form-label">Usuário</label><select class="form-select" name="usuario_id"><option value="0">Todos</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $usuarioId===(int)$u['id']?'selected':'' ?>><?= e($u['nome']) ?> · <?= e($u['email']) ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-3"><label class="form-label">Ação</label><select class="form-select" name="acao"><option value="">Todas</option><?php foreach($actions as $a): ?><option value="<?= e($a) ?>" <?= $acao===$a?'selected':'' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">De</label><input class="form-control" type="date" name="data_de" value="<?= e($dataDe) ?>"></div>
            <div class="col-md-3"><label class="form-label">Até</label><input class="form-control" type="date" name="data_ate" value="<?= e($dataAte) ?>"></div>
            <div class="col-md-6 d-flex align-items-end gap-2"><button class="btn btn-primary">Filtrar</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/auditoria/index.php')) ?>">Limpar</a></div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= number_format($total, 0, ',', '.') ?> registro(s)</span>
        <small class="text-secondary">Página <?= $page ?> de <?= $pages ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 audit-table">
            <thead><tr><th>Data</th><th>Nível</th><th>Usuário</th><th>Ação</th><th>Origem</th><th>Detalhes</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?><tr><td colspan="6" class="text-center text-secondary py-5">Nenhum registro encontrado.</td></tr><?php endif; ?>
            <?php foreach($logs as $log): $level=(string)($log['nivel'] ?? 'info'); ?>
                <tr>
                    <td class="text-nowrap"><div><?= e(date('d/m/Y', strtotime((string)$log['created_at']))) ?></div><small class="text-secondary"><?= e(date('H:i:s', strtotime((string)$log['created_at']))) ?></small></td>
                    <td><span class="badge <?= e($badgeClass($level)) ?>"><?= e($level) ?></span></td>
                    <td><?php if($log['usuario_nome']): ?><div class="fw-semibold"><?= e($log['usuario_nome']) ?></div><small class="text-secondary"><?= e($log['usuario_email']) ?></small><?php else: ?><span class="text-secondary">Sistema/visitante</span><?php endif; ?></td>
                    <td><code><?= e($log['acao']) ?></code><?php if($log['entidade']): ?><div class="small text-secondary"><?= e($log['entidade']) ?><?= $log['entidade_id'] ? ' #' . (int)$log['entidade_id'] : '' ?></div><?php endif; ?></td>
                    <td><div class="small"><?= e((string)($log['metodo'] ?? '')) ?> <?= e((string)($log['rota'] ?? '')) ?></div><small class="text-secondary"><?= e((string)($log['ip'] ?? '')) ?></small></td>
                    <td style="min-width:260px">
                        <?php if($log['detalhes']): ?><div><?= e(portalExcerpt((string)$log['detalhes'], 180)) ?></div><?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                        <?php if(!empty($log['user_agent']) || !empty($log['request_id'])): ?>
                            <details class="mt-1"><summary class="small text-secondary">Dados técnicos</summary><div class="small mt-1"><strong>Request:</strong> <?= e((string)($log['request_id'] ?? '')) ?><br><strong>Navegador:</strong> <?= e((string)($log['user_agent'] ?? '')) ?></div></details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-4" aria-label="Paginação da auditoria"><ul class="pagination flex-wrap">
    <?php for($i=max(1,$page-2); $i<=min($pages,$page+2); $i++): $qq=$_GET; $qq['page']=$i; ?>
        <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= e(http_build_query($qq)) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php require __DIR__ . '/../_footer.php'; ?>
