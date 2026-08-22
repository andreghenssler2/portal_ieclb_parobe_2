<?php

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('performance.gerenciar');
$pdo = Database::connection();
$pageTitle = 'Performance';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido. Atualize a página e tente novamente.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');
            if ($action === 'clear') {
                $removed = CacheService::clearAll();
                $success = 'Cache limpo com sucesso. ' . $removed . ' arquivo(s) removido(s).';
                logAction($pdo, 'performance.cache.limpar', 'cache', null, 'Arquivos removidos: ' . $removed);
            } elseif ($action === 'cleanup') {
                $removed = CacheService::cleanupExpired();
                $success = 'Cache expirado limpo. ' . $removed . ' arquivo(s) removido(s).';
                logAction($pdo, 'performance.cache.expirados', 'cache', null, 'Arquivos removidos: ' . $removed);
            } else {
                $enabled = isset($_POST['performance_cache_enabled']) ? '1' : '0';
                $pageEnabled = isset($_POST['performance_page_cache_enabled']) ? '1' : '0';
                $ttl = max(30, min(86400, (int)($_POST['performance_cache_ttl_seconds'] ?? 300)));
                $pageTtl = max(15, min(3600, (int)($_POST['performance_page_cache_ttl_seconds'] ?? 120)));

                saveSiteConfig($pdo, 'performance_cache_enabled', $enabled, 'booleano');
                saveSiteConfig($pdo, 'performance_page_cache_enabled', $pageEnabled, 'booleano');
                saveSiteConfig($pdo, 'performance_cache_ttl_seconds', (string)$ttl, 'numero');
                saveSiteConfig($pdo, 'performance_page_cache_ttl_seconds', (string)$pageTtl, 'numero');

                CacheService::clearAll();
                CacheService::configure($pdo);
                $success = 'Configurações de performance salvas. O cache anterior foi limpo.';
                logAction($pdo, 'performance.configuracoes.atualizar', 'configuracoes', null, 'Cache: ' . ($enabled === '1' ? 'ativo' : 'inativo') . '; Home: ' . ($pageEnabled === '1' ? 'ativa' : 'inativa'));
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$settings = siteConfigAll($pdo, true);
$enabled = ($settings['performance_cache_enabled'] ?? '1') !== '0';
$pageEnabled = ($settings['performance_page_cache_enabled'] ?? '1') !== '0';
$ttl = max(30, min(86400, (int)($settings['performance_cache_ttl_seconds'] ?? 300)));
$pageTtl = max(15, min(3600, (int)($settings['performance_page_cache_ttl_seconds'] ?? 120)));
$stats = CacheService::stats();

include __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Performance</h1>
        <p class="text-muted mb-0">Cache seguro em arquivos, cache da página inicial e otimizações de entrega pelo Apache.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="cleanup">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-hourglass-split me-1"></i>Limpar expirados</button>
        </form>
        <form method="post" onsubmit="return confirm('Limpar todo o cache do Portal?');">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="clear">
            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash3 me-1"></i>Limpar cache</button>
        </form>
    </div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Cache</div>
        <div class="fs-4 fw-semibold <?= $enabled ? 'text-success' : 'text-secondary' ?>"><?= $enabled ? 'Ativo' : 'Desativado' ?></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Arquivos em cache</div>
        <div class="fs-4 fw-semibold"><?= (int)$stats['files'] ?></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Espaço utilizado</div>
        <div class="fs-4 fw-semibold"><?= e(formatBytes((int)$stats['bytes'])) ?></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Pasta gravável</div>
        <div class="fs-4 fw-semibold <?= $stats['writable'] ? 'text-success' : 'text-danger' ?>"><?= $stats['writable'] ? 'Sim' : 'Não' ?></div>
    </div></div></div>
</div>

<form method="post" class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0">Cache do Portal</h2></div>
    <div class="card-body">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="save">

        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" role="switch" id="performance_cache_enabled" name="performance_cache_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="performance_cache_enabled">Ativar cache persistente</label>
            <div class="form-text">Reduz leituras repetidas da tabela de configurações e permite o cache seguro da Home.</div>
        </div>

        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" role="switch" id="performance_page_cache_enabled" name="performance_page_cache_enabled" value="1" <?= $pageEnabled ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="performance_page_cache_enabled">Cachear a Página Inicial para visitantes</label>
            <div class="form-text">Somente GET sem parâmetros e sem usuário autenticado. Painel, sessões e formulários nunca são armazenados.</div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="performance_cache_ttl_seconds">Validade do cache geral</label>
                <div class="input-group">
                    <input class="form-control" type="number" min="30" max="86400" id="performance_cache_ttl_seconds" name="performance_cache_ttl_seconds" value="<?= (int)$ttl ?>">
                    <span class="input-group-text">segundos</span>
                </div>
                <div class="form-text">Padrão: 300 segundos.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="performance_page_cache_ttl_seconds">Validade da Home</label>
                <div class="input-group">
                    <input class="form-control" type="number" min="15" max="3600" id="performance_page_cache_ttl_seconds" name="performance_page_cache_ttl_seconds" value="<?= (int)$pageTtl ?>">
                    <span class="input-group-text">segundos</span>
                </div>
                <div class="form-text">Padrão: 120 segundos. Alterações administrativas invalidam o cache automaticamente.</div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white py-3"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar alterações</button></div>
</form>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0">Otimizações do servidor</h2></div>
    <div class="card-body">
        <div class="d-flex gap-3 mb-3"><i class="bi bi-file-zip text-success fs-4"></i><div><strong>Compressão HTTP</strong><div class="text-muted small">A v0.31.0 habilita DEFLATE para HTML, CSS, JavaScript, JSON, XML e SVG quando <code>mod_deflate</code> estiver disponível.</div></div></div>
        <div class="d-flex gap-3"><i class="bi bi-clock-history text-success fs-4"></i><div><strong>Cache do navegador</strong><div class="text-muted small">Imagens e fontes recebem validade maior; CSS e JavaScript usam validade curta para equilibrar velocidade e atualizações.</div></div></div>
    </div>
</div>

<div class="alert alert-light border small">
    <strong>Pasta:</strong> <code><?= e((string)$stats['path']) ?></code>. Os arquivos são protegidos por <code>.htaccess</code> e podem ser apagados a qualquer momento; o Portal os recria automaticamente.
</div>

<?php include __DIR__ . '/../_footer.php'; ?>
