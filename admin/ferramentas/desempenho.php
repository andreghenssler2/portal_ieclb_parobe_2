<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo = Database::connection();

if (!class_exists('PerformanceHealthService')) {
    throw new RuntimeException(
        'PerformanceHealthService indisponível.'
    );
}

$error = '';
$benchmark = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error =
            'Token de segurança inválido.';
    } else {
        try {
            $action =
                (string)(
                    $_POST['action']
                    ?? ''
                );

            if ($action === 'save') {
                $cacheEnabled =
                    isset(
                        $_POST['performance_cache_enabled']
                    )
                        ? '1'
                        : '0';

                $pageCacheEnabled =
                    isset(
                        $_POST['performance_page_cache_enabled']
                    )
                        ? '1'
                        : '0';

                $cacheTtl =
                    max(
                        30,
                        min(
                            86400,
                            (int)(
                                $_POST['performance_cache_ttl_seconds']
                                ?? 300
                            )
                        )
                    );

                $pageTtl =
                    max(
                        15,
                        min(
                            3600,
                            (int)(
                                $_POST['performance_page_cache_ttl_seconds']
                                ?? 120
                            )
                        )
                    );

                saveSiteConfig(
                    $pdo,
                    'performance_cache_enabled',
                    $cacheEnabled,
                    'booleano'
                );

                saveSiteConfig(
                    $pdo,
                    'performance_page_cache_enabled',
                    $pageCacheEnabled,
                    'booleano'
                );

                saveSiteConfig(
                    $pdo,
                    'performance_cache_ttl_seconds',
                    (string)$cacheTtl,
                    'numero'
                );

                saveSiteConfig(
                    $pdo,
                    'performance_page_cache_ttl_seconds',
                    (string)$pageTtl,
                    'numero'
                );

                if (
                    class_exists(
                        'CacheService'
                    )
                ) {
                    CacheService::configure(
                        $pdo
                    );
                }

                logAction(
                    $pdo,
                    'performance.configuracoes.atualizar',
                    'configuracoes',
                    null,
                    'Cache='
                    . $cacheEnabled
                    . '; PageCache='
                    . $pageCacheEnabled
                    . '; TTL='
                    . $cacheTtl
                    . '; PageTTL='
                    . $pageTtl
                );

                Session::flash(
                    'success',
                    'Configurações de desempenho atualizadas.'
                );

                header(
                    'Location: '
                    . url(
                        'admin/ferramentas/desempenho.php'
                    )
                );

                exit;
            }

            if ($action === 'cleanup') {
                $removed =
                    CacheService::cleanupExpired();

                logAction(
                    $pdo,
                    'performance.cache.limpar_expirado',
                    'cache',
                    null,
                    $removed
                    . ' arquivo(s) removido(s).'
                );

                Session::flash(
                    'success',
                    $removed
                    . ' arquivo(s) de cache expirado(s) removido(s).'
                );

                header(
                    'Location: '
                    . url(
                        'admin/ferramentas/desempenho.php'
                    )
                );

                exit;
            }

            if ($action === 'clear') {
                $removed =
                    CacheService::clearAll();

                logAction(
                    $pdo,
                    'performance.cache.limpar_tudo',
                    'cache',
                    null,
                    $removed
                    . ' arquivo(s) removido(s).',
                    'warning'
                );

                Session::flash(
                    'success',
                    $removed
                    . ' arquivo(s) de cache removido(s).'
                );

                header(
                    'Location: '
                    . url(
                        'admin/ferramentas/desempenho.php'
                    )
                );

                exit;
            }

            if ($action === 'benchmark') {
                $benchmark =
                    PerformanceHealthService::benchmark(
                        $pdo,
                        10
                    );

                logAction(
                    $pdo,
                    'performance.benchmark.executar',
                    'performance',
                    null,
                    'SELECT 1 médio='
                    . (string)(
                        $benchmark['database_ms']['average']
                        ?? '0'
                    )
                    . ' ms'
                );
            }

            if (
                !in_array(
                    $action,
                    [
                        'save',
                        'cleanup',
                        'clear',
                        'benchmark',
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Ação inválida.'
                );
            }
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

if (
    class_exists(
        'CacheService'
    )
) {
    CacheService::configure(
        $pdo
    );
}

$report =
    PerformanceHealthService::report(
        $pdo
    );

$settings = [
    'performance_cache_enabled' =>
        siteConfig(
            $pdo,
            'performance_cache_enabled',
            '1'
        ),
    'performance_page_cache_enabled' =>
        siteConfig(
            $pdo,
            'performance_page_cache_enabled',
            '1'
        ),
    'performance_cache_ttl_seconds' =>
        siteConfig(
            $pdo,
            'performance_cache_ttl_seconds',
            '300'
        ),
    'performance_page_cache_ttl_seconds' =>
        siteConfig(
            $pdo,
            'performance_page_cache_ttl_seconds',
            '120'
        ),
];

$pageTitle =
    'Desempenho';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Desempenho
        </h1>

        <p class="text-secondary mb-0">
            Estado do cache, OPcache, PHP, banco e benchmark rápido do Portal.
        </p>
    </div>
</div>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($report['errors']): ?>
    <div class="alert alert-danger">
        <strong>Problemas:</strong>
        <ul class="mb-0">
            <?php foreach ($report['errors'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($report['warnings']): ?>
    <div class="alert alert-warning">
        <strong>Pontos para revisar:</strong>
        <ul class="mb-0">
            <?php foreach ($report['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Cache de dados
                </div>
                <span class="badge text-bg-<?= $report['cache']['enabled'] ? 'success' : 'secondary' ?> mt-2">
                    <?= $report['cache']['enabled'] ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Cache da Home
                </div>
                <span class="badge text-bg-<?= $report['cache']['page_enabled'] ? 'success' : 'secondary' ?> mt-2">
                    <?= $report['cache']['page_enabled'] ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Arquivos de cache
                </div>
                <div class="display-6 fw-semibold">
                    <?= (int)$report['cache']['stats']['files'] ?>
                </div>
                <div class="small text-secondary">
                    <?= e(formatBytes((int)$report['cache']['stats']['bytes'])) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    OPcache
                </div>
                <span class="badge text-bg-<?= $report['php']['opcache_enabled'] ? 'success' : 'warning' ?> mt-2">
                    <?= $report['php']['opcache_enabled'] ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <form method="post" class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                Configuração de cache
            </div>

            <div class="card-body p-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save">

                <div class="form-check form-switch mb-3">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="performance_cache_enabled"
                        id="cacheEnabled"
                        <?= $settings['performance_cache_enabled'] !== '0' ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="cacheEnabled">
                        Ativar cache de dados
                    </label>
                </div>

                <div class="form-check form-switch mb-4">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="performance_page_cache_enabled"
                        id="pageCacheEnabled"
                        <?= $settings['performance_page_cache_enabled'] !== '0' ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="pageCacheEnabled">
                        Ativar cache da Home para visitantes anônimos
                    </label>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">
                            TTL padrão do cache
                        </label>
                        <div class="input-group">
                            <input
                                class="form-control"
                                type="number"
                                min="30"
                                max="86400"
                                name="performance_cache_ttl_seconds"
                                value="<?= e($settings['performance_cache_ttl_seconds']) ?>"
                            >
                            <span class="input-group-text">s</span>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            TTL da Home
                        </label>
                        <div class="input-group">
                            <input
                                class="form-control"
                                type="number"
                                min="15"
                                max="3600"
                                name="performance_page_cache_ttl_seconds"
                                value="<?= e($settings['performance_page_cache_ttl_seconds']) ?>"
                            >
                            <span class="input-group-text">s</span>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary mt-4">
                    Salvar desempenho
                </button>
            </div>
        </form>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                Manutenção do cache
            </div>

            <div class="card-body p-4">
                <p>
                    Expirados:
                    <strong><?= (int)$report['cache']['stats']['expired'] ?></strong>
                </p>

                <p class="small text-secondary">
                    Pasta:
                    <code class="text-break"><?= e((string)$report['cache']['stats']['path']) ?></code>
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="cleanup">
                        <button class="btn btn-outline-primary">
                            Limpar expirados
                        </button>
                    </form>

                    <form method="post" onsubmit="return confirm('Limpar todo o cache do Portal?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="clear">
                        <button class="btn btn-outline-danger">
                            Limpar todo cache
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>
            Benchmark rápido
        </strong>

        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="benchmark">
            <button class="btn btn-sm btn-outline-primary">
                Executar benchmark
            </button>
        </form>
    </div>

    <div class="card-body">
        <p class="small text-secondary">
            Executa 10 consultas <code>SELECT 1</code> e um ciclo temporário de escrita/leitura no cache. Nenhum dado do Portal é alterado.
        </p>

        <?php if (is_array($benchmark)): ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-secondary">Banco mínimo</div>
                    <strong><?= e((string)$benchmark['database_ms']['min']) ?> ms</strong>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Banco médio</div>
                    <strong><?= e((string)$benchmark['database_ms']['average']) ?> ms</strong>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Banco p95</div>
                    <strong><?= e((string)$benchmark['database_ms']['p95']) ?> ms</strong>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Banco máximo</div>
                    <strong><?= e((string)$benchmark['database_ms']['max']) ?> ms</strong>
                </div>

                <div class="col-md-4">
                    <div class="small text-secondary">Cache escrita</div>
                    <strong>
                        <?= $benchmark['cache']['write_ms'] !== null
                            ? e((string)$benchmark['cache']['write_ms']) . ' ms'
                            : '—' ?>
                    </strong>
                </div>

                <div class="col-md-4">
                    <div class="small text-secondary">Cache leitura</div>
                    <strong>
                        <?= $benchmark['cache']['read_ms'] !== null
                            ? e((string)$benchmark['cache']['read_ms']) . ' ms'
                            : '—' ?>
                    </strong>
                </div>

                <div class="col-md-4">
                    <div class="small text-secondary">Integridade cache</div>
                    <span class="badge text-bg-<?= !empty($benchmark['cache']['verified']) ? 'success' : 'secondary' ?>">
                        <?= !empty($benchmark['cache']['verified']) ? 'OK' : 'Não testado' ?>
                    </span>
                </div>
            </div>
        <?php else: ?>
            <span class="text-secondary">
                Ainda não executado.
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                PHP
            </div>

            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Versão</dt>
                    <dd class="col-sm-7"><?= e((string)$report['php']['version']) ?></dd>

                    <dt class="col-sm-5">SAPI</dt>
                    <dd class="col-sm-7"><?= e((string)$report['php']['sapi']) ?></dd>

                    <dt class="col-sm-5">memory_limit</dt>
                    <dd class="col-sm-7"><?= e((string)$report['php']['memory_limit']) ?></dd>

                    <dt class="col-sm-5">max_execution_time</dt>
                    <dd class="col-sm-7"><?= (int)$report['php']['max_execution_time'] ?> s</dd>

                    <dt class="col-sm-5">realpath_cache_size</dt>
                    <dd class="col-sm-7"><?= e((string)$report['php']['realpath_cache_size']) ?></dd>

                    <dt class="col-sm-5">realpath_cache_ttl</dt>
                    <dd class="col-sm-7"><?= (int)$report['php']['realpath_cache_ttl'] ?> s</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                Banco
            </div>

            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Driver</dt>
                    <dd class="col-sm-7"><?= e((string)$report['database']['driver']) ?></dd>

                    <dt class="col-sm-5">Servidor</dt>
                    <dd class="col-sm-7"><?= e((string)$report['database']['server_version']) ?></dd>

                    <dt class="col-sm-5">Banco</dt>
                    <dd class="col-sm-7"><?= e((string)$report['database']['database']) ?></dd>

                    <dt class="col-sm-5">Conexão</dt>
                    <dd class="col-sm-7 text-break"><?= e((string)$report['database']['connection_status']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
