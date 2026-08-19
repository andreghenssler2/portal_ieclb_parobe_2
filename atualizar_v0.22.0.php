<?php

declare(strict_types=1);

const TARGET_VERSION = '0.22.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function seedSettings(PDO $pdo): void
{
    $defaults = [
        'backup_full_retention_count' => ['5', 'numero'],
        'backup_full_include_uploads' => ['1', 'booleano'],
        'backup_full_include_themes' => ['1', 'booleano'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave, valor, tipo) VALUES (:chave, :valor, :tipo) '
        . 'ON DUPLICATE KEY UPDATE chave = VALUES(chave)'
    );
    foreach ($defaults as $chave => [$valor, $tipo]) {
        $stmt->execute(['chave' => $chave, 'valor' => $valor, 'tipo' => $tipo]);
    }
    out('[OK] Configurações de backup completo criadas/verificadas.');
}

function ensureBackupStorage(string $root): void
{
    $dir = $root . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar storage/backups.');
    }

    $htaccess = "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    if (!is_file($dir . '/.htaccess') && file_put_contents($dir . '/.htaccess', $htaccess, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível proteger storage/backups/.htaccess.');
    }
    if (!is_file($dir . '/index.php') && file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível criar storage/backups/index.php.');
    }

    out('[OK] storage/backups verificado.');
    if (!is_writable($dir)) {
        out('[AVISO] storage/backups não está gravável pelo usuário atual do PHP.');
    }
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $safeCurrent = preg_replace('/[^0-9A-Za-z._-]+/', '-', $current) ?: 'sem-versao';
    $backup = $config . '.bak-v' . $safeCurrent . '-' . date('Ymd-His');
    if (!copy($config, $backup)) {
        throw new RuntimeException('Não foi possível criar backup de config.php.');
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
$db = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 72));

if (!is_file($config)) fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
if (!is_file($db)) fail('mod/db/Database.php não encontrado.');

foreach ([
    'app/Services/BackupService.php',
    'app/Services/FullBackupService.php',
    'admin/ferramentas/backups.php',
    'admin/ferramentas/backup-download.php',
    'admin/ferramentas/limpeza.php',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        fail('Arquivo da v0.22.0 não encontrado: ' . $file);
    }
}

require_once $config;
require_once $db;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach (['configuracoes', 'permissoes', 'perfil_permissoes'] as $table) {
        if (!tableExists($pdo, $table)) {
            throw new RuntimeException('A tabela ' . $table . ' não existe. Atualize o portal até a v0.21.0 antes desta versão.');
        }
    }

    $perm = $pdo->prepare("SELECT COUNT(*) FROM permissoes WHERE slug = 'backups.gerenciar'");
    $perm->execute();
    if ((int)$perm->fetchColumn() === 0) {
        throw new RuntimeException('A permissão backups.gerenciar não existe. Execute primeiro a atualização v0.21.0.');
    }

    seedSettings($pdo);
    ensureBackupStorage($root);

    if (class_exists('ZipArchive')) {
        out('[OK] Extensão ZipArchive disponível: backups completos habilitados.');
    } else {
        out('[AVISO] Extensão ZipArchive não encontrada. O backup do banco funcionará, mas o Backup Completo ficará desabilitado até habilitar php-zip/ZipArchive.');
    }

    updateVersion($config);
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Novo recurso: Ferramentas > Backups > Backup completo.');
    out('O config/config.php com credenciais não é incluído/restaurado automaticamente.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
