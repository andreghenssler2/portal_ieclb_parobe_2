<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$segments = array_values(array_filter(explode('/', trim(currentRelativePath(), '/')), static fn($v) => $v !== ''));
$slug = '';
if (count($segments) === 3) {
    $prefix = strtolower(rawurldecode((string)$segments[0]));
    $expected = permalinkPrefix('documento', $pdo);
    if (($prefix === $expected || $prefix === 'documento') && strtolower((string)$segments[2]) === 'baixar') {
        $candidate = strtolower(rawurldecode((string)$segments[1]));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $candidate)) {
            $slug = $candidate;
        }
    }
}

$document = $slug !== '' ? DocumentService::findPublishedBySlug($pdo, $slug) : null;
if (!$document || empty($document['midia_id'])) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$relative = ltrim(str_replace('\\', '/', (string)($document['caminho'] ?? '')), '/');
$root = realpath(__DIR__);
$absolute = $root !== false ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) : false;

if ($root === false || $absolute === false || !is_file($absolute) || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
    header('Location: ' . contentUrl('documento', (string)$document['slug']) . '?arquivo=indisponivel', true, 302);
    exit;
}

DocumentService::incrementDownload($pdo, (int)$document['id']);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$original = trim((string)($document['nome_original'] ?? ''));
if ($original === '') {
    $ext = trim((string)($document['extensao'] ?? ''));
    $original = (string)$document['slug'] . ($ext !== '' ? '.' . $ext : '');
}
$safeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $original) ?: 'download';
$safeAscii = trim($safeAscii, '.-') ?: 'download';

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: ' . ((string)($document['mime_type'] ?? '') ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: attachment; filename="' . addcslashes($safeAscii, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($original));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($absolute);
exit;
