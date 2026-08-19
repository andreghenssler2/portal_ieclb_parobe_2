<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/BackupService.php';
Auth::requirePermission('backups.gerenciar');

$pdo = Database::connection();
$service = new BackupService($pdo, dirname(__DIR__, 2));
$error = '';
$retention = max(1, min(100, (int)siteConfig($pdo, 'backup_retention_count', '10')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['acao'] ?? '');
            if ($action === 'criar') {
                $info = $service->createDatabaseBackup('manual');
                $service->pruneDatabaseBackups($retention);
                logAction($pdo, 'backup.criar', 'backup', null, $info['name'] . ' | ' . formatBytes((int)$info['size']));
                Session::flash('success', 'Backup criado com sucesso: ' . $info['name']);
            } elseif ($action === 'excluir') {
                $name = (string)($_POST['arquivo'] ?? '');
                $service->deleteBackup($name);
                logAction($pdo, 'backup.excluir', 'backup', null, $name, 'warning');
                Session::flash('success', 'Backup excluído.');
            } elseif ($action === 'restaurar') {
                $name = (string)($_POST['arquivo'] ?? '');
                $confirm = trim((string)($_POST['confirmacao'] ?? ''));
                if ($confirm !== 'RESTAURAR') {
                    throw new RuntimeException('Digite RESTAURAR para confirmar a restauração.');
                }
                $result = $service->restoreDatabaseBackup($name, true);
                try { logAction(Database::connection(), 'backup.restaurar', 'backup', null, $name . ' | comandos ' . (int)$result['comandos'], 'critical'); } catch (Throwable $ignored) {}
                Session::flash('success', 'Backup restaurado com sucesso. Um backup de segurança do estado anterior foi criado automaticamente.');
            } elseif ($action === 'retencao') {
                $retention = max(1, min(100, (int)($_POST['backup_retention_count'] ?? 10)));
                saveSiteConfig($pdo, 'backup_retention_count', (string)$retention, 'numero');
                $removed = $service->pruneDatabaseBackups($retention);
                logAction($pdo, 'backup.retencao_atualizar', 'configuracoes', null, 'Manter ' . $retention . ' backup(s). Removidos: ' . $removed);
                Session::flash('success', 'Retenção atualizada. ' . $removed . ' backup(s) antigo(s) removido(s).');
            }
            header('Location: ' . url('admin/ferramentas/backups.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$backups = $service->listDatabaseBackups();
$stats = $service->storageStats();
$pageTitle = 'Backups';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Backups</h1>
        <p class="text-secondary mb-0">Crie, baixe e restaure cópias do banco de dados do portal.</p>
    </div>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="acao" value="criar">
        <button class="btn btn-primary"><i class="bi bi-database-add me-1"></i>Criar backup agora</button>
    </form>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>Os backups desta versão contêm o <strong>banco de dados</strong>. Arquivos enviados em <code>uploads/</code> não são duplicados aqui. Antes de qualquer restauração, o portal cria automaticamente um backup do banco atual.</div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Backups armazenados</div><div class="display-6 fw-semibold"><?= (int)$stats['count'] ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Espaço utilizado</div><div class="display-6 fw-semibold fs-2"><?= e(formatBytes((int)$stats['bytes'])) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Retenção</div><div class="display-6 fw-semibold"><?= $retention ?></div><div class="small text-secondary">mais recentes</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Armazenamento</div><div class="fw-semibold mt-2 <?= $stats['writable'] ? 'text-success' : 'text-danger' ?>"><?= $stats['writable'] ? 'Gravável' : 'Sem permissão' ?></div><div class="small text-secondary text-truncate" title="<?= e((string)$stats['path']) ?>">storage/backups</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Política de retenção</div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="retencao">
            <div class="col-md-5 col-xl-3"><label class="form-label">Manter os backups mais recentes</label><input class="form-control" type="number" min="1" max="100" name="backup_retention_count" value="<?= $retention ?>"></div>
            <div class="col-md-auto"><button class="btn btn-outline-primary">Salvar e limpar excedentes</button></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Backups do banco</span><span class="badge text-bg-secondary"><?= count($backups) ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Data</th><th>Arquivo</th><th>Tamanho</th><th>SHA-256</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$backups): ?><tr><td colspan="5" class="text-center text-secondary py-5">Nenhum backup criado ainda.</td></tr><?php endif; ?>
            <?php foreach ($backups as $item): ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', (int)$item['mtime'])) ?></td>
                    <td><code><?= e($item['name']) ?></code><?php if ($item['gzip']): ?> <span class="badge text-bg-light border">GZIP</span><?php endif; ?></td>
                    <td class="text-nowrap"><?= e(formatBytes((int)$item['size'])) ?></td>
                    <td><code class="small" title="<?= e($item['sha256']) ?>"><?= e(substr((string)$item['sha256'], 0, 12)) ?>…</code></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/ferramentas/backup-download.php?arquivo=' . rawurlencode($item['name']))) ?>"><i class="bi bi-download"></i></a>
                        <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#restoreModal" data-backup="<?= e($item['name']) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir este backup permanentemente?');">
                            <?= Csrf::field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="arquivo" value="<?= e($item['name']) ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="restaurar">
            <input type="hidden" name="arquivo" id="restoreBackupName">
            <div class="modal-header"><h5 class="modal-title">Restaurar backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-warning"><strong>Atenção:</strong> a restauração substitui os dados atuais do banco pelos dados existentes no backup selecionado.</div>
                <p class="mb-2">Backup: <code id="restoreBackupLabel"></code></p>
                <label class="form-label">Digite <strong>RESTAURAR</strong> para confirmar</label>
                <input class="form-control" name="confirmacao" autocomplete="off" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning">Restaurar banco</button></div>
        </form>
    </div></div>
</div>
<script>
document.getElementById('restoreModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const name = button?.getAttribute('data-backup') || '';
    document.getElementById('restoreBackupName').value = name;
    document.getElementById('restoreBackupLabel').textContent = name;
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
