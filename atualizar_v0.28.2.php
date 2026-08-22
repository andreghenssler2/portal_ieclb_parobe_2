<?php

declare(strict_types=1);

const TARGET_VERSION = '0.28.2';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

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
        $source = preg_replace('/^<\?php\s*/', "<?php\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');\n", $source, 1) ?? $source;
    }

    if (file_put_contents($config, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    }
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
    out('[OK] Backup do config.php: ' . basename($backup));
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - correção de URLs e capas da Home v' . TARGET_VERSION);
out(str_repeat('-', 76));

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach (['app/Services/HomeService.php', 'public/home-modular.php'] as $required) {
    if (!is_file($root . '/' . $required)) fail('Arquivo da v0.28.2 não encontrado: ' . $required);
}

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));
out('BASE_URL: ' . (defined('BASE_URL') ? (string)BASE_URL : 'não definida'));

try {
    // Apenas valida a conexão; a correção é aplicada no serviço de renderização
    // sem alterar posts ou mídias existentes no banco.
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='home_secoes'");
    if ((int)$stmt->fetchColumn() > 0) {
        $active = (int)$pdo->query('SELECT COUNT(*) FROM home_secoes WHERE ativo=1')->fetchColumn();
        out('[OK] Seções ativas da home: ' . $active . '.');
    }

    updateVersion($config);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
        out('[OK] OPcache reiniciado.');
    }

    out(str_repeat('-', 76));
    out('Atualização concluída com sucesso.');
    out('Faça Ctrl+F5 na página inicial para recarregar os arquivos da Home.');
    out('Links internos agora usam a URL do Portal e capas priorizam a Biblioteca de Mídias local.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
