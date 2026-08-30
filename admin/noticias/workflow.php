<?php

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();

if (
    !Auth::can('noticias.gerenciar')
    && !Auth::can('noticias.revisar')
    && !Auth::can('noticias.publicar')
    && !Auth::isAdmin()
) {
    Session::flash(
        'error',
        'Você não possui permissão para acessar o fluxo editorial.'
    );

    header('Location: ' . url('admin/index.php'));
    exit;
}

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('admin/noticias/revisao.php'));
    exit;
}

if (!Csrf::validate($_POST['_token'] ?? null)) {
    Session::flash(
        'error',
        'Token de segurança inválido.'
    );

    header('Location: ' . url('admin/noticias/revisao.php'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$note = trim((string)($_POST['observacao'] ?? ''));

$return = trim((string)($_POST['return'] ?? 'queue'));

try {
    if (
        $action === 'submit'
        && !Auth::can('noticias.gerenciar')
        && !Auth::isAdmin()
    ) {
        throw new RuntimeException(
            'Você não possui permissão para enviar notícias para revisão.'
        );
    }

    match ($action) {
        'submit' =>
            EditorialWorkflowService::submit(
                $pdo,
                $id,
                (int)Auth::id()
            ),

        'approve' =>
            EditorialWorkflowService::approve(
                $pdo,
                $id,
                (int)Auth::id(),
                $note
            ),

        'changes' =>
            EditorialWorkflowService::requestChanges(
                $pdo,
                $id,
                (int)Auth::id(),
                $note
            ),

        'publish' =>
            EditorialWorkflowService::publish(
                $pdo,
                $id,
                (int)Auth::id()
            ),

        default =>
            throw new RuntimeException(
                'Ação editorial inválida.'
            ),
    };

    $message = match ($action) {
        'submit' => 'Notícia enviada para revisão.',
        'approve' => 'Notícia aprovada.',
        'changes' => 'Ajustes solicitados ao autor.',
        'publish' => 'Notícia publicada.',
        default => 'Fluxo editorial atualizado.',
    };

    Session::flash(
        'success',
        $message
    );
} catch (Throwable $e) {
    Session::flash(
        'error',
        $e->getMessage()
    );
}

if ($return === 'editor' && $id > 0) {
    header(
        'Location: '
        . url('admin/noticias/form.php?id=' . $id)
    );
    exit;
}

header(
    'Location: '
    . url('admin/noticias/revisao.php')
);
