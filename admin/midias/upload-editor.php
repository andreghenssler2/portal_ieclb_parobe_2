<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$canUpload =
    Auth::can('midias.gerenciar')
    || Auth::can('noticias.gerenciar')
    || Auth::can('paginas.gerenciar')
    || Auth::can('eventos.gerenciar')
    || Auth::can('galerias.gerenciar')
    || Auth::can('configuracoes.gerenciar');

if (!$canUpload) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Você não possui permissão para enviar mídias.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Csrf::validate($_POST['_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Token de segurança inválido. Recarregue a página e tente novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = $_FILES['arquivos'] ?? null;
if (!$files || !is_array($files['name'] ?? null)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Selecione pelo menos uma imagem ou vídeo MP4.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Database::connection();
$uploaded = [];
$errors = [];

foreach ($files['name'] as $i => $name) {
    $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) continue;

    $file = [
        'name' => $name,
        'type' => $files['type'][$i] ?? '',
        'tmp_name' => $files['tmp_name'][$i] ?? '',
        'error' => $error,
        'size' => $files['size'][$i] ?? 0,
    ];

    try {
        $media = MediaService::upload($pdo, $file, (int)Auth::id());
        $isImage = MediaService::isImage($media);
        $isVideo = MediaService::isVideo($media);

        if (!$isImage && !$isVideo) {
            MediaService::delete($pdo, (int)$media['id']);
            throw new RuntimeException('Este seletor aceita somente imagens e vídeos MP4.');
        }

        logAction($pdo, 'midia.upload', 'midias', (int)$media['id'], 'Upload pelo editor de conteúdo');

        $title = trim((string)($media['titulo'] ?? '')) ?: (string)$media['nome_original'];
        $alt = trim((string)($media['alt_text'] ?? '')) ?: $title;

        $uploaded[] = [
            'id' => (int)$media['id'],
            'url' => mediaUrl((string)$media['caminho']),
            'title' => $title,
            'alt' => $alt,
            'fileName' => (string)$media['nome_original'],
            'width' => !empty($media['largura']) ? (int)$media['largura'] : null,
            'height' => !empty($media['altura']) ? (int)$media['altura'] : null,
            'kind' => $isVideo ? 'video' : 'image',
            'mime' => (string)($media['mime_type'] ?? ''),
        ];
    } catch (Throwable $e) {
        $errors[] = (string)$name . ': ' . $e->getMessage();
    }
}

if (!$uploaded) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => $errors ? implode(' ', $errors) : 'Nenhuma mídia foi enviada.',
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => count($uploaded) . ' mídia(s) enviada(s) com sucesso.',
    'items' => $uploaded,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
