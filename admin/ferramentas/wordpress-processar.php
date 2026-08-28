<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('wordpress.importar');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método não permitido.');
    }
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        throw new RuntimeException('Token de segurança inválido.');
    }
    $jobId = max(0, (int)($_POST['job_id'] ?? 0));
    if ($jobId <= 0) {
        throw new RuntimeException('Importação inválida.');
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM wordpress_importacoes WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();
    if (!$job) {
        throw new RuntimeException('Importação não encontrada.');
    }
    $user = Auth::user();
    $userId = (int)($user['id'] ?? 0);
    if ((int)($job['usuario_id'] ?? 0) > 0 && (int)$job['usuario_id'] !== $userId && !Auth::can('usuarios.gerenciar')) {
        throw new RuntimeException('Você não pode processar uma importação iniciada por outro usuário.');
    }

    $credentials = $_SESSION['wordpress_import_credentials'][$jobId] ?? ['username' => '', 'application_password' => ''];
    $service = new WordPressImportService(
        $pdo,
        (string)$job['origem_url'],
        (string)($credentials['username'] ?? ''),
        (string)($credentials['application_password'] ?? '')
    );
    $snapshot = $service->processJob($jobId, 50);
    if (($snapshot['status'] ?? '') === 'concluido') {
        unset($_SESSION['wordpress_import_credentials'][$jobId]);
        if (function_exists('logAction')) {
            logAction($pdo, 'wordpress.importacao.concluir', 'wordpress_importacoes', $jobId, 'Processados: ' . (int)$snapshot['processed'] . ' | Erros: ' . (int)$snapshot['errors']);
        }
    }
    echo json_encode(['ok' => true, 'job' => $snapshot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
