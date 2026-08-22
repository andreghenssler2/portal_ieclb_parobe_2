<?php

declare(strict_types=1);

const TARGET_VERSION = '0.27.2';

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

function createMediaAliasTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'wordpress_import_media_urls')) {
        $pdo->exec("CREATE TABLE wordpress_import_media_urls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            origem_hash CHAR(64) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            source_url TEXT NOT NULL,
            wp_media_id BIGINT UNSIGNED NULL,
            local_id BIGINT UNSIGNED NULL,
            local_url TEXT NULL,
            local_path TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wp_media_url_origem_source (origem_hash, source_hash),
            KEY idx_wp_media_url_wp (origem_hash, wp_media_id),
            KEY idx_wp_media_url_local (local_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out('[OK] Tabela wordpress_import_media_urls criada.');
    } else {
        out('[OK] Tabela wordpress_import_media_urls já existe.');
    }

    // Traz para a nova tabela os source_url já conhecidos pelas versões
    // anteriores. Os aliases de tamanhos serão completados ao reprocessar Mídias.
    $pdo->exec("INSERT INTO wordpress_import_media_urls
        (origem_hash,source_hash,source_url,wp_media_id,local_id,local_url,local_path,created_at,updated_at)
        SELECT origem_hash,SHA2(TRIM(source_url),256),TRIM(source_url),wp_id,local_id,local_url,NULL,NOW(),NOW()
        FROM wordpress_import_map
        WHERE wp_tipo='media' AND source_url IS NOT NULL AND TRIM(source_url)<>''
        ON DUPLICATE KEY UPDATE
            wp_media_id=VALUES(wp_media_id),
            local_id=VALUES(local_id),
            local_url=VALUES(local_url),
            updated_at=NOW()");
    out('[OK] Mapeamentos de mídia existentes preparados para reparo.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
out('Portal IECLB Parobé - correção de mídias WordPress v' . TARGET_VERSION);
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach ([
    'app/Services/WordPressImportService.php',
    'admin/ferramentas/wordpress.php',
] as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.27.2 não encontrado: ' . $file);
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

    createMediaAliasTable($pdo);
    $uploadDir = $root . '/public/uploads/wordpress';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível preparar public/uploads/wordpress.');
    }
    out('[OK] Diretório local de mídias verificado.');

    updateVersion($config);
    out('[OK] Reparo automático de mídias remotas habilitado.');
    out('[OK] URLs src/srcset e tamanhos gerados pelo WordPress passam a ser normalizados.');
    out('[OK] Imagens embutidas sem attachment REST também podem ser copiadas para a Biblioteca de Mídias.');
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Para corrigir o conteúdo já importado:');
    out('1) execute Mídias com "Baixar mídias" e "Reparar mídias" marcados;');
    out('2) execute Posts / Notícias novamente no modo "Importar apenas novos".');
} catch (Throwable $e) {
    fail($e->getMessage());
}
