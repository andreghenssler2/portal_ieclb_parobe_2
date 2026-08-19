<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/BackupService.php';
require_once __DIR__ . '/../../app/Services/FullBackupService.php';
Auth::requirePermission('backups.gerenciar');

$pdo = Database::connection();
$root = dirname(__DIR__, 2);
$type = (string)($_GET['tipo'] ?? 'db');
$name = (string)($_GET['arquivo'] ?? '');

try {
    if ($type === 'full') {
        $service = new FullBackupService($pdo, $root);
        $path = $service->fullBackupPath($name);
        $contentType = 'application/zip';
        $action = 'backup.completo.download';
    } else {
        $service = new BackupService($pdo, $root);
        $path = $service->backupPath($name);
        $contentType = str_ends_with($name, '.gz') ? 'application/gzip' : 'application/sql';
        $action = 'backup.banco.download';
    }

    logAction($pdo, $action, 'backup', null, $name);
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($name)) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    echo 'Backup não encontrado.';
}
