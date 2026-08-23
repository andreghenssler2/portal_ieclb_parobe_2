<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';

Auth::requireLogin();
Auth::requirePermission('noticias.gerenciar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validate($_POST['_token'] ?? null)) {
    Session::flash('error', 'Solicitação inválida.');
    header('Location: ' . url('admin/noticias/index.php'));
    exit;
}

$pdo = Database::connection();
CategoryService::ensureSchema($pdo);
$id = (int)($_POST['id'] ?? 0);

try {
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id=:id AND status <> \'lixeira\' LIMIT 1');
    $stmt->execute(['id' => $id]);
    $post = $stmt->fetch();
    if (!$post) {
        throw new RuntimeException('Post não encontrado.');
    }

    $novoTitulo = 'Cópia de ' . (string)$post['titulo'];
    $novoSlug = uniqueSlug($pdo, 'posts', $novoTitulo);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO posts
             (autor_id,comunidade_id,categoria_id,titulo,slug,resumo,conteudo,imagem_capa_id,seo_titulo,seo_descricao,seo_noindex,status,destaque,comentarios_ativos,publicado_em)
             VALUES
             (:autor_id,:comunidade_id,:categoria_id,:titulo,:slug,:resumo,:conteudo,:imagem_capa_id,:seo_titulo,:seo_descricao,:seo_noindex,\'rascunho\',0,:comentarios_ativos,NULL)'
        );
        $insert->execute([
            'autor_id' => Auth::id(),
            'comunidade_id' => $post['comunidade_id'] ?: null,
            'categoria_id' => $post['categoria_id'] ?: null,
            'titulo' => $novoTitulo,
            'slug' => $novoSlug,
            'resumo' => $post['resumo'] ?: null,
            'conteudo' => (string)$post['conteudo'],
            'imagem_capa_id' => $post['imagem_capa_id'] ?: null,
            'seo_titulo' => null,
            'seo_descricao' => $post['seo_descricao'] ?: null,
            'seo_noindex' => 1,
            'comentarios_ativos' => (int)($post['comentarios_ativos'] ?? 1),
        ]);
        $novoId = (int)$pdo->lastInsertId();

        $categoryIds = CategoryService::postCategoryIds($pdo, $id);
        CategoryService::syncPostCategories($pdo, $novoId, $categoryIds, $post['categoria_id'] ? (int)$post['categoria_id'] : null);

        $tagStmt = $pdo->prepare('SELECT tag_id FROM post_tags WHERE post_id=:post_id');
        $tagStmt->execute(['post_id' => $id]);
        $tagIds = array_map('intval', $tagStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($tagIds) {
            $link = $pdo->prepare('INSERT INTO post_tags (post_id,tag_id) VALUES (:post_id,:tag_id)');
            foreach ($tagIds as $tagId) {
                $link->execute(['post_id' => $novoId, 'tag_id' => $tagId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    logAction($pdo, 'noticia.duplicar', 'posts', $novoId, $novoTitulo);
    Session::flash('success', 'Novo rascunho criado a partir da notícia original.');
    header('Location: ' . url('admin/noticias/form.php?id=' . $novoId));
    exit;
} catch (Throwable $e) {
    Session::flash('error', 'Não foi possível copiar o post: ' . $e->getMessage());
    header('Location: ' . url('admin/noticias/index.php'));
    exit;
}
