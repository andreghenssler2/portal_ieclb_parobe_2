<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

if (!Csrf::validate($_POST['_token'] ?? null)) {
    Session::flash('error', 'Token de segurança inválido.');
    header('Location: ' . url('admin/eventos/index.php'));
    exit;
}

$pdo = Database::connection();
$id = max(0, (int)($_POST['id'] ?? 0));

if ($id <= 0) {
    Session::flash('error', 'Item da agenda inválido.');
    header('Location: ' . url('admin/eventos/index.php'));
    exit;
}

$stmt =
    $pdo->prepare(
        'SELECT *
         FROM eventos
         WHERE id = :id
         LIMIT 1'
    );

$stmt->execute([
    'id' => $id,
]);

$evento = $stmt->fetch();

if (!is_array($evento)) {
    Session::flash('error', 'Item da agenda não encontrado.');
    header('Location: ' . url('admin/eventos/index.php'));
    exit;
}

try {
    $titulo =
        trim(
            (string)($evento['titulo'] ?? '')
        );

    $copyTitle =
        $titulo !== ''
            ? $titulo . ' (cópia)'
            : 'Cópia de evento';

    $slug =
        uniqueSlug(
            $pdo,
            'eventos',
            $copyTitle . '-' . date('Y-m-d-His')
        );

    $insert =
        $pdo->prepare(
            'INSERT INTO eventos
                (
                    autor_id,
                    comunidade_id,
                    categoria_evento_id,
                    tipo,
                    titulo,
                    slug,
                    resumo,
                    descricao,
                    local,
                    endereco,
                    data_inicio,
                    data_fim,
                    santa_ceia,
                    imagem_capa_id,
                    seo_titulo,
                    seo_descricao,
                    seo_noindex,
                    status
                )
             VALUES
                (
                    :autor_id,
                    :comunidade_id,
                    :categoria_evento_id,
                    :tipo,
                    :titulo,
                    :slug,
                    :resumo,
                    :descricao,
                    :local,
                    :endereco,
                    :data_inicio,
                    :data_fim,
                    :santa_ceia,
                    :imagem_capa_id,
                    :seo_titulo,
                    :seo_descricao,
                    :seo_noindex,
                    :status
                )'
        );

    $insert->execute([
        'autor_id' =>
            Auth::id(),
        'comunidade_id' =>
            $evento['comunidade_id'] ?? null,
        'categoria_evento_id' =>
            $evento['categoria_evento_id'] ?? null,
        'tipo' =>
            (string)($evento['tipo'] ?? 'atividade'),
        'titulo' =>
            $copyTitle,
        'slug' =>
            $slug,
        'resumo' =>
            $evento['resumo'] ?? null,
        'descricao' =>
            $evento['descricao'] ?? null,
        'local' =>
            $evento['local'] ?? null,
        'endereco' =>
            $evento['endereco'] ?? null,
        'data_inicio' =>
            $evento['data_inicio'] ?? date('Y-m-d H:i:s'),
        'data_fim' =>
            $evento['data_fim'] ?? null,
        'santa_ceia' =>
            (int)($evento['santa_ceia'] ?? 0),
        'imagem_capa_id' =>
            $evento['imagem_capa_id'] ?? null,
        'seo_titulo' =>
            $evento['seo_titulo'] ?? null,
        'seo_descricao' =>
            $evento['seo_descricao'] ?? null,
        'seo_noindex' =>
            (int)($evento['seo_noindex'] ?? 0),
        'status' =>
            'rascunho',
    ]);

    $newId =
        (int)$pdo->lastInsertId();

    logAction(
        $pdo,
        'agenda.duplicar',
        'eventos',
        $newId,
        'Cópia do evento #' . $id . ': ' . $copyTitle
    );

    Session::flash(
        'success',
        'Item duplicado como rascunho. Revise a data e publique quando estiver pronto.'
    );

    header(
        'Location: '
        . url(
            'admin/eventos/form.php?id='
            . $newId
        )
    );
    exit;
} catch (Throwable $e) {
    Session::flash(
        'error',
        'Não foi possível duplicar o item: '
        . $e->getMessage()
    );

    header(
        'Location: '
        . url('admin/eventos/index.php')
    );
    exit;
}
