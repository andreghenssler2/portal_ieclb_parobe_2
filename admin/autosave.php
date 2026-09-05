<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, max-age=0'
);

function autosaveJson(
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
    autosaveJson(
        [
            'ok' => false,
            'error' => 'Sua sessão expirou.',
            'login_required' => true,
        ],
        401
    );
}

if (
    !class_exists(
        'ContentAutosaveService'
    )
) {
    autosaveJson(
        [
            'ok' => false,
            'error' => 'Serviço de autosave indisponível.',
        ],
        503
    );
}

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    autosaveJson(
        [
            'ok' => false,
            'error' => 'Método não permitido.',
        ],
        405
    );
}

if (
    !Csrf::validate(
        $_POST['_token']
        ?? null
    )
) {
    autosaveJson(
        [
            'ok' => false,
            'error' => 'Token de segurança inválido.',
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
    || !Auth::can(
        $permission
    )
) {
    autosaveJson(
        [
            'ok' => false,
            'error' => 'Você não possui permissão para este autosave.',
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
    ContentAutosaveService::ensureSchema(
        $pdo
    );

    if ($action === 'load') {
        $row =
            ContentAutosaveService::find(
                $pdo,
                $userId,
                $type,
                $contentId
            );

        autosaveJson(
            [
                'ok' => true,
                'draft' => $row,
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
            autosaveJson(
                [
                    'ok' => false,
                    'error' => 'Rascunho vazio.',
                ],
                422
            );
        }

        if (
            strlen($raw)
            > 4 * 1024 * 1024
        ) {
            autosaveJson(
                [
                    'ok' => false,
                    'error' => 'O rascunho automático ultrapassou 4 MB.',
                ],
                413
            );
        }

        $payload =
            json_decode(
                $raw,
                true
            );

        if (!is_array($payload)) {
            autosaveJson(
                [
                    'ok' => false,
                    'error' => 'Formato de rascunho inválido.',
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
                $payload
            );

        /*
         * Limpeza probabilística para não executar DELETE em toda digitação.
         */
        try {
            if (
                random_int(
                    1,
                    50
                ) === 1
            ) {
                ContentAutosaveService::cleanup(
                    $pdo,
                    30
                );
            }
        } catch (Throwable $ignored) {
        }

        autosaveJson(
            [
                'ok' => true,
                'updated_at' =>
                    $row['updated_at']
                    ?? null,
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

        autosaveJson(
            [
                'ok' => true,
                'deleted' => $deleted,
            ]
        );
    }

    autosaveJson(
        [
            'ok' => false,
            'error' => 'Ação de autosave inválida.',
        ],
        422
    );
} catch (Throwable $e) {
    autosaveJson(
        [
            'ok' => false,
            'error' =>
                defined('APP_DEBUG')
                && APP_DEBUG
                    ? $e->getMessage()
                    : 'Não foi possível processar o autosave.',
        ],
        500
    );
}
