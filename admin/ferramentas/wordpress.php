<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('wordpress.importar');
$pdo = Database::connection();
$user = Auth::user();
$userId = (int)($user['id'] ?? 0);

$allowed = WordPressImportService::allowedModules();
$module = (string)($_GET['modulo'] ?? $_POST['modulo'] ?? 'all');
if (!in_array($module, $allowed, true)) {
    $module = 'all';
}

$error = '';
$testResult = null;
$form = [
    'wordpress_url' => trim((string)($_POST['wordpress_url'] ?? '')),
    'username' => trim((string)($_POST['username'] ?? '')),
    'events_endpoint' => trim((string)($_POST['events_endpoint'] ?? '')),
    'mode' => (string)($_POST['mode'] ?? 'new'),
    'download_media' => !isset($_POST['action']) || isset($_POST['download_media']),
    'rewrite_media_urls' => !isset($_POST['action']) || isset($_POST['rewrite_media_urls']),
    'repair_remote_media' => !isset($_POST['action']) || isset($_POST['repair_remote_media']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'test');
            $password = (string)($_POST['application_password'] ?? '');
            $client = new WordPressImportService($pdo, $form['wordpress_url'], $form['username'], $password);
            $testResult = $client->testConnection();

            if ($action === 'start') {
                if (!in_array($form['mode'], ['new', 'update', 'simulate'], true)) {
                    throw new RuntimeException('Modo de importação inválido.');
                }
                $jobId = WordPressImportService::createJob(
                    $pdo,
                    $userId,
                    $form['wordpress_url'],
                    $module,
                    $form['mode'],
                    $form['events_endpoint'],
                    [
                        'download_media' => (bool)$form['download_media'],
                        'rewrite_media_urls' => (bool)$form['rewrite_media_urls'],
                        'repair_remote_media' => (bool)$form['repair_remote_media'],
                    ]
                );
                $_SESSION['wordpress_import_credentials'] ??= [];
                $_SESSION['wordpress_import_credentials'][$jobId] = [
                    'username' => $form['username'],
                    'application_password' => $password,
                ];
                if (function_exists('logAction')) {
                    logAction($pdo, 'wordpress.importacao.iniciar', 'wordpress_importacoes', $jobId, 'Origem: ' . $form['wordpress_url'] . ' | Módulo: ' . $module . ' | Modo: ' . $form['mode']);
                }
                header('Location: ' . url('admin/ferramentas/wordpress.php?modulo=' . rawurlencode($module) . '&job=' . $jobId));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$jobId = max(0, (int)($_GET['job'] ?? 0));
$job = null;
$jobSnapshot = null;
if ($jobId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM wordpress_importacoes WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch() ?: null;
    if ($job) {
        try {
            $creds = $_SESSION['wordpress_import_credentials'][$jobId] ?? ['username' => '', 'application_password' => ''];
            $viewer = new WordPressImportService($pdo, (string)$job['origem_url'], (string)($creds['username'] ?? ''), (string)($creds['application_password'] ?? ''));
            $jobSnapshot = $viewer->jobSnapshot($jobId);
        } catch (Throwable $e) {
            $error = $error ?: $e->getMessage();
        }
    }
}

$recent = [];
try {
    $recent = $pdo->query('SELECT id,origem_url,modulo,fase,modo,status,processados,criados,atualizados,ignorados,erros,created_at,finalizado_em FROM wordpress_importacoes ORDER BY id DESC LIMIT 15')->fetchAll() ?: [];
} catch (Throwable $ignored) {
}

$moduleLinks = [
    'all' => ['Importação completa', 'bi-arrow-repeat'],
    'posts' => ['Posts / Notícias', 'bi-file-earmark-text'],
    'categories' => ['Categorias', 'bi-folder'],
    'tags' => ['Tags', 'bi-tags'],
    'media' => ['Mídias', 'bi-images'],
    'pages' => ['Páginas', 'bi-files'],
    'events' => ['Eventos', 'bi-calendar-event'],
];
$pageTitle = 'Importar do WordPress';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Importar do WordPress</h1>
        <p class="text-secondary mb-0">Importe <?= e(WordPressImportService::moduleLabel($module)) ?> pela REST API, com controle contra duplicações.</p>
    </div>
    <span class="badge text-bg-light border fs-6">v<?= e(defined('APP_VERSION') ? (string)APP_VERSION : '0.27.2') ?></span>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-3">
        <div class="card border-0 shadow-sm sticky-xl-top" style="top:84px">
            <div class="card-header bg-white fw-semibold">O que importar</div>
            <div class="list-group list-group-flush">
                <?php foreach ($moduleLinks as $key => [$label, $icon]): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $module === $key ? 'active' : '' ?>" href="<?= e(url('admin/ferramentas/wordpress.php?modulo=' . rawurlencode($key))) ?>">
                        <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-9">
        <?php if ($job && $jobSnapshot): ?>
            <div class="card border-0 shadow-sm mb-4" id="importProgressCard" data-job-id="<?= (int)$jobId ?>">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-semibold">Importação #<?= (int)$jobId ?></span>
                        <span class="text-secondary ms-2"><?= e((string)$job['origem_url']) ?></span>
                    </div>
                    <span class="badge" id="jobStatusBadge"></span>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div><strong id="phaseLabel"><?= e((string)$jobSnapshot['phase_label']) ?></strong><span class="text-secondary ms-2" id="pageLabel"></span></div>
                        <span class="small text-secondary" id="progressPercent"><?= (int)$jobSnapshot['progress'] ?>%</span>
                    </div>
                    <div class="progress mb-4" role="progressbar" aria-label="Progresso da importação" style="height:10px">
                        <div class="progress-bar" id="progressBar" style="width:<?= (int)$jobSnapshot['progress'] ?>%"></div>
                    </div>

                    <div class="row g-3 text-center mb-4">
                        <div class="col-6 col-md"><div class="border rounded p-3"><div class="h4 mb-0" id="countProcessed"><?= (int)$jobSnapshot['processed'] ?></div><small class="text-secondary">Processados</small></div></div>
                        <div class="col-6 col-md"><div class="border rounded p-3"><div class="h4 mb-0 text-success" id="countCreated"><?= (int)$jobSnapshot['created'] ?></div><small class="text-secondary">Novos</small></div></div>
                        <div class="col-6 col-md"><div class="border rounded p-3"><div class="h4 mb-0 text-primary" id="countUpdated"><?= (int)$jobSnapshot['updated'] ?></div><small class="text-secondary">Atualizados</small></div></div>
                        <div class="col-6 col-md"><div class="border rounded p-3"><div class="h4 mb-0 text-secondary" id="countSkipped"><?= (int)$jobSnapshot['skipped'] ?></div><small class="text-secondary">Ignorados</small></div></div>
                        <div class="col-6 col-md"><div class="border rounded p-3"><div class="h4 mb-0 text-danger" id="countErrors"><?= (int)$jobSnapshot['errors'] ?></div><small class="text-secondary">Erros</small></div></div>
                    </div>

                    <div id="jobError" class="alert alert-danger d-none"></div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button class="btn btn-primary" type="button" id="continueImport"><i class="bi bi-play-fill me-1"></i>Continuar processamento</button>
                        <a class="btn btn-outline-secondary" href="<?= e(url('admin/ferramentas/wordpress.php?modulo=' . rawurlencode($module))) ?>">Nova importação</a>
                    </div>
                    <div class="border rounded bg-body-tertiary p-3">
                        <div class="fw-semibold small mb-2">Últimas mensagens</div>
                        <div id="jobLogs" class="small"></div>
                    </div>
                    <form id="wpProcessForm" class="d-none"><?= Csrf::field() ?></form>
                </div>
            </div>
        <?php else: ?>
            <form method="post" class="card border-0 shadow-sm mb-4" autocomplete="off">
                <div class="card-body p-4">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="modulo" value="<?= e($module) ?>">
                    <h2 class="h5 mb-3">Conexão com o WordPress</h2>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">URL do WordPress</label>
                            <input class="form-control" type="url" name="wordpress_url" value="<?= e($form['wordpress_url']) ?>" placeholder="https://site-antigo.org.br" required>
                            <div class="form-text">Informe a URL raiz. O Portal acessará <code>/wp-json/</code> e os endpoints REST do WordPress.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Usuário <span class="text-secondary fw-normal">(opcional)</span></label>
                            <input class="form-control" name="username" value="<?= e($form['username']) ?>" autocomplete="username">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Application Password <span class="text-secondary fw-normal">(opcional)</span></label>
                            <input class="form-control" type="password" name="application_password" autocomplete="new-password" placeholder="Use para conteúdo que exige autenticação">
                        </div>
                        <?php if ($module === 'events' || $module === 'all'): ?>
                            <div class="col-12">
                                <label class="form-label">Endpoint de eventos <span class="text-secondary fw-normal">(opcional)</span></label>
                                <input class="form-control" name="events_endpoint" value="<?= e($form['events_endpoint']) ?>" placeholder="Ex.: tribe_events ou /tribe/events/v1/events">
                                <div class="form-text">Deixe vazio para tentar detectar automaticamente um Custom Post Type de eventos.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <hr class="my-4">
                    <h2 class="h5 mb-3">Comportamento</h2>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Ao encontrar item já importado</label>
                            <select class="form-select" name="mode">
                                <option value="new" <?= $form['mode'] === 'new' ? 'selected' : '' ?>>Importar apenas novos</option>
                                <option value="update" <?= $form['mode'] === 'update' ? 'selected' : '' ?>>Importar novos e atualizar existentes</option>
                                <option value="simulate" <?= $form['mode'] === 'simulate' ? 'selected' : '' ?>>Simular — não gravar alterações</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <div class="form-check form-switch mt-md-4 pt-md-2">
                                <input class="form-check-input" type="checkbox" name="download_media" id="downloadMedia" <?= $form['download_media'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="downloadMedia">Baixar mídias para o Portal</label>
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="rewrite_media_urls" id="rewriteMedia" <?= $form['rewrite_media_urls'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rewriteMedia">Trocar URLs de mídia no conteúdo</label>
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="repair_remote_media" id="repairRemoteMedia" <?= $form['repair_remote_media'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="repairRemoteMedia">Reparar mídias que ainda usam o WordPress antigo</label>
                            </div>
                        </div>
                    </div>

                    <?php if ($module === 'all'): ?>
                        <div class="alert alert-info mt-4 mb-0">
                            <strong>Ordem da importação completa:</strong> Categorias → Tags → Mídias → Páginas → Posts / Notícias → Eventos. Essa ordem permite reconstruir relacionamentos e imagens destacadas quando a origem expõe os dados pela REST API.
                        </div>
                    <?php elseif (in_array($module, ['posts', 'pages', 'events'], true)): ?>
                        <div class="alert alert-info mt-4 mb-0">
                            <strong>Reparo automático de mídias:</strong> capas e imagens dentro do conteúdo que ainda apontem para o WordPress antigo serão verificadas novamente. O importador reconhece também tamanhos gerados pelo WordPress, como <code>-300x200</code> e <code>-768x512</code>, e pode substituir essas URLs pelo arquivo salvo no Portal. O modo <em>Importar apenas novos</em> faz esse reparo sem duplicar o post.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button class="btn btn-outline-primary" name="action" value="test"><i class="bi bi-plug me-1"></i>Testar conexão</button>
                        <button class="btn btn-primary" name="action" value="start"><i class="bi bi-cloud-download me-1"></i>Iniciar importação</button>
                    </div>
                </div>
            </form>

            <?php if ($testResult): ?>
                <div class="alert alert-success shadow-sm">
                    <div class="fw-semibold"><i class="bi bi-check-circle me-2"></i>Conexão realizada com sucesso<?= $testResult['name'] ? ' — ' . e((string)$testResult['name']) : '' ?>.</div>
                    <div class="small mt-1">REST API: <?= e((string)$testResult['url']) ?><?= $testResult['authenticated'] ? ' · autenticação informada' : ' · acesso público' ?></div>
                    <?php if (!empty($testResult['event_candidates'])): ?>
                        <div class="small mt-2"><strong>Eventos detectados:</strong>
                            <?php foreach ($testResult['event_candidates'] as $candidate): ?>
                                <span class="badge text-bg-light border me-1"><?= e((string)$candidate['name']) ?> — <?= e((string)$candidate['endpoint']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Histórico de importações</span><span class="text-secondary small">últimas 15</span></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>#</th><th>Origem</th><th>Módulo</th><th>Status</th><th>Resultado</th><th>Iniciada</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?><tr><td colspan="7" class="text-center text-secondary py-4">Nenhuma importação realizada.</td></tr><?php endif; ?>
                    <?php foreach ($recent as $row): ?>
                        <?php
                        $status = (string)$row['status'];
                        $statusClass = match ($status) { 'concluido' => 'success', 'falhou' => 'danger', 'processando' => 'primary', default => 'secondary' };
                        ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td class="text-truncate" style="max-width:260px" title="<?= e((string)$row['origem_url']) ?>"><?= e((string)$row['origem_url']) ?></td>
                            <td><?= e(WordPressImportService::moduleLabel((string)$row['modulo'])) ?></td>
                            <td><span class="badge text-bg-<?= e($statusClass) ?>"><?= e($status) ?></span></td>
                            <td class="small text-nowrap"><span class="text-success">+<?= (int)$row['criados'] ?></span> · <span class="text-primary">↻<?= (int)$row['atualizados'] ?></span> · <span class="text-danger">!<?= (int)$row['erros'] ?></span></td>
                            <td class="text-nowrap small"><?= e(date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></td>
                            <td><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/ferramentas/wordpress.php?modulo=' . rawurlencode((string)$row['modulo']) . '&job=' . (int)$row['id'])) ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($job && $jobSnapshot): ?>
<script>
(() => {
    const initial = <?= json_encode($jobSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const jobId = <?= (int)$jobId ?>;
    const endpoint = <?= json_encode(url('admin/ferramentas/wordpress-processar.php'), JSON_UNESCAPED_SLASHES) ?>;
    const token = document.querySelector('#wpProcessForm input[name="_token"]')?.value || '';
    const button = document.getElementById('continueImport');
    let running = false;

    const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    const statusMeta = (status) => ({
        aguardando: ['secondary', 'Aguardando'],
        processando: ['primary', 'Processando'],
        concluido: ['success', 'Concluído'],
        falhou: ['danger', 'Falhou'],
        cancelado: ['secondary', 'Cancelado']
    }[status] || ['secondary', status]);

    function render(data) {
        const [cls, label] = statusMeta(data.status);
        const badge = document.getElementById('jobStatusBadge');
        badge.className = 'badge text-bg-' + cls;
        badge.textContent = label;
        document.getElementById('phaseLabel').textContent = data.phase_label;
        document.getElementById('pageLabel').textContent = data.total_pages > 0 && data.status !== 'concluido' ? `Página ${Math.max(1, data.page)} de ${data.total_pages}` : '';
        document.getElementById('progressPercent').textContent = data.progress + '%';
        document.getElementById('progressBar').style.width = data.progress + '%';
        document.getElementById('countProcessed').textContent = data.processed;
        document.getElementById('countCreated').textContent = data.created;
        document.getElementById('countUpdated').textContent = data.updated;
        document.getElementById('countSkipped').textContent = data.skipped;
        document.getElementById('countErrors').textContent = data.errors;
        const error = document.getElementById('jobError');
        if (data.last_error) {
            error.textContent = data.last_error;
            error.classList.remove('d-none');
        } else {
            error.classList.add('d-none');
        }
        const logs = document.getElementById('jobLogs');
        logs.innerHTML = (data.logs || []).length ? data.logs.map(row => {
            const badgeClass = row.nivel === 'erro' ? 'danger' : (row.nivel === 'sucesso' ? 'success' : (row.nivel === 'aviso' ? 'warning' : 'secondary'));
            return `<div class="d-flex gap-2 py-1 border-bottom"><span class="badge text-bg-${badgeClass}">${esc(row.nivel)}</span><span>${esc(row.mensagem)}</span></div>`;
        }).join('') : '<span class="text-secondary">Sem mensagens.</span>';
        if (data.status === 'concluido') {
            button.classList.add('d-none');
        } else {
            button.classList.remove('d-none');
            button.innerHTML = data.status === 'falhou' ? '<i class="bi bi-arrow-clockwise me-1"></i>Tentar novamente' : '<i class="bi bi-play-fill me-1"></i>Continuar processamento';
        }
    }

    async function step(auto = false) {
        if (running) return;
        running = true;
        button.disabled = true;
        try {
            const body = new URLSearchParams({job_id: String(jobId), _token: token});
            const response = await fetch(endpoint, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'}, body});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Falha ao processar a importação.');
            render(data.job);
            if (data.job.status === 'processando' || data.job.status === 'aguardando') {
                window.setTimeout(() => step(true), 180);
            }
        } catch (err) {
            const box = document.getElementById('jobError');
            box.textContent = err.message || String(err);
            box.classList.remove('d-none');
        } finally {
            running = false;
            button.disabled = false;
        }
    }

    button.addEventListener('click', () => step(false));
    render(initial);
    if (initial.status === 'aguardando' || initial.status === 'processando') {
        window.setTimeout(() => step(true), 300);
    }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../_footer.php'; ?>
