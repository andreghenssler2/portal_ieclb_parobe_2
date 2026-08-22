<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('midias.gerenciar');
$pdo = Database::connection();
$error = '';

$defaults = [
    'media_optimize_enabled' => '1',
    'media_generate_webp' => '1',
    'media_variant_widths' => '320,640,1024,1600',
    'media_image_quality' => '82',
];
$settings = array_merge($defaults, siteConfigAll($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'settings');

            if ($action === 'settings') {
                $settings['media_optimize_enabled'] = isset($_POST['media_optimize_enabled']) ? '1' : '0';
                $settings['media_generate_webp'] = isset($_POST['media_generate_webp']) ? '1' : '0';
                $widths = ImageOptimizationService::parseWidths((string)($_POST['media_variant_widths'] ?? ''));
                $settings['media_variant_widths'] = implode(',', $widths);
                $settings['media_image_quality'] = (string)max(50, min(95, (int)($_POST['media_image_quality'] ?? 82)));

                saveSiteConfig($pdo, 'media_optimize_enabled', $settings['media_optimize_enabled'], 'booleano');
                saveSiteConfig($pdo, 'media_generate_webp', $settings['media_generate_webp'], 'booleano');
                saveSiteConfig($pdo, 'media_variant_widths', $settings['media_variant_widths'], 'texto');
                saveSiteConfig($pdo, 'media_image_quality', $settings['media_image_quality'], 'numero');
                logAction($pdo, 'midia.otimizacao.configuracoes', 'configuracoes');
                Session::flash('success', 'Configurações de otimização atualizadas.');
                header('Location: ' . url('admin/midias/otimizacao.php'));
                exit;
            }

            if ($action === 'optimize_batch') {
                $limit = max(1, min(20, (int)($_POST['limit'] ?? 10)));
                $stmt = $pdo->query(
                    "SELECT m.id FROM midias m
                     WHERE m.mime_type IN ('image/jpeg','image/png','image/webp')
                       AND NOT EXISTS (SELECT 1 FROM midia_variantes v WHERE v.midia_id=m.id)
                     ORDER BY m.id ASC LIMIT " . $limit
                );
                $ids = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
                $done = 0;
                $fail = 0;
                foreach ($ids as $id) {
                    $result = ImageOptimizationService::optimizeMedia($pdo, $id, true);
                    if ($result['ok']) $done++; else $fail++;
                }
                logAction($pdo, 'midia.otimizacao.lote', 'midias', null, 'Processadas: ' . count($ids) . '; sucesso: ' . $done . '; falhas: ' . $fail);
                Session::flash('success', count($ids) > 0
                    ? 'Lote concluído: ' . $done . ' imagem(ns) otimizada(s)' . ($fail ? ', ' . $fail . ' com aviso/falha.' : '.')
                    : 'Não há imagens pendentes para otimizar.');
                header('Location: ' . url('admin/midias/otimizacao.php'));
                exit;
            }

            if ($action === 'optimize_one' || $action === 'regenerate_one') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Mídia inválida.');
                $result = ImageOptimizationService::optimizeMedia($pdo, $id, $action === 'regenerate_one');
                if (!$result['ok']) throw new RuntimeException($result['message']);
                logAction($pdo, $action === 'regenerate_one' ? 'midia.otimizacao.regenerar' : 'midia.otimizacao.gerar', 'midias', $id, $result['message']);
                Session::flash('success', $result['message']);
                header('Location: ' . url('admin/midias/otimizacao.php'));
                exit;
            }

            throw new RuntimeException('Ação inválida.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$stats = [
    'images' => 0,
    'supported' => 0,
    'optimized' => 0,
    'variants' => 0,
    'bytes' => 0,
];
try {
    $stats['images'] = (int)$pdo->query("SELECT COUNT(*) FROM midias WHERE mime_type LIKE 'image/%'")->fetchColumn();
    $stats['supported'] = (int)$pdo->query("SELECT COUNT(*) FROM midias WHERE mime_type IN ('image/jpeg','image/png','image/webp')")->fetchColumn();
    $stats['optimized'] = (int)$pdo->query("SELECT COUNT(DISTINCT midia_id) FROM midia_variantes")->fetchColumn();
    $stats['variants'] = (int)$pdo->query("SELECT COUNT(*) FROM midia_variantes")->fetchColumn();
    $stats['bytes'] = (int)$pdo->query("SELECT COALESCE(SUM(tamanho),0) FROM midia_variantes")->fetchColumn();
} catch (Throwable $e) {}
$pending = max(0, $stats['supported'] - $stats['optimized']);

$recent = [];
try {
    $recent = $pdo->query(
        "SELECT m.id,m.nome_original,m.caminho,m.mime_type,m.tamanho,m.largura,m.altura,
                COUNT(v.id) AS variantes,COALESCE(SUM(v.tamanho),0) AS variantes_bytes
         FROM midias m
         LEFT JOIN midia_variantes v ON v.midia_id=m.id
         WHERE m.mime_type IN ('image/jpeg','image/png','image/webp')
         GROUP BY m.id
         ORDER BY m.id DESC LIMIT 40"
    )->fetchAll() ?: [];
} catch (Throwable $e) {}

$pageTitle = 'Otimização de imagens';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Otimização de imagens</h1>
        <p class="text-secondary mb-0">Crie tamanhos menores e WebP sem alterar os arquivos originais.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/midias/index.php')) ?>"><i class="bi bi-images me-1"></i> Biblioteca</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if (ImageOptimizationService::driver() === 'none'): ?>
<div class="alert alert-warning"><strong>GD/Imagick indisponível.</strong> Habilite uma dessas extensões no PHP para gerar variantes.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">Imagens suportadas</div><div class="display-6 fw-semibold"><?= (int)$stats['supported'] ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">Otimizadas</div><div class="display-6 fw-semibold"><?= (int)$stats['optimized'] ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">Pendentes</div><div class="display-6 fw-semibold"><?= (int)$pending ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary small">Variantes geradas</div><div class="display-6 fw-semibold"><?= (int)$stats['variants'] ?></div><div class="small text-secondary"><?= e(formatBytes((int)$stats['bytes'])) ?></div></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <form method="post" class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><strong>Configurações</strong></div>
            <div class="card-body p-4">
                <?= Csrf::field() ?><input type="hidden" name="action" value="settings">
                <div class="mb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="media_optimize_enabled" id="opt" <?= $settings['media_optimize_enabled']==='1'?'checked':'' ?>><label class="form-check-label" for="opt">Otimizar automaticamente novas imagens</label></div></div>
                <div class="mb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="media_generate_webp" id="webp" <?= $settings['media_generate_webp']==='1'?'checked':'' ?> <?= ImageOptimizationService::webpSupported()?'':'disabled' ?>><label class="form-check-label" for="webp">Gerar versões WebP</label></div><div class="form-text">WebP no servidor: <?= ImageOptimizationService::webpSupported() ? 'disponível' : 'indisponível' ?>.</div></div>
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label">Larguras das variantes</label><input class="form-control" name="media_variant_widths" value="<?= e($settings['media_variant_widths']) ?>" placeholder="320,640,1024,1600"><div class="form-text">Valores em pixels, separados por vírgula. Mínimo 160, máximo 4096.</div></div>
                    <div class="col-md-4"><label class="form-label">Qualidade</label><div class="input-group"><input class="form-control" type="number" min="50" max="95" name="media_image_quality" value="<?= e($settings['media_image_quality']) ?>"><span class="input-group-text">%</span></div></div>
                </div>
                <div class="mt-4"><button class="btn btn-primary">Salvar configurações</button></div>
            </div>
        </form>
    </div>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><strong>Processar biblioteca existente</strong></div>
            <div class="card-body p-4">
                <p class="text-secondary">O processamento pelo painel ocorre em lotes pequenos para reduzir risco de timeout. Para bibliotecas grandes, use o comando CLI incluído na atualização.</p>
                <dl class="row small mb-4"><dt class="col-5">Motor</dt><dd class="col-7"><?= e(ImageOptimizationService::driverLabel()) ?></dd><dt class="col-5">WebP</dt><dd class="col-7"><?= ImageOptimizationService::webpSupported()?'Sim':'Não' ?></dd><dt class="col-5">Pendentes</dt><dd class="col-7"><?= (int)$pending ?></dd></dl>
                <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="optimize_batch"><input type="hidden" name="limit" value="10"><button class="btn btn-outline-primary w-100" <?= $pending<=0 || ImageOptimizationService::driver()==='none'?'disabled':'' ?>><i class="bi bi-lightning-charge me-1"></i> Otimizar próximas 10</button></form>
                <div class="form-text mt-3">CLI: <code>php otimizar_imagens_v0.32.0.php</code></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center"><strong>Imagens recentes</strong><span class="text-secondary small">até 40</span></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Imagem</th><th>Original</th><th>Variantes</th><th>Status</th><th></th></tr></thead><tbody>
    <?php if (!$recent): ?><tr><td colspan="5" class="text-secondary py-4 text-center">Nenhuma imagem compatível.</td></tr><?php endif; ?>
    <?php foreach ($recent as $m): ?>
        <tr>
            <td><div class="d-flex align-items-center gap-2"><img src="<?= e(mediaUrl((string)$m['caminho'])) ?>" alt="" style="width:54px;height:42px;object-fit:cover;border-radius:6px"><span class="text-truncate" style="max-width:280px"><?= e((string)$m['nome_original']) ?></span></div></td>
            <td class="small"><?= e(formatBytes((int)$m['tamanho'])) ?><?php if($m['largura']&&$m['altura']): ?><br><span class="text-secondary"><?= (int)$m['largura'] ?>×<?= (int)$m['altura'] ?></span><?php endif; ?></td>
            <td class="small"><?= (int)$m['variantes'] ?><?php if((int)$m['variantes_bytes']>0): ?><br><span class="text-secondary"><?= e(formatBytes((int)$m['variantes_bytes'])) ?></span><?php endif; ?></td>
            <td><?= (int)$m['variantes']>0 ? '<span class="badge text-bg-success">Otimizada</span>' : '<span class="badge text-bg-warning">Pendente</span>' ?></td>
            <td class="text-end"><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="<?= (int)$m['variantes']>0?'regenerate_one':'optimize_one' ?>"><button class="btn btn-sm btn-outline-secondary" <?= ImageOptimizationService::driver()==='none'?'disabled':'' ?>><?= (int)$m['variantes']>0?'Regenerar':'Otimizar' ?></button></form></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
