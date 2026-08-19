<?php

declare(strict_types=1);

const TARGET_VERSION = '0.20.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table'=>$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column');
    $stmt->execute(['table'=>$table,'column'=>$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:idx');
    $stmt->execute(['table'=>$table,'idx'=>$index]);
    return (int)$stmt->fetchColumn() > 0;
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (columnExists($pdo,$table,$column)) {
        out('[OK] Coluna '.$table.'.'.$column.' já existe.');
        return;
    }
    $pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition);
    out('[OK] Coluna '.$table.'.'.$column.' criada.');
}

function addIndex(PDO $pdo, string $table, string $name, string $columns): void
{
    if (indexExists($pdo,$table,$name)) {
        out('[OK] Índice '.$name.' já existe.');
        return;
    }
    $pdo->exec('CREATE INDEX `'.$name.'` ON `'.$table.'` ('.$columns.')');
    out('[OK] Índice '.$name.' criado.');
}

function seedPermissions(PDO $pdo): void
{
    $stmt=$pdo->prepare(
        'INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES (:nome,:slug,:grupo,:descricao,:ordem) '
        . 'ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)'
    );
    $items=[
        ['Visualizar auditoria','auditoria.visualizar','Administração','Consultar e exportar registros de auditoria e segurança.',75],
        ['Gerenciar segurança','seguranca.gerenciar','Administração','Configurar sessão, bloqueio de login e retenção da auditoria.',76],
    ];
    foreach($items as [$nome,$slug,$grupo,$descricao,$ordem]){
        $stmt->execute(compact('nome','slug','grupo','descricao','ordem'));
    }

    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
         SELECT pf.id,pe.id FROM perfis pf CROSS JOIN permissoes pe
         WHERE pf.slug='administrador' AND pe.slug IN ('auditoria.visualizar','seguranca.gerenciar')"
    );
    out('[OK] Permissões de Auditoria e Segurança criadas/verificadas.');
}

function seedSettings(PDO $pdo): void
{
    $defaults=[
        'security_session_timeout_minutes'=>['60','numero'],
        'security_max_login_attempts'=>['5','numero'],
        'security_lockout_minutes'=>['15','numero'],
        'security_audit_retention_days'=>['180','numero'],
        'security_log_failed_logins'=>['1','booleano'],
    ];
    $stmt=$pdo->prepare(
        'INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo) '
        . 'ON DUPLICATE KEY UPDATE chave=VALUES(chave)'
    );
    foreach($defaults as $chave=>[$valor,$tipo]){
        $stmt->execute(['chave'=>$chave,'valor'=>$valor,'tipo'=>$tipo]);
    }
    out('[OK] Configurações de segurança criadas/verificadas.');
}

function updateVersion(string $config): void
{
    $source=(string)file_get_contents($config);
    $current=defined('APP_VERSION')?(string)APP_VERSION:'sem-versao';
    $backup=$config.'.bak-v'.preg_replace('/[^0-9A-Za-z._-]+/','-',$current).'-'.date('Ymd-His');
    if(!copy($config,$backup)) throw new RuntimeException('Não foi possível criar backup de config.php.');

    $pattern="/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if(preg_match($pattern,$source)){
        $source=preg_replace($pattern,"define('APP_VERSION', '".TARGET_VERSION."');",$source,1)??$source;
    }else{
        $line="define('APP_VERSION', '".TARGET_VERSION."');\n";
        if(preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/',$source,$m,PREG_OFFSET_CAPTURE)){
            $pos=$m[0][1]+strlen($m[0][0]);
            $source=substr($source,0,$pos)."\n\n".$line.substr($source,$pos);
        }else{
            $source=preg_replace('/^<\?php\s*/',"<?php\n\n".$line,$source,1)??($line.$source);
        }
    }
    if(file_put_contents($config,$source,LOCK_EX)===false) throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    out('[OK] Backup do config: '.basename($backup));
    out('[OK] APP_VERSION atualizado para '.TARGET_VERSION.'.');
}

$root=__DIR__;
$config=$root.'/config/config.php';
$db=$root.'/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v'.TARGET_VERSION);
out(str_repeat('-',72));

if(!is_file($config)) fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
if(!is_file($db)) fail('mod/db/Database.php não encontrado.');
foreach([
    'admin/auditoria/index.php',
    'admin/auditoria/exportar.php',
    'admin/configuracoes/seguranca.php',
    'mod/auth/Auth.php',
    'mod/auth/Session.php'
] as $file){
    if(!is_file($root.'/'.$file)) fail('Arquivo da v0.20.0 não encontrado: '.$file);
}

require_once $config;
require_once $db;
out('Versão identificada: '.(defined('APP_VERSION')?(string)APP_VERSION:'não definida'));

try{
    $pdo=Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach(['logs','usuarios','perfis','permissoes','perfil_permissoes','configuracoes'] as $table){
        if(!tableExists($pdo,$table)) throw new RuntimeException('A tabela '.$table.' não existe. Atualize o portal até a v0.19.0 antes desta versão.');
    }

    addColumn($pdo,'logs','nivel',"VARCHAR(20) NOT NULL DEFAULT 'info' AFTER `ip`");
    addColumn($pdo,'logs','metodo',"VARCHAR(10) NULL AFTER `nivel`");
    addColumn($pdo,'logs','rota',"VARCHAR(255) NULL AFTER `metodo`");
    addColumn($pdo,'logs','user_agent',"VARCHAR(255) NULL AFTER `rota`");
    addColumn($pdo,'logs','request_id',"VARCHAR(64) NULL AFTER `user_agent`");

    addIndex($pdo,'logs','idx_logs_nivel_created','`nivel`,`created_at`');
    addIndex($pdo,'logs','idx_logs_usuario_created','`usuario_id`,`created_at`');
    addIndex($pdo,'logs','idx_logs_acao_created','`acao`,`created_at`');

    if(!tableExists($pdo,'login_tentativas')){
        $pdo->exec(
            "CREATE TABLE login_tentativas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NULL,
                sucesso TINYINT(1) NOT NULL DEFAULT 0,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_tentativas_email_data (email,created_at),
                INDEX idx_login_tentativas_ip_data (ip,created_at),
                INDEX idx_login_tentativas_sucesso_data (sucesso,created_at)
            ) ENGINE=InnoDB"
        );
        out('[OK] Tabela login_tentativas criada.');
    }else{
        out('[OK] Tabela login_tentativas já existe.');
    }

    seedPermissions($pdo);
    seedSettings($pdo);
    updateVersion($config);

    out(str_repeat('-',72));
    out('Atualização concluída com sucesso.');
    out('Novos módulos: Auditoria e Configurações > Segurança.');
    out('Proteções: expiração por inatividade e bloqueio temporário de tentativas de login.');
}catch(Throwable $e){
    fail($e->getMessage());
}
