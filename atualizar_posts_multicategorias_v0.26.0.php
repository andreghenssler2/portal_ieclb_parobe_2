<?php

declare(strict_types=1);

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}
function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:idx');
    $stmt->execute(['table' => $table, 'idx' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - melhoria de Posts: múltiplas categorias');
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach (['posts', 'categorias'] as $required) {
        if (!tableExists($pdo, $required)) {
            throw new RuntimeException('Tabela obrigatória ausente: ' . $required . '.');
        }
    }

    if (!tableExists($pdo, 'post_categorias')) {
        $pdo->exec(
            "CREATE TABLE post_categorias (
                post_id INT UNSIGNED NOT NULL,
                categoria_id INT UNSIGNED NOT NULL,
                principal TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id, categoria_id),
                INDEX idx_post_categorias_categoria (categoria_id, post_id),
                INDEX idx_post_categorias_principal (post_id, principal),
                CONSTRAINT fk_post_categorias_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_post_categorias_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        out('[OK] Tabela post_categorias criada.');
    } else {
        out('[OK] Tabela post_categorias já existe.');
        if (!indexExists($pdo, 'post_categorias', 'idx_post_categorias_categoria')) {
            $pdo->exec('ALTER TABLE post_categorias ADD INDEX idx_post_categorias_categoria (categoria_id, post_id)');
        }
        if (!indexExists($pdo, 'post_categorias', 'idx_post_categorias_principal')) {
            $pdo->exec('ALTER TABLE post_categorias ADD INDEX idx_post_categorias_principal (post_id, principal)');
        }
    }

    $insert = $pdo->exec(
        'INSERT IGNORE INTO post_categorias (post_id, categoria_id, principal)
         SELECT id, categoria_id, 1 FROM posts WHERE categoria_id IS NOT NULL'
    );
    out('[OK] Categorias antigas migradas/verificadas (' . (int)$insert . ' vínculo(s) novo(s)).');

    // Garante no máximo uma categoria principal por post e mantém a coluna legada sincronizada.
    $postIds = $pdo->query('SELECT DISTINCT post_id FROM post_categorias ORDER BY post_id')->fetchAll(PDO::FETCH_COLUMN);
    $choose = $pdo->prepare('SELECT categoria_id, principal FROM post_categorias WHERE post_id=:post_id ORDER BY principal DESC, categoria_id ASC');
    $clear = $pdo->prepare('UPDATE post_categorias SET principal=0 WHERE post_id=:post_id');
    $set = $pdo->prepare('UPDATE post_categorias SET principal=1 WHERE post_id=:post_id AND categoria_id=:categoria_id');
    $sync = $pdo->prepare('UPDATE posts SET categoria_id=:categoria_id WHERE id=:post_id');
    foreach ($postIds as $postIdRaw) {
        $postId = (int)$postIdRaw;
        $choose->execute(['post_id' => $postId]);
        $rows = $choose->fetchAll();
        if (!$rows) continue;
        $primaryId = (int)$rows[0]['categoria_id'];
        $clear->execute(['post_id' => $postId]);
        $set->execute(['post_id' => $postId, 'categoria_id' => $primaryId]);
        $sync->execute(['post_id' => $postId, 'categoria_id' => $primaryId]);
    }

    out('[OK] Relações de categorias sincronizadas.');
    out('[OK] Nenhuma versão do Portal foi alterada; esta é uma melhoria compatível com v0.26.0.');
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Agora uma Notícia pode ser vinculada a várias categorias.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
