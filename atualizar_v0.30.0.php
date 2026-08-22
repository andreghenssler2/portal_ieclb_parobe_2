<?php

declare(strict_types=1);

const TARGET_VERSION = '0.30.0';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Não foi possível criar a pasta: ' . $path);
    }
}

function protectDirectory(string $path): void
{
    ensureDirectory($path);
    $htaccess = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        if (file_put_contents($htaccess, $content, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível proteger a pasta: ' . $path);
        }
    }
}

function backupFile(string $source, string $backupDir, string $label): ?string
{
    if (!is_file($source)) {
        return null;
    }
    protectDirectory($backupDir);
    $target = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $label . '-' . date('Ymd-His') . '.bak';
    if (!copy($source, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . basename($source) . '.');
    }
    return $target;
}

function ensurePermission(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES " .
        "('Visualizar Saúde do Portal','saude.visualizar','Ferramentas','Executar diagnósticos de servidor, banco, arquivos, segurança, URLs e e-mail.',87) " .
        "ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)"
    );

    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id) " .
        "SELECT p.id, pe.id FROM perfis p JOIN permissoes pe ON pe.slug='saude.visualizar' WHERE p.slug='administrador'"
    );

    out('[OK] Permissão saude.visualizar criada/verificada para Administrador.');
}

function patchAdminHeader(string $header, string $backupDir): void
{
    if (!is_file($header)) {
        throw new RuntimeException('admin/_header.php não encontrado.');
    }

    $source = (string)file_get_contents($header);
    if (str_contains($source, "admin/ferramentas/saude.php")) {
        out('[OK] Menu Saúde do Portal já está instalado.');
        return;
    }

    $backup = backupFile($header, $backupDir, 'admin-header');
    if ($backup) {
        out('[OK] Backup do menu administrativo: ' . $backup);
    }

    $oldCondition = "Auth::can('backups.gerenciar') || Auth::can('manutencao.gerenciar') || Auth::can('wordpress.importar')";
    $newCondition = $oldCondition . " || Auth::can('saude.visualizar')";
    if (str_contains($source, $oldCondition)) {
        $source = str_replace($oldCondition, $newCondition, $source);
    } else {
        out('[AVISO] Condição do menu Ferramentas está diferente do esperado; o link será inserido mesmo assim.');
    }

    $toolsPos = strpos($source, 'id="menuFerramentas"');
    if ($toolsPos === false) {
        throw new RuntimeException('Não foi possível localizar o submenu Ferramentas em admin/_header.php.');
    }

    $importNeedle = "href=\"<?= e(url('admin/ferramentas/wordpress.php')) ?>\">Importar WordPress</a>";
    $importPos = strpos($source, $importNeedle, $toolsPos);
    if ($importPos !== false) {
        $endifPos = strpos($source, '<?php endif; ?>', $importPos);
        if ($endifPos === false) {
            throw new RuntimeException('Não foi possível localizar o fechamento do item Importar WordPress.');
        }
        $insertPos = $endifPos + strlen('<?php endif; ?>');
        $block = "\n                        <?php if (Auth::can('saude.visualizar')): ?>\n" .
            "                            <a class=\"<?= \$isPath('ferramentas/saude.php') ? 'active' : '' ?>\" href=\"<?= e(url('admin/ferramentas/saude.php')) ?>\">Saúde do Portal</a>\n" .
            "                        <?php endif; ?>";
        $source = substr($source, 0, $insertPos) . $block . substr($source, $insertPos);
    } else {
        $closePos = strpos($source, '</div>', $toolsPos);
        if ($closePos === false) {
            throw new RuntimeException('Não foi possível localizar o final do submenu Ferramentas.');
        }
        $block = "                        <?php if (Auth::can('saude.visualizar')): ?>\n" .
            "                            <a class=\"<?= \$isPath('ferramentas/saude.php') ? 'active' : '' ?>\" href=\"<?= e(url('admin/ferramentas/saude.php')) ?>\">Saúde do Portal</a>\n" .
            "                        <?php endif; ?>\n                    ";
        $source = substr($source, 0, $closePos) . $block . substr($source, $closePos);
    }

    if (file_put_contents($header, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar admin/_header.php.');
    }
    out('[OK] Ferramentas > Saúde do Portal adicionado ao menu.');
}

function patchRootHtaccess(string $file, string $backupDir): void
{
    $source = is_file($file) ? (string)file_get_contents($file) : '';
    if (str_contains($source, 'BEGIN IECLB V0.30 SENSITIVE FILES')) {
        out('[OK] Proteção de arquivos sensíveis já está instalada no .htaccess.');
        return;
    }

    if (is_file($file)) {
        $backup = backupFile($file, $backupDir, 'root-htaccess');
        if ($backup) {
            out('[OK] Backup do .htaccess: ' . $backup);
        }
    }

    $block = <<<'HTACCESS'

# BEGIN IECLB V0.30 SENSITIVE FILES
# Atualizadores devem ser executados somente pelo terminal: php atualizar_vX.Y.Z.php
<IfModule mod_authz_core.c>
    <FilesMatch "^(atualizar.*\.php|database.*\.sql|.*\.bak(?:-.*)?|.*\.sha256)$">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    <FilesMatch "^(atualizar.*\.php|database.*\.sql|.*\.bak(?:-.*)?|.*\.sha256)$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</IfModule>
# END IECLB V0.30 SENSITIVE FILES
HTACCESS;

    $newSource = rtrim($source) . PHP_EOL . $block . PHP_EOL;
    if (file_put_contents($file, $newSource, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar o .htaccess principal.');
    }
    out('[OK] Atualizadores, dumps SQL, backups e checksums bloqueados via HTTP.');
}

function moveLegacyConfigBackups(string $configDir, string $destination): void
{
    protectDirectory($destination);
    $files = glob(rtrim($configDir, '/\\') . DIRECTORY_SEPARATOR . 'config.php.bak-*') ?: [];
    if ($files === []) {
        out('[OK] Não há backups antigos de config.php para mover.');
        return;
    }

    $moved = 0;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $target = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . basename($file);
        if (is_file($target)) {
            $target .= '-' . date('His');
        }
        if (@rename($file, $target)) {
            $moved++;
            continue;
        }
        if (@copy($file, $target) && @unlink($file)) {
            $moved++;
        }
    }
    out('[OK] Backups antigos de config.php movidos: ' . $moved . '/' . count($files) . '.');
}

function updateVersion(string $config, string $backupDir): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $safe = preg_replace('/[^0-9A-Za-z._-]+/', '-', $current) ?: 'sem-versao';
    $backup = backupFile($config, $backupDir, 'config-v' . $safe);
    if ($backup) {
        out('[OK] Backup do config.php: ' . $backup);
    }

    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (preg_match($pattern, $source)) {
        $source = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $source, 1) ?? $source;
    } else {
        $declarationPattern = '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/';
        if (preg_match($declarationPattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            $matchedText = $matches[0][0];
            $offset = $matches[0][1] + strlen($matchedText);
            $source = substr($source, 0, $offset) . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');" . substr($source, $offset);
        } else {
            $source = preg_replace('/^<\?php\s*/', "<?php\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');\n", $source, 1) ?? $source;
        }
    }

    if (file_put_contents($config, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    }
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado somente pelo terminal:\nphp atualizar_v0.30.0.php\n");
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$header = $root . '/admin/_header.php';
$service = $root . '/app/Services/SiteHealthService.php';
$page = $root . '/admin/ferramentas/saude.php';
$configProtection = $root . '/config/.htaccess';
$storage = $root . '/storage';
$configBackupDir = $storage . '/config-backups';
$updateBackupDir = $storage . '/update-backups/v0.30.0';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 76));

foreach ([$config, $dbFile, $header, $service, $page, $configProtection] as $required) {
    if (!is_file($required)) {
        fail('Arquivo necessário não encontrado: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $required));
    }
}

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    protectDirectory($configBackupDir);
    protectDirectory($updateBackupDir);

    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    ensurePermission($pdo);
    patchAdminHeader($header, $updateBackupDir);
    patchRootHtaccess($root . '/.htaccess', $updateBackupDir);
    moveLegacyConfigBackups($root . '/config', $configBackupDir);
    updateVersion($config, $configBackupDir);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Atualização v' . TARGET_VERSION . ' concluída com sucesso.');
    out('Acesse no painel: Ferramentas > Saúde do Portal.');
    out('Os scripts atualizar*.php agora ficam bloqueados via HTTP e continuam funcionando pelo terminal.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
