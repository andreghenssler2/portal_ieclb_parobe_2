<?php

declare(strict_types=1);

const TARGET_VERSION = '0.27.1';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}
function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $safe = preg_replace('/[^0-9A-Za-z._-]+/', '-', $current) ?: 'sem-versao';
    $backup = $config . '.bak-v' . $safe . '-' . date('Ymd-His');
    if (!copy($config, $backup)) {
        throw new RuntimeException('Não foi possível criar backup do config.php.');
    }

    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (preg_match($pattern, $source)) {
        $source = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $source, 1) ?? $source;
    } else {
        $line = "define('APP_VERSION', '" . TARGET_VERSION . "');\n";
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
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
$dbFile = $root . '/mod/db/Database.php';
out('Portal IECLB Parobé - correção de capas WordPress v' . TARGET_VERSION);
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach ([
    'app/Services/WordPressImportService.php',
    'admin/ferramentas/wordpress.php',
] as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.27.1 não encontrado: ' . $file);
}

require_once $config;
require_once $dbFile;
$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);
if (version_compare($current, '0.27.0', '<')) {
    fail('Instale a v0.27.0 antes desta correção.');
}

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    foreach (['posts', 'paginas', 'midias', 'eventos', 'wordpress_importacoes', 'wordpress_import_map'] as $table) {
        if (!tableExists($pdo, $table)) {
            throw new RuntimeException('Tabela obrigatória ausente: ' . $table . '.');
        }
    }

    updateVersion($config);
    out('[OK] Importador WordPress corrigido para buscar featured_media sob demanda.');
    out('[OK] Compatibilidade ampliada para campos de capa por ID, caminho e URL.');
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Para corrigir posts já importados, execute novamente Posts / Notícias.');
    out('O modo "Importar apenas novos" já recupera as capas ausentes da v0.27.0.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
