<?php

declare(strict_types=1);

const TARGET_VERSION = '0.28.3';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
    $stmt->execute(['t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function updateDefaultVisualConfig(PDO $pdo): void
{
    if (!tableExists($pdo, 'home_secoes')) return;

    $defaults = [
        'Últimas Notícias' => ['background'=>'white','date_position'=>'after','show_date'=>false,'show_excerpt'=>false],
        'Comunidades'      => ['background'=>'soft','date_position'=>'after','show_date'=>true,'show_excerpt'=>false],
        'Paróquia'         => ['background'=>'white','date_position'=>'before','show_date'=>true,'show_excerpt'=>false],
    ];

    $select = $pdo->prepare('SELECT id, configuracao_json FROM home_secoes WHERE titulo=:titulo LIMIT 1');
    $update = $pdo->prepare('UPDATE home_secoes SET configuracao_json=:config,updated_at=NOW() WHERE id=:id');

    foreach ($defaults as $title => $visual) {
        $select->execute(['titulo' => $title]);
        $row = $select->fetch();
        if (!$row) continue;
        $config = json_decode((string)($row['configuracao_json'] ?? ''), true);
        if (!is_array($config)) $config = [];
        foreach ($visual as $key => $value) $config[$key] = $value;
        if (!array_key_exists('autoplay', $config)) $config['autoplay'] = false;
        $update->execute([
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => (int)$row['id'],
        ]);
        out('[OK] Visual padrão atualizado: ' . $title . '.');
    }
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $safe = preg_replace('/[^0-9A-Za-z._-]+/', '-', $current) ?: 'sem-versao';
    $backup = $config . '.bak-v' . $safe . '-' . date('Ymd-His');
    if (!copy($config, $backup)) throw new RuntimeException('Não foi possível criar backup do config.php.');

    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (preg_match($pattern, $source)) {
        $source = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $source, 1) ?? $source;
    } else {
        $source = preg_replace('/^<\?php\s*/', "<?php\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');\n", $source, 1) ?? $source;
    }
    if (file_put_contents($config, $source, LOCK_EX) === false) throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
    out('[OK] Backup do config.php: ' . basename($backup));
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - ajuste visual da Home v' . TARGET_VERSION);
out(str_repeat('-', 76));
if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach (['app/Services/HomeService.php','admin/aparencia/home.php','public/home-modular.php','public/css/home-modular.css','public/js/home-modular.js'] as $required) {
    if (!is_file($root . '/' . $required)) fail('Arquivo da v0.28.3 não encontrado: ' . $required);
}

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    updateDefaultVisualConfig($pdo);

    $index = $root . '/index.php';
    if (is_file($index)) {
        $source = (string)file_get_contents($index);
        if (str_contains($source, 'HOME_MODULAR_V028')) {
            out('[OK] Integração modular encontrada no index.php.');
        } else {
            out('[AVISO] Não encontrei a marca HOME_MODULAR_V028 no index.php. A home modular precisa estar integrada pela v0.28.1 ou posterior.');
        }
    }

    updateVersion($config);
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        out('[OK] OPcache reiniciado.');
    }
    out(str_repeat('-', 76));
    out('Atualização concluída.');
    out('A home modular será posicionada depois da agenda e substituirá os blocos antigos equivalentes no navegador.');
    out('Use Ctrl+F5 ao abrir a página inicial.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
