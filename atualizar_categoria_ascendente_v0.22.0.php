<?php

declare(strict_types=1);

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
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index'
    );
    $stmt->execute(['table' => $table, 'index' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function foreignKeyExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint'
    );
    $stmt->execute(['table' => $table, 'constraint' => $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

$root = __DIR__;
$config = $root . '/config/config.php';
$db = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - Categoria ascendente para Posts (v0.22.0)');
out(str_repeat('-', 72));

if (!is_file($config)) {
    fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
}
if (!is_file($db)) {
    fail('mod/db/Database.php não encontrado.');
}

foreach ([
    'app/Services/CategoryService.php',
    'admin/categorias/index.php',
    'admin/noticias/form.php',
    'admin/configuracoes/escrita.php',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        fail('Arquivo da correção não encontrado: ' . $file);
    }
}

require_once $config;
require_once $db;

out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    if (!tableExists($pdo, 'categorias')) {
        throw new RuntimeException('A tabela categorias não existe.');
    }

    if (!columnExists($pdo, 'categorias', 'parent_id')) {
        $pdo->exec(
            'ALTER TABLE categorias
             ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
        );
        out('[OK] Coluna categorias.parent_id criada.');
    } else {
        out('[OK] Coluna categorias.parent_id já existe.');
    }

    if (!indexExists($pdo, 'categorias', 'idx_categorias_parent_id')) {
        $pdo->exec('CREATE INDEX idx_categorias_parent_id ON categorias (parent_id)');
        out('[OK] Índice idx_categorias_parent_id criado.');
    } else {
        out('[OK] Índice idx_categorias_parent_id já existe.');
    }

    if (!foreignKeyExists($pdo, 'categorias', 'fk_categorias_parent')) {
        $pdo->exec(
            'ALTER TABLE categorias
             ADD CONSTRAINT fk_categorias_parent
             FOREIGN KEY (parent_id) REFERENCES categorias(id)
             ON DELETE SET NULL
             ON UPDATE CASCADE'
        );
        out('[OK] Relacionamento de Categoria ascendente criado.');
    } else {
        out('[OK] Relacionamento fk_categorias_parent já existe.');
    }

    // Garante que uma eventual base parcialmente alterada não contenha auto-relacionamento.
    $fixed = $pdo->exec('UPDATE categorias SET parent_id = NULL WHERE parent_id = id');
    if ($fixed > 0) {
        out('[OK] ' . $fixed . ' auto-relacionamento(s) inválido(s) removido(s).');
    }

    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Posts > Categorias agora possui a opção "Categoria ascendente".');
    out('Exemplo: Eventos > Eventos 1 > Eventos 1.1');
    out('APP_VERSION permanece inalterado, pois esta é uma correção da v0.22.0.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
