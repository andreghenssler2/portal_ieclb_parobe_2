<?php

declare(strict_types=1);

const TARGET_VERSION = '0.9.0';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message, int $code = 1): never
{
    out('[ERRO] ' . $message);
    exit($code);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function updateConfigVersion(string $path, string $version): void
{
    $content = file_get_contents($path);
    if ($content === false) {
        fail('Não foi possível ler config/config.php.');
    }

    $pattern = "/define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern, $content, $match) && ($match[1] ?? '') === $version) {
        out('[OK] APP_VERSION já está em ' . $version . '.');
        return;
    }

    $backup = $path . '.bak-' . date('Ymd-His');
    if (!copy($path, $backup)) {
        fail('Não foi possível criar backup do config/config.php.');
    }

    if (preg_match($pattern, $content)) {
        $newContent = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . $version . "');",
            $content,
            1
        );
        $message = '[OK] APP_VERSION alterado para ' . $version . '.';
    } else {
        $definition = "define('APP_VERSION', '" . $version . "');";

        if (preg_match('/declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/', $content, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $newContent = substr($content, 0, $offset)
                . PHP_EOL . PHP_EOL . $definition
                . substr($content, $offset);
        } elseif (preg_match('/<\\?php\\s*/', $content, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $newContent = substr($content, 0, $offset)
                . PHP_EOL . PHP_EOL . $definition
                . substr($content, $offset);
        } else {
            $newContent = '<?php' . PHP_EOL . $definition . PHP_EOL . $content;
        }

        $message = '[OK] APP_VERSION não existia e foi adicionada como ' . $version . '.';
    }

    if ($newContent === null || file_put_contents($path, $newContent, LOCK_EX) === false) {
        fail('Não foi possível atualizar config/config.php. Backup: ' . basename($backup));
    }

    out($message);
    out('[OK] Backup do config: ' . basename($backup));
}

$root = __DIR__;
$config = $root . '/config/config.php';
$databaseClass = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 64));

if (!is_file($config)) {
    fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
}
if (!is_file($databaseClass)) {
    fail('mod/db/Database.php não encontrado.');
}

$requiredFiles = [
    'admin/_header.php',
    'admin/_footer.php',
    'admin/categorias/index.php',
    'admin/configuracoes/index.php',
    'admin/formularios/respostas.php',
    'admin/midias/index.php',
    'admin/perfis/index.php',
    'public/css/admin.css',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        fail('Arquivo da v0.9.0 não encontrado: ' . $file);
    }
}

require_once $config;
require_once $databaseClass;

out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach (['usuarios', 'perfis', 'permissoes', 'categorias', 'posts'] as $table) {
        if (!tableExists($pdo, $table)) {
            fail('A tabela ' . $table . ' não existe. Atualize primeiro as versões anteriores do Portal.');
        }
    }

    out('[OK] Estrutura necessária do banco verificada.');
    out('[OK] A v0.9.0 não exige alteração de tabelas ou perda de dados.');
} catch (Throwable $e) {
    fail('Falha ao verificar o banco: ' . $e->getMessage());
}

updateConfigVersion($config, TARGET_VERSION);

out(str_repeat('-', 64));
out('Atualização concluída com sucesso.');
out('Novo painel administrativo instalado.');
out('Categorias de Posts disponíveis em: admin/categorias/');
out('Nenhuma migração SQL é necessária nesta versão.');
