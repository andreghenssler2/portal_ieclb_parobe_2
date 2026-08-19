<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/BackupService.php';
Auth::requirePermission('manutencao.gerenciar');
$pdo = Database::connection();
$error = '';

$themeDays = max(7, min(3650, (int)siteConfig($pdo, 'tools_theme_backup_retention_days', '90')));
$backupKeep = max(1, min(100, (int)siteConfig($pdo, 'backup_retention_count', '10')));
$auditDays = max(30, min(3650, (int)siteConfig($pdo, 'security_audit_retention_days', '180')));

function cleanupThemeBackups(string $root, int $days): array
{
    $dir = $root . '/storage/theme-backups';
    $removed = 0; $bytes = 0;
    if (!is_dir($dir)) return ['removed'=>0,'bytes'=>0];
    $threshold = time() - ($days * 86400);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if (!$file->isFile() || in_array($file->getFilename(), ['.htaccess','index.php'], true)) continue;
        if ($file->getMTime() < $threshold) {
            $size = $file->getSize();
            if (@unlink($file->getPathname())) { $removed++; $bytes += $size; }
        }
    }
    return ['removed'=>$removed,'bytes'=>$bytes];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['acao'] ?? '');
            if ($action === 'salvar_retencao') {
                $themeDays = max(7, min(3650, (int)($_POST['tools_theme_backup_retention_days'] ?? 90)));
                saveSiteConfig($pdo, 'tools_theme_backup_retention_days', (string)$themeDays, 'numero');
                Session::flash('success', 'Política de limpeza atualizada.');
            } elseif ($action === 'auditoria') {
                $beforeLogs = (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn();
                $pdo->exec('DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $auditDays . ' DAY)');
                $afterLogs = (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn();
                $attemptDays = max(30, min($auditDays, 365));
                try { $pdo->exec('DELETE FROM login_tentativas WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $attemptDays . ' DAY)'); } catch (Throwable $ignored) {}
                logAction($pdo, 'limpeza.auditoria', 'manutencao', null, 'Registros removidos: ' . max(0, $beforeLogs - $afterLogs));
                Session::flash('success', 'Histórico antigo de auditoria e tentativas de login foi limpo.');
            } elseif ($action === 'temas') {
                $result = cleanupThemeBackups(dirname(__DIR__, 2), $themeDays);
                logAction($pdo, 'limpeza.backups_tema', 'manutencao', null, 'Removidos: ' . $result['removed'] . ' | ' . formatBytes((int)$result['bytes']));
                Session::flash('success', $result['removed'] . ' backup(s) antigo(s) de temas removido(s), liberando ' . formatBytes((int)$result['bytes']) . '.');
            } elseif ($action === 'backups') {
                $service = new BackupService($pdo, dirname(__DIR__, 2));
                $removed = $service->pruneDatabaseBackups($backupKeep);
                logAction($pdo, 'limpeza.backups_banco', 'manutencao', null, 'Removidos: ' . $removed);
                Session::flash('success', $removed . ' backup(s) de banco excedente(s) removido(s).');
            } elseif ($action === 'tudo') {
                $beforeLogs = (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn();
                $pdo->exec('DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $auditDays . ' DAY)');
                try { $pdo->exec('DELETE FROM login_tentativas WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . max(30, min($auditDays,365)) . ' DAY)'); } catch (Throwable $ignored) {}
                $theme = cleanupThemeBackups(dirname(__DIR__, 2), $themeDays);
                $service = new BackupService($pdo, dirname(__DIR__, 2));
                $dbRemoved = $service->pruneDatabaseBackups($backupKeep);
                $afterLogs = (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn();
                logAction($pdo, 'limpeza.geral', 'manutencao', null, 'Logs: ' . max(0,$beforeLogs-$afterLogs) . '; temas: ' . $theme['removed'] . '; backups DB: ' . $dbRemoved);
                Session::flash('success', 'Limpeza geral concluída.');
            }
            header('Location: ' . url('admin/ferramentas/limpeza.php'));
            exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$stats = ['logs'=>0,'tentativas'=>0,'theme_files'=>0,'theme_bytes'=>0,'db_backups'=>0,'db_bytes'=>0];
try { $stats['logs'] = (int)$pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn(); } catch (Throwable $e) {}
try { $stats['tentativas'] = (int)$pdo->query('SELECT COUNT(*) FROM login_tentativas')->fetchColumn(); } catch (Throwable $e) {}
$themeDir = dirname(__DIR__, 2) . '/storage/theme-backups';
if (is_dir($themeDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile() && !in_array($file->getFilename(), ['.htaccess','index.php'], true)) { $stats['theme_files']++; $stats['theme_bytes'] += $file->getSize(); }
}
try { $bs = new BackupService($pdo, dirname(__DIR__, 2)); $bss = $bs->storageStats(); $stats['db_backups']=$bss['count']; $stats['db_bytes']=$bss['bytes']; } catch (Throwable $e) {}

$pageTitle = 'Limpeza';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h3 mb-1">Limpeza</h1><p class="text-secondary mb-0">Remova históricos e backups antigos respeitando as políticas de retenção.</p></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Registros de auditoria</div><div class="display-6 fw-semibold"><?= $stats['logs'] ?></div><div class="small text-secondary">retenção <?= $auditDays ?> dias</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Tentativas de login</div><div class="display-6 fw-semibold"><?= $stats['tentativas'] ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Backups de temas</div><div class="display-6 fw-semibold"><?= $stats['theme_files'] ?></div><div class="small text-secondary"><?= e(formatBytes($stats['theme_bytes'])) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary">Backups do banco</div><div class="display-6 fw-semibold"><?= $stats['db_backups'] ?></div><div class="small text-secondary"><?= e(formatBytes($stats['db_bytes'])) ?></div></div></div></div>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Retenção dos backups de temas</div><div class="card-body"><form method="post" class="row g-3 align-items-end"><?= Csrf::field() ?><input type="hidden" name="acao" value="salvar_retencao"><div class="col-md-4"><label class="form-label">Excluir backups de tema com mais de</label><div class="input-group"><input class="form-control" type="number" min="7" max="3650" name="tools_theme_backup_retention_days" value="<?= $themeDays ?>"><span class="input-group-text">dias</span></div></div><div class="col-md-auto"><button class="btn btn-outline-primary">Salvar</button></div></form></div></div>
<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Ações de limpeza</div><div class="list-group list-group-flush">
    <div class="list-group-item py-3 d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="fw-semibold">Auditoria e tentativas de login</div><div class="small text-secondary">Remove somente registros mais antigos que a retenção definida em Configurações > Segurança.</div></div><form method="post" onsubmit="return confirm('Executar a limpeza da auditoria agora?');"><?= Csrf::field() ?><input type="hidden" name="acao" value="auditoria"><button class="btn btn-outline-secondary">Limpar históricos antigos</button></form></div>
    <div class="list-group-item py-3 d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="fw-semibold">Backups antigos do Editor de Temas</div><div class="small text-secondary">Remove arquivos com mais de <?= $themeDays ?> dias.</div></div><form method="post"><?= Csrf::field() ?><input type="hidden" name="acao" value="temas"><button class="btn btn-outline-secondary">Limpar backups de temas</button></form></div>
    <div class="list-group-item py-3 d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="fw-semibold">Backups excedentes do banco</div><div class="small text-secondary">Mantém somente os <?= $backupKeep ?> backups mais recentes.</div></div><form method="post"><?= Csrf::field() ?><input type="hidden" name="acao" value="backups"><button class="btn btn-outline-secondary">Aplicar retenção</button></form></div>
    <div class="list-group-item py-3 d-flex flex-wrap justify-content-between align-items-center gap-3 bg-body-tertiary"><div><div class="fw-semibold">Limpeza geral</div><div class="small text-secondary">Executa todas as ações acima de uma só vez.</div></div><form method="post" onsubmit="return confirm('Executar todas as rotinas de limpeza?');"><?= Csrf::field() ?><input type="hidden" name="acao" value="tudo"><button class="btn btn-warning">Executar limpeza geral</button></form></div>
</div></div>
<?php require __DIR__ . '/../_footer.php'; ?>
