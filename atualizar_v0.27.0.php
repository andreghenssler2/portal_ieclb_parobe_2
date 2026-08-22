<?php

declare(strict_types=1);

const TARGET_VERSION = '0.27.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}
function seedPermission(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
         VALUES ('Importar WordPress','wordpress.importar','Ferramentas','Importar conteúdos e mídias de sites WordPress pela REST API.',86)
         ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)"
    );
    $stmt->execute();
    return (int)$pdo->query("SELECT id FROM permissoes WHERE slug='wordpress.importar' LIMIT 1")->fetchColumn();
}
function grantAdministrator(PDO $pdo, int $permissionId): void
{
    $stmt = $pdo->prepare("SELECT id FROM perfis WHERE slug='administrador' LIMIT 1");
    $stmt->execute();
    $profileId = (int)$stmt->fetchColumn();
    if ($profileId > 0 && $permissionId > 0) {
        $insert = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id) VALUES (:perfil,:permissao)');
        $insert->execute(['perfil' => $profileId, 'permissao' => $permissionId]);
    }
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
function createTables(PDO $pdo): void
{
    if (!tableExists($pdo, 'wordpress_importacoes')) {
        $pdo->exec("CREATE TABLE wordpress_importacoes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NULL,
            origem_url VARCHAR(500) NOT NULL,
            origem_hash CHAR(64) NOT NULL,
            modulo VARCHAR(30) NOT NULL,
            fase VARCHAR(30) NOT NULL,
            eventos_endpoint VARCHAR(500) NULL,
            modo VARCHAR(20) NOT NULL DEFAULT 'new',
            opcoes_json TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
            pagina_atual INT UNSIGNED NOT NULL DEFAULT 1,
            total_paginas_fase INT UNSIGNED NOT NULL DEFAULT 0,
            total_itens_fase INT UNSIGNED NOT NULL DEFAULT 0,
            processados INT UNSIGNED NOT NULL DEFAULT 0,
            criados INT UNSIGNED NOT NULL DEFAULT 0,
            atualizados INT UNSIGNED NOT NULL DEFAULT 0,
            ignorados INT UNSIGNED NOT NULL DEFAULT 0,
            erros INT UNSIGNED NOT NULL DEFAULT 0,
            ultimo_erro TEXT NULL,
            iniciado_em DATETIME NULL,
            finalizado_em DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_wordpress_importacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            INDEX idx_wp_import_status (status, created_at),
            INDEX idx_wp_import_origem (origem_hash, modulo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out('[OK] Tabela wordpress_importacoes criada.');
    } else {
        out('[OK] Tabela wordpress_importacoes já existe.');
    }

    if (!tableExists($pdo, 'wordpress_import_map')) {
        $pdo->exec("CREATE TABLE wordpress_import_map (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            origem_hash CHAR(64) NOT NULL,
            origem_url VARCHAR(500) NOT NULL,
            wp_id BIGINT UNSIGNED NOT NULL,
            wp_tipo VARCHAR(100) NOT NULL,
            wp_parent_id BIGINT UNSIGNED NULL,
            wp_slug VARCHAR(255) NULL,
            wp_modified DATETIME NULL,
            source_url TEXT NULL,
            local_id BIGINT UNSIGNED NOT NULL,
            local_tipo VARCHAR(100) NOT NULL,
            local_url TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wp_import_origem_tipo_id (origem_hash, wp_tipo, wp_id),
            INDEX idx_wp_import_local (local_tipo, local_id),
            INDEX idx_wp_import_parent (origem_hash, wp_tipo, wp_parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out('[OK] Tabela wordpress_import_map criada.');
    } else {
        out('[OK] Tabela wordpress_import_map já existe.');
    }

    if (!tableExists($pdo, 'wordpress_import_logs')) {
        $pdo->exec("CREATE TABLE wordpress_import_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            importacao_id BIGINT UNSIGNED NOT NULL,
            nivel VARCHAR(20) NOT NULL,
            wp_id BIGINT UNSIGNED NULL,
            mensagem TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_wordpress_import_logs_importacao FOREIGN KEY (importacao_id) REFERENCES wordpress_importacoes(id) ON DELETE CASCADE,
            INDEX idx_wp_import_logs_job (importacao_id, id),
            INDEX idx_wp_import_logs_level (nivel, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out('[OK] Tabela wordpress_import_logs criada.');
    } else {
        out('[OK] Tabela wordpress_import_logs já existe.');
    }
}
function prepareUploadDirectory(string $root): void
{
    $dir = $root . '/public/uploads/wordpress';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar public/uploads/wordpress.');
    }
    out('[OK] Diretório de mídias do WordPress preparado.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach ([
    'app/Services/WordPressImportService.php',
    'admin/ferramentas/wordpress.php',
    'admin/ferramentas/wordpress-processar.php',
    'admin/_header.php',
    'bootstrap.php',
] as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.27.0 não encontrado: ' . $file);
}

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    foreach (['usuarios','perfis','permissoes','perfil_permissoes','configuracoes','posts','paginas','midias','categorias','tags','eventos','email_envios'] as $table) {
        if (!tableExists($pdo, $table)) {
            throw new RuntimeException('Tabela obrigatória ausente: ' . $table . '. Atualize o portal até v0.26.0 antes.');
        }
    }

    createTables($pdo);
    $permissionId = seedPermission($pdo);
    grantAdministrator($pdo, $permissionId);
    out('[OK] Permissão wordpress.importar criada/verificada.');
    prepareUploadDirectory($root);

    if (!function_exists('curl_init')) {
        out('[AVISO] A extensão cURL não está habilitada. O importador possui fallback HTTP, mas cURL é fortemente recomendado para sites grandes e mídias.');
    } else {
        out('[OK] cURL disponível para REST API e download de mídias.');
    }

    updateVersion($config);
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Abra: ' . rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/admin/ferramentas/wordpress.php');
    out('Também foram adicionados atalhos de importação nos menus de Posts, Categorias, Tags, Mídias, Páginas e Eventos.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
