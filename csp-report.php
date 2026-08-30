<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header(
        'Allow: POST'
    );

    http_response_code(405);
    exit;
}

header(
    'Cache-Control: no-store, max-age=0'
);

header(
    'Content-Type: text/plain; charset=utf-8'
);

try {
    $pdo =
        Database::connection();

    if (
        !class_exists(
            'CspReportService'
        )
        || !CspReportService::enabled(
            $pdo
        )
    ) {
        http_response_code(204);
        exit;
    }

    $length =
        (int)(
            $_SERVER['CONTENT_LENGTH']
            ?? 0
        );

    if (
        $length > 131072
    ) {
        http_response_code(413);
        exit;
    }

    $raw =
        file_get_contents(
            'php://input'
        );

    if (!is_string($raw)) {
        $raw = '';
    }

    CspReportService::collectRaw(
        $pdo,
        $raw,
        (string)(
            $_SERVER['CONTENT_TYPE']
            ?? ''
        )
    );

    http_response_code(204);
} catch (Throwable $e) {
    /*
     * O coletor nunca deve quebrar a navegação do visitante.
     */
    http_response_code(204);
}
