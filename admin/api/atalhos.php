<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/AdminShortcutService.php';

Auth::requireLogin();

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

$userId =
    (int)Auth::id();

$service =
    new AdminShortcutService(
        Database::connection()
    );

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentRoute =
        (string)(
            $_GET['current']
            ?? ''
        );

    try {
        $lists =
            $service->lists(
                $userId,
                $currentRoute
            );

        echo json_encode(
            [
                'ok' => true,
                'favorites' => $lists['favorites'],
                'recent' => $lists['recent'],
                'current_favorite' =>
                    $lists['current_favorite'],
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        http_response_code(500);

        echo json_encode(
            [
                'ok' => false,
                'message' =>
                    'Não foi possível carregar seus atalhos.',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode(
        [
            'ok' => false,
            'message' => 'Método não permitido.',
        ]
    );

    exit;
}

if (
    !Csrf::validate(
        $_POST['_token']
        ?? null
    )
) {
    http_response_code(419);

    echo json_encode(
        [
            'ok' => false,
            'message' =>
                'Token de segurança inválido.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$action =
    trim(
        (string)(
            $_POST['action']
            ?? ''
        )
    );

$route =
    (string)(
        $_POST['route']
        ?? ''
    );

$title =
    (string)(
        $_POST['title']
        ?? 'Painel'
    );

try {
    if ($action === 'visit') {
        $service->recordVisit(
            $userId,
            $route,
            $title
        );

        echo json_encode([
            'ok' => true,
        ]);

        exit;
    }

    if ($action === 'toggle_favorite') {
        $favorite =
            $service->toggleFavorite(
                $userId,
                $route,
                $title
            );

        logAction(
            Database::connection(),
            $favorite
                ? 'admin_atalho.favoritar'
                : 'admin_atalho.desfavoritar',
            'usuario_admin_atalhos',
            null,
            $title
        );

        echo json_encode(
            [
                'ok' => true,
                'favorite' => $favorite,
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if ($action === 'remove') {
        $service->remove(
            $userId,
            $route
        );

        echo json_encode([
            'ok' => true,
        ]);

        exit;
    }

    throw new InvalidArgumentException(
        'Ação inválida.'
    );
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode(
        [
            'ok' => false,
            'message' => $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}
