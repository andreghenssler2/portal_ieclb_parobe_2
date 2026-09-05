<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/*
 * v0.84.0 R3
 *
 * Endpoint NOVO para evitar cache/opcache do autosave.php antigo.
 * Se o bootstrap ainda não tiver carregado o serviço, usamos um arquivo
 * exclusivo da R3, evitando depender do estado das tentativas anteriores.
 */
if (!class_exists('ContentAutosaveService')) {
    $r3ServiceFile =
        __DIR__
        . '/../app/Services/ContentAutosaveServiceV84R3.php';

    if (is_file($r3ServiceFile)) {
        require_once $r3ServiceFile;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function autosaveV84R3Json(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if (!Auth::check()) {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Sua sessão expirou.',
            'login_required' => true,
            'endpoint' => 'v0.84.0-R3',
        ],
        401
    );
}

if (!class_exists('ContentAutosaveService')) {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Serviço de autosave R3 não carregado.',
            'endpoint' => 'v0.84.0-R3',
            'service_file_exists' =>
                is_file(
                    __DIR__
                    . '/../app/Services/ContentAutosaveServiceV84R3.php'
                ),
        ],
        503
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Método não permitido.',
            'endpoint' => 'v0.84.0-R3',
        ],
        405
    );
}

if (!Csrf::validate($_POST['_token'] ?? null)) {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Token de segurança inválido.',
            'endpoint' => 'v0.84.0-R3',
        ],
        419
    );
}

$type =
    strtolower(
        trim(
            (string)(
                $_POST['tipo']
                ?? ''
            )
        )
    );

$permission =
    match ($type) {
        'post' =>
            'noticias.gerenciar',
        'pagina' =>
            'paginas.gerenciar',
        default =>
            '',
    };

if (
    $permission === ''
    || !Auth::can($permission)
) {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Você não possui permissão para este autosave.',
            'endpoint' => 'v0.84.0-R3',
        ],
        403
    );
}

$userId =
    (int)Auth::id();

$contentId =
    max(
        0,
        (int)(
            $_POST['content_id']
            ?? 0
        )
    );

$action =
    strtolower(
        trim(
            (string)(
                $_POST['action']
                ?? ''
            )
        )
    );

$pdo =
    Database::connection();

try {
    ContentAutosaveService::ensureSchema($pdo);

    if ($action === 'load') {
        $row =
            ContentAutosaveService::find(
                $pdo,
                $userId,
                $type,
                $contentId
            );

        autosaveV84R3Json(
            [
                'ok' => true,
                'draft' => $row,
                'endpoint' => 'v0.84.0-R3',
            ]
        );
    }

    if ($action === 'save') {
        $raw =
            (string)(
                $_POST['payload']
                ?? ''
            );

        if ($raw === '') {
            autosaveV84R3Json(
                [
                    'ok' => false,
                    'error' => 'Rascunho vazio.',
                    'endpoint' => 'v0.84.0-R3',
                ],
                422
            );
        }

        if (strlen($raw) > 4 * 1024 * 1024) {
            autosaveV84R3Json(
                [
                    'ok' => false,
                    'error' => 'O rascunho automático ultrapassou 4 MB.',
                    'endpoint' => 'v0.84.0-R3',
                ],
                413
            );
        }

        $draft =
            json_decode(
                $raw,
                true
            );

        if (!is_array($draft)) {
            autosaveV84R3Json(
                [
                    'ok' => false,
                    'error' => 'Formato de rascunho inválido.',
                    'endpoint' => 'v0.84.0-R3',
                ],
                422
            );
        }

        $row =
            ContentAutosaveService::save(
                $pdo,
                $userId,
                $type,
                $contentId,
                $draft
            );

        autosaveV84R3Json(
            [
                'ok' => true,
                'updated_at' =>
                    $row['updated_at']
                    ?? null,
                'endpoint' => 'v0.84.0-R3',
            ]
        );
    }

    if ($action === 'delete') {
        $deleted =
            ContentAutosaveService::delete(
                $pdo,
                $userId,
                $type,
                $contentId
            );

        autosaveV84R3Json(
            [
                'ok' => true,
                'deleted' => $deleted,
                'endpoint' => 'v0.84.0-R3',
            ]
        );
    }

    autosaveV84R3Json(
        [
            'ok' => false,
            'error' => 'Ação de autosave inválida.',
            'endpoint' => 'v0.84.0-R3',
        ],
        422
    );
} catch (Throwable $e) {
    autosaveV84R3Json(
        [
            'ok' => false,
            'error' =>
                defined('APP_DEBUG')
                && APP_DEBUG
                    ? $e->getMessage()
                    : 'Não foi possível processar o autosave.',
            'endpoint' => 'v0.84.0-R3',
        ],
        500
    );
}
