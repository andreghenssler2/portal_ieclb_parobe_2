<?php

declare(strict_types=1);

const TARGET_VERSION = '0.19.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function seedPermission(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) '
        . 'VALUES (:nome, :slug, :grupo, :descricao, :ordem) '
        . 'ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ordem = VALUES(ordem)'
    );
    $stmt->execute([
        'nome' => 'Editor de Temas',
        'slug' => 'tema_editor.gerenciar',
        'grupo' => 'Aparência',
        'descricao' => 'Editar arquivos permitidos dos temas e restaurar backups.',
        'ordem' => 86,
    ]);
    out('[OK] Permissão tema_editor.gerenciar criada/verificada.');
}

function prepareBackupDirectory(string $root): void
{
    $dir = $root . '/storage/theme-backups';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar storage/theme-backups.');
    }

    $htSource = $root . '/storage/theme-backups/.htaccess';
    if (!is_file($htSource)) {
        $content = "# Portal IECLB Parobé - backups internos dos temas\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        if (file_put_contents($htSource, $content, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível proteger a pasta de backups.');
        }
    }
    $index = $dir . '/index.php';
    if (!is_file($index)) {
        file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
    }
    out('[OK] Pasta storage/theme-backups preparada e protegida.');
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $backup = $config . '.bak-v' . preg_replace('/[^0-9A-Za-z._-]+/', '-', $current) . '-' . date('Ymd-His');
    if (!copy($config, $backup)) {
        throw new RuntimeException('Não foi possível criar backup de config.php.');
    }

    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (preg_match($pattern, $source)) {
        $source = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $source, 1) ?? $source;
    } else {
        $line = "define('APP_VERSION', '" . TARGET_VERSION . "');\n";
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source, $match, PREG_OFFSET_CAPTURE)) {
            $pos = $match[0][1] + strlen($match[0][0]);
            $source = substr($source, 0, $pos) . "\n\n" . $line . substr($source, $pos);
        } else {
            $source = preg_replace('/^<\?php\s*/', "<?php\n\n" . $line, $source, 1) ?? ($line . $source);
        }
    }

    if (file_put_contents($config, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    }
    out('[OK] Backup do config: ' . basename($backup));
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$db = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
if (!is_file($db)) fail('mod/db/Database.php não encontrado.');
foreach (['admin/aparencia/editor-temas.php', 'app/Services/ThemeEditorService.php', 'public/js/theme-editor.js'] as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.19.0 não encontrado: ' . $file);
}

require_once $config;
require_once $db;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    foreach (['permissoes', 'perfil_permissoes', 'perfis', 'logs'] as $table) {
        if (!tableExists($pdo, $table)) {
            throw new RuntimeException('A tabela ' . $table . ' não existe. Atualize o portal até a v0.18.0 antes desta versão.');
        }
    }

    seedPermission($pdo);
    prepareBackupDirectory($root);
    updateVersion($config);

    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Acesse: Aparência > Editor de Temas');
    out('Permissão: tema_editor.gerenciar (Administrador possui acesso total por padrão).');
} catch (Throwable $e) {
    fail($e->getMessage());
}
