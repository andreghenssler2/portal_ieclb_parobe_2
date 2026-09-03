<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    header(
        'Allow: POST'
    );

    http_response_code(405);
    exit;
}

$pdo =
    Database::connection();

$formId =
    max(
        0,
        (int)(
            $_POST['formulario_id']
            ?? 0
        )
    );

$instanceKey =
    EmbeddedFormService::normalizeInstanceKey(
        (string)(
            $_POST['instance_key']
            ?? ''
        )
    );

$returnTo =
    EmbeddedFormService::safeReturnTo(
        (string)(
            $_POST['return_to']
            ?? ''
        )
    );

if ($returnTo === '') {
    $returnTo =
        EmbeddedFormService::currentReturnTo();
}

if ($instanceKey === '') {
    $instanceKey =
        'form-'
        . $formId;
}

$anchor =
    'portal-form-'
    . substr(
        hash(
            'sha256',
            $instanceKey
            . ':'
            . $formId
        ),
        0,
        12
    );

$result =
    EmbeddedFormService::submit(
        $pdo,
        $formId,
        $_POST
    );

EmbeddedFormService::rememberState(
    $formId,
    $instanceKey,
    [
        'success' =>
            !empty(
                $result['success']
            )
                ? (string)(
                    $result['message']
                    ?? ''
                )
                : '',
        'errors' =>
            !empty(
                $result['success']
            )
                ? []
                : (
                    is_array(
                        $result['errors']
                        ?? null
                    )
                        ? $result['errors']
                        : [
                            'Não foi possível enviar o formulário.',
                        ]
                ),
        'old' =>
            !empty(
                $result['success']
            )
                ? []
                : (
                    is_array(
                        $result['old']
                        ?? null
                    )
                        ? $result['old']
                        : []
                ),
    ]
);

header(
    'Location: '
    . $returnTo
    . '#'
    . rawurlencode(
        $anchor
    )
);

exit;
