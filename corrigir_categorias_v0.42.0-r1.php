<?php

declare(strict_types=1);

const FIX_NAME = 'v0.42.0-r1';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND column_name=?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND index_name=?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function lintPhp(string $file): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command, $lines, $code);

    if ($code !== 0) {
        throw new RuntimeException(
            basename($file) . " não passou no php -l:\n" . implode(PHP_EOL, $lines)
        );
    }
}

function patchNoticia(string $path, string $backupDir): void
{
    if (!is_file($path)) {
        throw new RuntimeException('noticia.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, '// v0.42.0-r1 - categorias com fallback legado')) {
        out('[OK] noticia.php já possui fallback de categorias.');
        return;
    }

    $old = <<<'PHP'
$categoryStmt = $pdo->prepare("SELECT c.nome,c.slug FROM post_categorias pc INNER JOIN categorias c ON c.id=pc.categoria_id WHERE pc.post_id=:id ORDER BY pc.principal DESC,c.nome");
$categoryStmt->execute(['id'=>$post['id']]);
$postCategories=$categoryStmt->fetchAll();
PHP;

    $new = <<<'PHP'
// v0.42.0-r1 - categorias com fallback legado.
$postCategories = [];
try {
    $categoryStmt = $pdo->prepare(
        "SELECT c.nome,c.slug
         FROM post_categorias pc
         INNER JOIN categorias c ON c.id=pc.categoria_id
         WHERE pc.post_id=:id
         ORDER BY pc.principal DESC,c.nome"
    );
    $categoryStmt->execute(['id'=>$post['id']]);
    $postCategories = $categoryStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    // Compatibilidade com base antiga: usa posts.categoria_id.
    if (!empty($post['categoria_id'])) {
        $categoryStmt = $pdo->prepare(
            "SELECT nome,slug FROM categorias WHERE id=:id LIMIT 1"
        );
        $categoryStmt->execute(['id'=>(int)$post['categoria_id']]);
        $legacyCategory = $categoryStmt->fetch();
        if ($legacyCategory) {
            $postCategories = [$legacyCategory];
        }
    }
}
PHP;

    if (!str_contains($source, $old)) {
        throw new RuntimeException(
            'Não foi possível localizar o bloco de categorias em noticia.php.'
        );
    }

    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de backup.');
    }

    if (!copy($path, $backupDir . '/noticia.php')) {
        throw new RuntimeException('Não foi possível criar backup de noticia.php.');
    }

    $source = str_replace($old, $new, $source);

    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar noticia.php.');
    }

    lintPhp($path);
    out('[OK] noticia.php atualizado com fallback legado.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - correção ' . FIX_NAME);
out('Categorias de Notícias / post_categorias');
out(str_repeat('-', 76));

if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}
if (!is_file($dbFile)) {
    fail('mod/db/Database.php não encontrado.');
}

require_once $config;
require_once $dbFile;

$backupDir = $root . '/storage/update-backups/' . FIX_NAME . '-' . date('Ymd-His');

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    if (!tableExists($pdo, 'posts')) {
        throw new RuntimeException('Tabela posts não encontrada.');
    }
    if (!tableExists($pdo, 'categorias')) {
        throw new RuntimeException('Tabela categorias não encontrada.');
    }

    if (!columnExists($pdo, 'categorias', 'parent_id')) {
        $pdo->exec(
            'ALTER TABLE categorias
             ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
        );
        out('[OK] categorias.parent_id criado.');
    } else {
        out('[OK] categorias.parent_id já existe.');
    }

    if (!indexExists($pdo, 'categorias', 'idx_categorias_parent')) {
        $pdo->exec(
            'CREATE INDEX idx_categorias_parent
             ON categorias (parent_id)'
        );
        out('[OK] Índice idx_categorias_parent criado.');
    }

    if (!tableExists($pdo, 'post_categorias')) {
        $pdo->exec(
            "CREATE TABLE post_categorias (
                post_id INT UNSIGNED NOT NULL,
                categoria_id INT UNSIGNED NOT NULL,
                principal TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id,categoria_id),
                KEY idx_post_categorias_categoria (categoria_id),
                KEY idx_post_categorias_principal (post_id,principal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        out('[OK] Tabela post_categorias criada.');
    } else {
        out('[OK] Tabela post_categorias já existe.');
    }

    // Garante colunas caso exista uma versão incompleta da tabela.
    if (!columnExists($pdo, 'post_categorias', 'principal')) {
        $pdo->exec(
            'ALTER TABLE post_categorias
             ADD COLUMN principal TINYINT(1) NOT NULL DEFAULT 0'
        );
        out('[OK] post_categorias.principal criado.');
    }

    if (!indexExists($pdo, 'post_categorias', 'idx_post_categorias_categoria')) {
        $pdo->exec(
            'CREATE INDEX idx_post_categorias_categoria
             ON post_categorias (categoria_id)'
        );
        out('[OK] Índice de categoria criado.');
    }

    // Migra a categoria antiga de posts.categoria_id para a tabela pivô.
    if (columnExists($pdo, 'posts', 'categoria_id')) {
        $pdo->exec(
            "INSERT IGNORE INTO post_categorias
                (post_id,categoria_id,principal)
             SELECT p.id,p.categoria_id,1
             FROM posts p
             INNER JOIN categorias c ON c.id=p.categoria_id
             WHERE p.categoria_id IS NOT NULL
               AND p.categoria_id>0"
        );

        out('[OK] Categorias existentes das notícias migradas.');
    }

    patchNoticia($root . '/noticia.php', $backupDir);

    if (class_exists('CacheService')) {
        try {
            CacheService::clearGroup('page');
            CacheService::clearGroup('public');
        } catch (Throwable $ignored) {
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Correção concluída.');
    out('A versão do Portal permanece ' . (defined('APP_VERSION') ? APP_VERSION : 'atual') . '.');
    if (is_dir($backupDir)) {
        out('Backup: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
