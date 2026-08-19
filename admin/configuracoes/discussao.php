<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('configuracoes.gerenciar');
$pdo = Database::connection();
$error = '';
$defaults = [
    'comments_enabled' => '1',
    'comments_default_open' => '1',
    'comments_require_moderation' => '1',
    'comments_max_length' => '2000',
    'comments_rate_limit_seconds' => '60',
];
$s = array_merge($defaults, siteConfigAll($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $s['comments_enabled'] = isset($_POST['comments_enabled']) ? '1' : '0';
            $s['comments_default_open'] = isset($_POST['comments_default_open']) ? '1' : '0';
            $s['comments_require_moderation'] = isset($_POST['comments_require_moderation']) ? '1' : '0';
            $s['comments_max_length'] = (string)max(200, min(10000, (int)($_POST['comments_max_length'] ?? 2000)));
            $s['comments_rate_limit_seconds'] = (string)max(10, min(3600, (int)($_POST['comments_rate_limit_seconds'] ?? 60)));

            foreach ($defaults as $key => $_) {
                $type = in_array($key, ['comments_max_length','comments_rate_limit_seconds'], true) ? 'numero' : 'booleano';
                saveSiteConfig($pdo, $key, $s[$key], $type);
            }
            logAction($pdo, 'configuracoes.discussao', 'configuracoes');
            Session::flash('success', 'Configurações de discussão atualizadas.');
            header('Location: ' . url('admin/configuracoes/discussao.php')); exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Configurações de discussão';
require __DIR__ . '/../_header.php';
?>
<h1 class="h3 mb-1">Discussão</h1>
<p class="text-secondary mb-4">Controle os comentários enviados nas Notícias.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body p-4">
    <?= Csrf::field() ?>
    <div class="row g-4">
        <div class="col-12">
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="comments_enabled" id="commentsEnabled" <?= $s['comments_enabled']==='1'?'checked':'' ?>><label class="form-check-label fw-semibold" for="commentsEnabled">Permitir comentários no portal</label><div class="form-text">Quando desligado, comentários já aprovados continuam visíveis, mas novos comentários não podem ser enviados.</div></div>
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="comments_default_open" id="commentsDefaultOpen" <?= $s['comments_default_open']==='1'?'checked':'' ?>><label class="form-check-label" for="commentsDefaultOpen">Permitir comentários por padrão em novas Notícias</label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="comments_require_moderation" id="commentsModeration" <?= $s['comments_require_moderation']==='1'?'checked':'' ?>><label class="form-check-label" for="commentsModeration">Comentário deve ser aprovado antes de aparecer publicamente</label></div>
        </div>
        <div class="col-md-6"><label class="form-label">Tamanho máximo do comentário</label><div class="input-group"><input class="form-control" type="number" min="200" max="10000" name="comments_max_length" value="<?= e($s['comments_max_length']) ?>"><span class="input-group-text">caracteres</span></div></div>
        <div class="col-md-6"><label class="form-label">Intervalo mínimo entre envios do mesmo IP</label><div class="input-group"><input class="form-control" type="number" min="10" max="3600" name="comments_rate_limit_seconds" value="<?= e($s['comments_rate_limit_seconds']) ?>"><span class="input-group-text">segundos</span></div></div>
        <div class="col-12"><button class="btn btn-primary">Salvar discussão</button> <a class="btn btn-outline-secondary" href="<?= e(url('admin/comentarios/index.php')) ?>">Ir para comentários</a></div>
    </div>
</div></form>
<?php require __DIR__ . '/../_footer.php'; ?>
