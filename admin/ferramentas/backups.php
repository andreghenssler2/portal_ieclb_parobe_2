<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/BackupService.php';
require_once __DIR__ . '/../../app/Services/FullBackupService.php';
require_once __DIR__ . '/../../app/Services/AutomaticBackupService.php';
Auth::requirePermission('backups.gerenciar');

$pdo = Database::connection();
$root = dirname(__DIR__, 2);
$dbService = new BackupService($pdo, $root);
$fullService = new FullBackupService($pdo, $root);
$error = '';
$dbRetention = max(1, min(100, (int)siteConfig($pdo, 'backup_retention_count', '10')));
$fullRetention = max(1, min(50, (int)siteConfig($pdo, 'backup_full_retention_count', '5')));
$includeUploads = siteConfig($pdo, 'backup_full_include_uploads', '1') === '1';
$includeThemes = siteConfig($pdo, 'backup_full_include_themes', '1') === '1';
$automaticBackupStatus =
    AutomaticBackupService::status(
        $pdo,
        $root
    );

$automaticDb =
    (array)(
        $automaticBackupStatus['database']
        ?? []
    );

$automaticFull =
    (array)(
        $automaticBackupStatus['full']
        ?? []
    );


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['acao'] ?? '');

            if ($action === 'criar_db') {
                $info = $dbService->createDatabaseBackup('manual');
                $dbService->pruneDatabaseBackups($dbRetention);
                logAction($pdo, 'backup.banco.criar', 'backup', null, $info['name'] . ' | ' . formatBytes((int)$info['size']));
                Session::flash('success', 'Backup do banco criado com sucesso: ' . $info['name']);
            } elseif ($action === 'criar_completo') {
                $includeUploads = isset($_POST['incluir_uploads']);
                $includeThemes = isset($_POST['incluir_temas']);
                saveSiteConfig($pdo, 'backup_full_include_uploads', $includeUploads ? '1' : '0', 'booleano');
                saveSiteConfig($pdo, 'backup_full_include_themes', $includeThemes ? '1' : '0', 'booleano');
                $info = $fullService->createFullBackup('manual', $includeUploads, $includeThemes);
                $fullService->pruneFullBackups($fullRetention);
                logAction($pdo, 'backup.completo.criar', 'backup', null, $info['name'] . ' | ' . formatBytes((int)$info['size']));
                Session::flash('success', 'Backup completo criado com sucesso: ' . $info['name']);
            } elseif ($action === 'excluir_db') {
                $name = (string)($_POST['arquivo'] ?? '');
                $dbService->deleteBackup($name);
                logAction($pdo, 'backup.banco.excluir', 'backup', null, $name, 'warning');
                Session::flash('success', 'Backup do banco excluído.');
            } elseif ($action === 'excluir_completo') {
                $name = (string)($_POST['arquivo'] ?? '');
                $fullService->deleteFullBackup($name);
                logAction($pdo, 'backup.completo.excluir', 'backup', null, $name, 'warning');
                Session::flash('success', 'Backup completo excluído.');
            } elseif ($action === 'restaurar_db') {
                $name = (string)($_POST['arquivo'] ?? '');
                $confirm = trim((string)($_POST['confirmacao'] ?? ''));
                if ($confirm !== 'RESTAURAR') {
                    throw new RuntimeException('Digite RESTAURAR para confirmar a restauração.');
                }
                $result = $dbService->restoreDatabaseBackup($name, true);
                try {
                    logAction(Database::connection(), 'backup.banco.restaurar', 'backup', null, $name . ' | comandos ' . (int)$result['comandos'], 'critical');
                } catch (Throwable $ignored) {}
                Session::flash('success', 'Banco restaurado com sucesso. Um backup de segurança do estado anterior foi criado automaticamente.');
            } elseif ($action === 'restaurar_completo') {
                $name = (string)($_POST['arquivo'] ?? '');
                $confirm = trim((string)($_POST['confirmacao'] ?? ''));
                if ($confirm !== 'RESTAURAR COMPLETO') {
                    throw new RuntimeException('Digite RESTAURAR COMPLETO para confirmar.');
                }
                $restoreDb = isset($_POST['restaurar_banco']);
                $restoreUploads = isset($_POST['restaurar_uploads']);
                $restoreThemes = isset($_POST['restaurar_temas']);
                $result = $fullService->restoreFullBackup($name, $restoreDb, $restoreUploads, $restoreThemes, true);
                try {
                    logAction(
                        Database::connection(),
                        'backup.completo.restaurar',
                        'backup',
                        null,
                        $name . ' | SQL ' . (int)$result['database_commands'] . ' | uploads ' . (int)$result['uploads_files'] . ' | temas ' . (int)$result['theme_files'],
                        'critical'
                    );
                } catch (Throwable $ignored) {}
                Session::flash('success', 'Backup completo restaurado. Antes da restauração foi criado um backup completo de segurança.');
            } elseif ($action === 'retencao') {
                $dbRetention = max(1, min(100, (int)($_POST['backup_retention_count'] ?? 10)));
                $fullRetention = max(1, min(50, (int)($_POST['backup_full_retention_count'] ?? 5)));
                saveSiteConfig($pdo, 'backup_retention_count', (string)$dbRetention, 'numero');
                saveSiteConfig($pdo, 'backup_full_retention_count', (string)$fullRetention, 'numero');
                $removedDb = $dbService->pruneDatabaseBackups($dbRetention);
                $removedFull = $fullService->pruneFullBackups($fullRetention);
                logAction($pdo, 'backup.retencao_atualizar', 'configuracoes', null, 'Banco=' . $dbRetention . ', completos=' . $fullRetention . '. Removidos: ' . ($removedDb + $removedFull));
                Session::flash('success', 'Retenção atualizada. ' . ($removedDb + $removedFull) . ' backup(s) antigo(s) removido(s).');
            }

            header('Location: ' . url('admin/ferramentas/backups.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$dbBackups = $dbService->listDatabaseBackups();
$fullBackups = $fullService->listFullBackups();
$dbStats = $dbService->storageStats();
$fullStats = $fullService->storageStats();
$pageTitle = 'Backups';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Backups</h1>
        <p class="text-secondary mb-0">Backups do banco e cópias completas do conteúdo do portal.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="criar_db">
            <button class="btn btn-outline-primary"><i class="bi bi-database-add me-1"></i>Backup do banco</button>
        </form>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createFullModal" <?= $fullService->isSupported() ? '' : 'disabled' ?>>
            <i class="bi bi-file-earmark-zip me-1"></i>Backup completo
        </button>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if (!$fullService->isSupported()): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div><strong>Backup completo indisponível:</strong> a extensão PHP <code>ZipArchive</code> não está habilitada. O backup do banco continua funcionando normalmente.</div>
</div>
<?php else: ?>
<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-shield-check mt-1"></i>
    <div>
        O backup completo inclui o <strong>banco</strong> e, conforme selecionado, <strong>uploads</strong> e <strong>temas</strong>. O arquivo <code>config/config.php</code> com credenciais <strong>não é incluído nem restaurado automaticamente</strong>. Antes de uma restauração completa, o portal cria outra cópia completa de segurança.
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Backups do banco</div><div class="display-6 fw-semibold"><?= (int)$dbStats['count'] ?></div><div class="small text-secondary"><?= e(formatBytes((int)$dbStats['bytes'])) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Backups completos</div><div class="display-6 fw-semibold"><?= (int)$fullStats['count'] ?></div><div class="small text-secondary"><?= e(formatBytes((int)$fullStats['bytes'])) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Retenção banco</div><div class="display-6 fw-semibold"><?= $dbRetention ?></div><div class="small text-secondary">mais recentes</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Retenção completos</div><div class="display-6 fw-semibold"><?= $fullRetention ?></div><div class="small text-secondary">mais recentes</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">
            <i class="bi bi-clock-history me-1"></i>
            Backups automáticos
        </span>

        <a
            class="btn btn-sm btn-outline-primary"
            href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
        >
            Configurar tarefas
        </a>
    </div>

    <div class="card-body">
        <p class="text-secondary">
            O Agendador reutiliza os mesmos serviços, retenções e opções desta tela.
            O backup do banco fica ativo diariamente por padrão; o backup completo
            fica desativado até você decidir habilitá-lo.
        </p>

        <div class="row g-3">
            <div class="col-xl-6">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <strong>Banco de dados</strong>

                        <span class="badge <?= !empty($automaticDb['active']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= !empty($automaticDb['active']) ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </div>

                    <div class="small text-secondary mb-1">
                        Intervalo:
                        <strong>
                            <?= isset($automaticDb['interval'])
                                ? (int)$automaticDb['interval'] . ' min'
                                : 'não registrado' ?>
                        </strong>
                    </div>

                    <div class="small text-secondary mb-1">
                        Próxima execução:
                        <strong>
                            <?= !empty($automaticDb['next_run'])
                                ? e(formatDateBr((string)$automaticDb['next_run']))
                                : 'não definida' ?>
                        </strong>
                    </div>

                    <div class="small text-secondary mb-1">
                        Última execução:
                        <strong>
                            <?= !empty($automaticDb['last_finished'])
                                ? e(formatDateBr((string)$automaticDb['last_finished']))
                                : 'ainda não executado' ?>
                        </strong>
                    </div>

                    <?php if (!empty($automaticDb['last_status'])): ?>
                        <div class="small text-secondary mb-1">
                            Último status:
                            <span class="badge <?= $automaticDb['last_status'] === 'erro' ? 'text-bg-danger' : ($automaticDb['last_status'] === 'ignorado' ? 'text-bg-warning' : 'text-bg-success') ?>">
                                <?= e((string)$automaticDb['last_status']) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($automaticDb['latest_file'])): ?>
                        <hr>

                        <div class="small">
                            <div class="text-secondary">
                                Último arquivo automático
                            </div>

                            <code>
                                <?= e((string)$automaticDb['latest_file']['name']) ?>
                            </code>

                            <div class="text-secondary mt-1">
                                <?= e(formatBytes((int)$automaticDb['latest_file']['size'])) ?>
                                ·
                                <?= e(date('d/m/Y H:i:s', (int)$automaticDb['latest_file']['mtime'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <strong>Backup completo</strong>

                        <span class="badge <?= !empty($automaticFull['active']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= !empty($automaticFull['active']) ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </div>

                    <div class="small text-secondary mb-1">
                        Intervalo:
                        <strong>
                            <?= isset($automaticFull['interval'])
                                ? (int)$automaticFull['interval'] . ' min'
                                : 'não registrado' ?>
                        </strong>
                    </div>

                    <div class="small text-secondary mb-1">
                        Próxima execução:
                        <strong>
                            <?= !empty($automaticFull['next_run'])
                                ? e(formatDateBr((string)$automaticFull['next_run']))
                                : 'não definida' ?>
                        </strong>
                    </div>

                    <div class="small text-secondary mb-1">
                        Última execução:
                        <strong>
                            <?= !empty($automaticFull['last_finished'])
                                ? e(formatDateBr((string)$automaticFull['last_finished']))
                                : 'ainda não executado' ?>
                        </strong>
                    </div>

                    <?php if (empty($automaticBackupStatus['zip_supported'])): ?>
                        <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                            ZipArchive não está disponível; o backup completo automático
                            será ignorado enquanto a extensão não estiver ativa.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($automaticFull['latest_file'])): ?>
                        <hr>

                        <div class="small">
                            <div class="text-secondary">
                                Último arquivo automático
                            </div>

                            <code>
                                <?= e((string)$automaticFull['latest_file']['name']) ?>
                            </code>

                            <div class="text-secondary mt-1">
                                <?= e(formatBytes((int)$automaticFull['latest_file']['size'])) ?>
                                ·
                                <?= e(date('d/m/Y H:i:s', (int)$automaticFull['latest_file']['mtime'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-text mt-3">
            As execuções dependem do cron.php configurado no servidor.
        </div>
    </div>
</div>
<div class="card-header bg-white fw-semibold">Política de retenção</div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="retencao">
            <div class="col-md-4 col-xl-3">
                <label class="form-label">Manter backups do banco</label>
                <input class="form-control" type="number" min="1" max="100" name="backup_retention_count" value="<?= $dbRetention ?>">
            </div>
            <div class="col-md-4 col-xl-3">
                <label class="form-label">Manter backups completos</label>
                <input class="form-control" type="number" min="1" max="50" name="backup_full_retention_count" value="<?= $fullRetention ?>">
            </div>
            <div class="col-md-auto"><button class="btn btn-outline-primary">Salvar e limpar excedentes</button></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Backups completos</span><span class="badge text-bg-secondary"><?= count($fullBackups) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Data</th><th>Arquivo</th><th>Tamanho</th><th>SHA-256</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$fullBackups): ?><tr><td colspan="5" class="text-center text-secondary py-5">Nenhum backup completo criado ainda.</td></tr><?php endif; ?>
            <?php foreach ($fullBackups as $item): ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', (int)$item['mtime'])) ?></td>
                    <td><code><?= e($item['name']) ?></code> <span class="badge text-bg-primary">ZIP</span></td>
                    <td class="text-nowrap"><?= e(formatBytes((int)$item['size'])) ?></td>
                    <td><code class="small" title="<?= e($item['sha256']) ?>"><?= e(substr((string)$item['sha256'], 0, 12)) ?>…</code></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" title="Baixar" href="<?= e(url('admin/ferramentas/backup-download.php?tipo=full&arquivo=' . rawurlencode($item['name']))) ?>"><i class="bi bi-download"></i></a>
                        <?php if ($fullService->isSupported()): ?>
                            <button class="btn btn-sm btn-outline-warning" title="Restaurar" type="button" data-bs-toggle="modal" data-bs-target="#restoreFullModal" data-backup="<?= e($item['name']) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este backup completo permanentemente?');">
                            <?= Csrf::field() ?><input type="hidden" name="acao" value="excluir_completo"><input type="hidden" name="arquivo" value="<?= e($item['name']) ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Backups do banco</span><span class="badge text-bg-secondary"><?= count($dbBackups) ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Data</th><th>Arquivo</th><th>Tamanho</th><th>SHA-256</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$dbBackups): ?><tr><td colspan="5" class="text-center text-secondary py-5">Nenhum backup do banco criado ainda.</td></tr><?php endif; ?>
            <?php foreach ($dbBackups as $item): ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', (int)$item['mtime'])) ?></td>
                    <td><code><?= e($item['name']) ?></code><?php if ($item['gzip']): ?> <span class="badge text-bg-light border">GZIP</span><?php endif; ?></td>
                    <td class="text-nowrap"><?= e(formatBytes((int)$item['size'])) ?></td>
                    <td><code class="small" title="<?= e($item['sha256']) ?>"><?= e(substr((string)$item['sha256'], 0, 12)) ?>…</code></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/ferramentas/backup-download.php?tipo=db&arquivo=' . rawurlencode($item['name']))) ?>"><i class="bi bi-download"></i></a>
                        <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#restoreDbModal" data-backup="<?= e($item['name']) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este backup permanentemente?');">
                            <?= Csrf::field() ?><input type="hidden" name="acao" value="excluir_db"><input type="hidden" name="arquivo" value="<?= e($item['name']) ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="createFullModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= Csrf::field() ?><input type="hidden" name="acao" value="criar_completo">
            <div class="modal-header"><h5 class="modal-title">Criar backup completo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="text-secondary">O banco de dados é sempre incluído.</p>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="incluir_uploads" id="backupUploads" <?= $includeUploads ? 'checked' : '' ?>><label class="form-check-label" for="backupUploads">Incluir <code>uploads/</code> (imagens e documentos)</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="incluir_temas" id="backupThemes" <?= $includeThemes ? 'checked' : '' ?>><label class="form-check-label" for="backupThemes">Incluir <code>theme/</code></label></div>
                <div class="alert alert-secondary small mt-3 mb-0">Credenciais de <code>config/config.php</code> não são incluídas no ZIP.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-file-earmark-zip me-1"></i>Criar ZIP</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="restoreDbModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= Csrf::field() ?><input type="hidden" name="acao" value="restaurar_db"><input type="hidden" name="arquivo" id="restoreDbName">
            <div class="modal-header"><h5 class="modal-title">Restaurar banco</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-warning"><strong>Atenção:</strong> a restauração substitui os dados atuais do banco.</div>
                <p>Backup: <code id="restoreDbLabel"></code></p>
                <label class="form-label">Digite <strong>RESTAURAR</strong></label>
                <input class="form-control" name="confirmacao" autocomplete="off" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning">Restaurar banco</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="restoreFullModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= Csrf::field() ?><input type="hidden" name="acao" value="restaurar_completo"><input type="hidden" name="arquivo" id="restoreFullName">
            <div class="modal-header"><h5 class="modal-title">Restaurar backup completo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-danger"><strong>Atenção:</strong> esta ação pode alterar banco, uploads e temas. Um backup completo de segurança será criado antes.</div>
                <p>Backup: <code id="restoreFullLabel"></code></p>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restaurar_banco" id="restoreFullDb" checked><label class="form-check-label" for="restoreFullDb">Restaurar banco de dados</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restaurar_uploads" id="restoreFullUploads" checked><label class="form-check-label" for="restoreFullUploads">Restaurar uploads</label></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="restaurar_temas" id="restoreFullThemes" checked><label class="form-check-label" for="restoreFullThemes">Restaurar temas</label></div>
                <div class="small text-secondary mb-3">Arquivos do backup sobrescrevem arquivos com o mesmo nome. Arquivos criados depois do backup não são apagados automaticamente.</div>
                <label class="form-label">Digite <strong>RESTAURAR COMPLETO</strong></label>
                <input class="form-control" name="confirmacao" autocomplete="off" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Restaurar selecionados</button></div>
        </form>
    </div></div>
</div>

<script>
document.getElementById('restoreDbModal')?.addEventListener('show.bs.modal', function (event) {
    const name = event.relatedTarget?.getAttribute('data-backup') || '';
    document.getElementById('restoreDbName').value = name;
    document.getElementById('restoreDbLabel').textContent = name;
});
document.getElementById('restoreFullModal')?.addEventListener('show.bs.modal', function (event) {
    const name = event.relatedTarget?.getAttribute('data-backup') || '';
    document.getElementById('restoreFullName').value = name;
    document.getElementById('restoreFullLabel').textContent = name;
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
