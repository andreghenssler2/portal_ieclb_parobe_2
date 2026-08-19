<?php

declare(strict_types=1);

const TARGET_VERSION = '0.21.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function seedPermissions(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES (:nome,:slug,:grupo,:descricao,:ordem) '
        . 'ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)'
    );
    $items = [
        ['Gerenciar backups','backups.gerenciar','Administração','Criar, baixar, excluir e restaurar backups do banco de dados.',77],
        ['Gerenciar manutenção','manutencao.gerenciar','Administração','Ativar modo manutenção e executar rotinas de limpeza.',78],
    ];
    foreach ($items as [$nome,$slug,$grupo,$descricao,$ordem]) {
        $stmt->execute(compact('nome','slug','grupo','descricao','ordem'));
    }
    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
         SELECT pf.id,pe.id FROM perfis pf CROSS JOIN permissoes pe
         WHERE pf.slug='administrador' AND pe.slug IN ('backups.gerenciar','manutencao.gerenciar')"
    );
    out('[OK] Permissões de Backups e Manutenção criadas/verificadas.');
}

function seedSettings(PDO $pdo): void
{
    $defaults = [
        'backup_retention_count' => ['10','numero'],
        'maintenance_enabled' => ['0','booleano'],
        'maintenance_title' => ['Portal temporariamente em manutenção','texto'],
        'maintenance_message' => ['Estamos realizando melhorias. Tente novamente em alguns instantes.','texto'],
        'maintenance_expected_end' => ['','texto'],
        'maintenance_allow_admins' => ['1','booleano'],
        'maintenance_allowed_ips' => ['','texto'],
        'maintenance_enabled_at' => ['','texto'],
        'tools_theme_backup_retention_days' => ['90','numero'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo) '
        . 'ON DUPLICATE KEY UPDATE chave=VALUES(chave)'
    );
    foreach ($defaults as $chave => [$valor,$tipo]) {
        $stmt->execute(['chave'=>$chave,'valor'=>$valor,'tipo'=>$tipo]);
    }
    out('[OK] Configurações de Backups e Manutenção criadas/verificadas.');
}

function protectBackupStorage(string $root): void
{
    $dir = $root . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar storage/backups.');
    }
    $htaccess = "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    if (file_put_contents($dir . '/.htaccess', $htaccess, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível proteger storage/backups/.htaccess.');
    }
    if (file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível criar storage/backups/index.php.');
    }
    out('[OK] storage/backups criado e protegido.');
    if (!is_writable($dir)) {
        out('[AVISO] storage/backups não está gravável pelo usuário atual do PHP. Ajuste a permissão antes de criar backups pelo painel.');
    }
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $current = defined('APP_VERSION') ? (string)APP_VERSION : 'sem-versao';
    $backup = $config . '.bak-v' . preg_replace('/[^0-9A-Za-z._-]+/','-',$current) . '-' . date('Ymd-His');
    if (!copy($config,$backup)) throw new RuntimeException('Não foi possível criar backup de config.php.');

    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (preg_match($pattern,$source)) {
        $source = preg_replace($pattern,"define('APP_VERSION', '" . TARGET_VERSION . "');",$source,1) ?? $source;
    } else {
        $line = "define('APP_VERSION', '" . TARGET_VERSION . "');\n";
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/',$source,$m,PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $source = substr($source,0,$pos) . "\n\n" . $line . substr($source,$pos);
        } else {
            $source = preg_replace('/^<\?php\s*/',"<?php\n\n" . $line,$source,1) ?? ($line.$source);
        }
    }
    if (file_put_contents($config,$source,LOCK_EX) === false) throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    out('[OK] Backup do config: ' . basename($backup));
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$db = $root . '/mod/db/Database.php';
out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-',72));

if (!is_file($config)) fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
if (!is_file($db)) fail('mod/db/Database.php não encontrado.');
foreach ([
    'app/Services/BackupService.php',
    'admin/ferramentas/backups.php',
    'admin/ferramentas/manutencao.php',
    'admin/ferramentas/limpeza.php'
] as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.21.0 não encontrado: ' . $file);
}

require_once $config;
require_once $db;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    foreach (['usuarios','perfis','permissoes','perfil_permissoes','configuracoes','logs'] as $table) {
        if (!tableExists($pdo,$table)) throw new RuntimeException('A tabela ' . $table . ' não existe. Atualize o portal até a v0.20.0 antes desta versão.');
    }
    seedPermissions($pdo);
    seedSettings($pdo);
    protectBackupStorage($root);
    updateVersion($config);
    out(str_repeat('-',72));
    out('Atualização concluída com sucesso.');
    out('Novo menu: Ferramentas > Backups, Manutenção e Limpeza.');
    out('Importante: teste a criação de um backup pelo painel após a atualização.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
