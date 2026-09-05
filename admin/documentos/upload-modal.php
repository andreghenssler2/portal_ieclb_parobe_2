<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('documentos.gerenciar');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode(
        [
            'ok' => false,
            'message' => 'Método não permitido.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if (!Csrf::validate($_POST['_token'] ?? null)) {
    http_response_code(419);

    echo json_encode(
        [
            'ok' => false,
            'message' =>
                'Token de segurança inválido. Recarregue a página e tente novamente.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$files =
    $_FILES['arquivos']
    ?? null;

if (
    !$files
    || !is_array($files['name'] ?? null)
) {
    http_response_code(422);

    echo json_encode(
        [
            'ok' => false,
            'message' => 'Selecione pelo menos um arquivo.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$pdo =
    Database::connection();

$uploaded = [];
$errors = [];

foreach ($files['name'] as $index => $name) {
    $error =
        (int)(
            $files['error'][$index]
            ?? UPLOAD_ERR_NO_FILE
        );

    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $file = [
        'name' =>
            (string)$name,
        'type' =>
            (string)(
                $files['type'][$index]
                ?? ''
            ),
        'tmp_name' =>
            (string)(
                $files['tmp_name'][$index]
                ?? ''
            ),
        'error' =>
            $error,
        'size' =>
            (int)(
                $files['size'][$index]
                ?? 0
            ),
    ];

    try {
        $media =
            MediaService::upload(
                $pdo,
                $file,
                (int)Auth::id()
            );

        if (
            str_starts_with(
                strtolower(
                    (string)($media['mime_type'] ?? '')
                ),
                'image/'
            )
        ) {
            MediaService::delete(
                $pdo,
                (int)$media['id']
            );

            throw new RuntimeException(
                'Imagens não podem ser usadas como documento.'
            );
        }

        $title =
            trim(
                (string)($media['titulo'] ?? '')
            )
            ?: (string)$media['nome_original'];

        $uploaded[] = [
            'id' =>
                (int)$media['id'],
            'title' =>
                $title,
            'fileName' =>
                (string)$media['nome_original'],
            'extension' =>
                strtoupper(
                    (string)($media['extensao'] ?? '')
                ),
            'size' =>
                (int)($media['tamanho'] ?? 0),
        ];

        logAction(
            $pdo,
            'midia.upload',
            'midias',
            (int)$media['id'],
            'Upload pelo seletor de documentos'
        );
    } catch (Throwable $e) {
        $errors[] =
            (string)$name
            . ': '
            . $e->getMessage();
    }
}

if (!$uploaded) {
    http_response_code(422);

    echo json_encode(
        [
            'ok' => false,
            'message' =>
                $errors
                    ? implode(' ', $errors)
                    : 'Nenhum arquivo foi enviado.',
            'errors' =>
                $errors,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

echo json_encode(
    [
        'ok' => true,
        'message' =>
            count($uploaded)
            . ' arquivo(s) enviado(s) com sucesso.',
        'items' =>
            $uploaded,
        'errors' =>
            $errors,
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);
