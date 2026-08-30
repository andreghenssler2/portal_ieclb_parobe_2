<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/AdminGlobalSearchService.php';

Auth::requireLogin();

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

$query =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

if (
    $query === ''
    || mb_strlen($query) < 2
) {
    echo json_encode(
        [
            'ok' => true,
            'query' => $query,
            'total' => 0,
            'results' => [],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {
    $service =
        new AdminGlobalSearchService(
            Database::connection()
        );

    $data =
        $service->search(
            $query,
            6
        );

    echo json_encode(
        [
            'ok' => true,
            'query' => $data['query'],
            'total' => $data['total'],
            'results' => $data['results'],
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
                'Não foi possível realizar a busca agora.',
            'results' => [],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}
