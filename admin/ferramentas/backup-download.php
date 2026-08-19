<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/BackupService.php';
Auth::requirePermission('backups.gerenciar');

$pdo = Database::connection();
$service = new BackupService($pdo, dirname(__DIR__, 2));
$name = (string)($_GET['arquivo'] ?? '');
try {
    $path = $service->backupPath($name);
    logAction($pdo, 'backup.download', 'backup', null, $name);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($name)) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    echo 'Backup não encontrado.';
}
