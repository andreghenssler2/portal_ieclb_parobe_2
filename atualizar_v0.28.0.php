<?php

declare(strict_types=1);

const TARGET_VERSION = '0.28.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}
function createHomeTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'home_secoes')) {
        $pdo->exec("CREATE TABLE home_secoes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(160) NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'carousel',
            origem VARCHAR(30) NOT NULL DEFAULT 'posts',
            categoria_id INT UNSIGNED NULL,
            link_texto VARCHAR(80) NULL,
            link_url VARCHAR(500) NULL,
            limite TINYINT UNSIGNED NOT NULL DEFAULT 8,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 10,
            configuracao_json TEXT NULL,
            usuario_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_home_secoes_ativo_ordem (ativo, ordem),
            KEY idx_home_secoes_categoria (categoria_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        out('[OK] Tabela home_secoes criada.');
    } else {
        out('[OK] Tabela home_secoes já existe.');
    }
}
function seedPermission(PDO $pdo): int
{
    $stmt = $pdo->prepare("INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
        VALUES ('Gerenciar Página Inicial','home.gerenciar','Aparência','Adicionar, remover, ordenar e configurar as seções da página inicial.',44)
        ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)");
    $stmt->execute();
    return (int)$pdo->query("SELECT id FROM permissoes WHERE slug='home.gerenciar' LIMIT 1")->fetchColumn();
}
function grantAdministrator(PDO $pdo, int $permissionId): void
{
    $profileId = (int)$pdo->query("SELECT id FROM perfis WHERE slug='administrador' LIMIT 1")->fetchColumn();
    if ($profileId > 0 && $permissionId > 0) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id) VALUES (:perfil,:permissao)');
        $stmt->execute(['perfil' => $profileId, 'permissao' => $permissionId]);
    }
}
function findCategory(PDO $pdo, array $terms): ?array
{
    if (!tableExists($pdo, 'categorias')) return null;
    $nameCol = columnExists($pdo, 'categorias', 'nome') ? 'nome' : (columnExists($pdo, 'categorias', 'titulo') ? 'titulo' : null);
    $slugCol = columnExists($pdo, 'categorias', 'slug') ? 'slug' : null;
    if (!$nameCol && !$slugCol) return null;
    $select = 'id' . ($nameCol ? ',`' . $nameCol . '` AS nome' : ",'' AS nome") . ($slugCol ? ',`' . $slugCol . '` AS slug' : ",'' AS slug");
    $rows = $pdo->query('SELECT ' . $select . ' FROM categorias ORDER BY id ASC')->fetchAll() ?: [];
    foreach ($terms as $term) {
        $term = function_exists('mb_strtolower') ? mb_strtolower((string)$term, 'UTF-8') : strtolower((string)$term);
        foreach ($rows as $row) {
            $rawHay = trim((string)$row['nome']) . ' ' . trim((string)$row['slug']);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($rawHay, 'UTF-8') : strtolower($rawHay);
            if ($hay !== '' && str_contains($hay, $term)) return $row;
        }
    }
    return null;
}
function seedHomeSections(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM home_secoes')->fetchColumn() > 0) {
        out('[OK] Seções existentes preservadas.');
        return;
    }
    $community = findCategory($pdo, ['comunidade','comunidades']);
    $parish = findCategory($pdo, ['paroquial','paróquia','paroquia']);
    $stmt = $pdo->prepare('INSERT INTO home_secoes (titulo,tipo,origem,categoria_id,link_texto,link_url,limite,ativo,ordem,configuracao_json,created_at,updated_at) VALUES (:titulo,:tipo,:origem,:categoria,:texto,:url,:limite,1,:ordem,:config,NOW(),NOW())');
    $defaults = [
        ['Últimas Notícias','featured','posts',null,'Veja mais','/noticias.php',3,10,['show_date'=>false,'show_excerpt'=>false,'autoplay'=>false]],
        ['Comunidades','carousel','posts',$community ? (int)$community['id'] : null,'Mostrar Todos',$community && !empty($community['slug']) ? '/noticias.php?categoria=' . rawurlencode((string)$community['slug']) : '/noticias.php',8,20,['show_date'=>true,'show_excerpt'=>false,'autoplay'=>false]],
        ['Paróquia','carousel','posts',$parish ? (int)$parish['id'] : null,'Mostrar Todos',$parish && !empty($parish['slug']) ? '/noticias.php?categoria=' . rawurlencode((string)$parish['slug']) : '/noticias.php',8,30,['show_date'=>true,'show_excerpt'=>false,'autoplay'=>false]],
    ];
    foreach ($defaults as [$title,$type,$source,$category,$text,$url,$limit,$order,$config]) {
        $stmt->execute(['titulo'=>$title,'tipo'=>$type,'origem'=>$source,'categoria'=>$category,'texto'=>$text,'url'=>$url,'limite'=>$limit,'ordem'=>$order,'config'=>json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    out('[OK] Seções padrão criadas: Últimas Notícias, Comunidades e Paróquia.');
}
function patchIndex(string $root): void
{
    $file = $root . '/index.php';
    if (!is_file($file)) {
        out('[AVISO] index.php não encontrado. A integração automática da home não foi realizada.');
        return;
    }
    $source = (string)file_get_contents($file);
    if (str_contains($source, 'HOME_MODULAR_V028')) {
        out('[OK] index.php já está integrado à home modular.');
        return;
    }
    if (!preg_match('/<main\b[^>]*>/i', $source, $m, PREG_OFFSET_CAPTURE)) {
        out('[AVISO] Não encontrei uma tag <main> literal no index.php. O arquivo foi preservado; use public/home-modular.php no ponto desejado.');
        file_put_contents($root . '/INTEGRAR_HOME_v0.28.0.txt', "Inclua dentro do conteúdo principal do index.php:\r\n<?php /* HOME_MODULAR_V028 */ require __DIR__ . '/public/home-modular.php'; ?>\r\n");
        return;
    }
    $openTag = $m[0][0];
    $openPos = (int)$m[0][1];
    $contentStart = $openPos + strlen($openTag);
    $closePos = stripos($source, '</main>', $contentStart);
    if ($closePos === false) {
        out('[AVISO] Tag </main> não encontrada. index.php foi preservado.');
        return;
    }
    $backup = $file . '.bak-v0.27.2-' . date('Ymd-His');
    if (!copy($file, $backup)) throw new RuntimeException('Não foi possível criar backup do index.php.');
    $replacement = "\n<?php /* HOME_MODULAR_V028 */ require __DIR__ . '/public/home-modular.php'; ?>\n";
    $patched = substr($source, 0, $contentStart) . $replacement . substr($source, $closePos);
    if (file_put_contents($file, $patched, LOCK_EX) === false) throw new RuntimeException('Não foi possível integrar a home modular ao index.php.');
    out('[OK] Home modular integrada ao <main> do index.php.');
    out('[OK] Backup do index: ' . basename($backup));
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
    out('[OK] Backup do config: ' . basename($backup));
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 72));
if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach (['app/Services/HomeService.php','admin/aparencia/home.php','admin/_header.php','bootstrap.php','public/home-modular.php','public/css/home-modular.css','public/js/home-modular.js'] as $required) {
    if (!is_file($root . '/' . $required)) fail('Arquivo da v0.28.0 não encontrado: ' . $required);
}
require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));
try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    foreach (['perfis','permissoes','perfil_permissoes','posts','paginas','eventos','midias','categorias'] as $table) {
        if (!tableExists($pdo, $table)) throw new RuntimeException('Tabela obrigatória ausente: ' . $table . '.');
    }
    createHomeTable($pdo);
    $permissionId = seedPermission($pdo);
    grantAdministrator($pdo, $permissionId);
    out('[OK] Permissão home.gerenciar criada/verificada.');
    seedHomeSections($pdo);
    patchIndex($root);
    updateVersion($config);
    out(str_repeat('-', 72));
    out('Atualização concluída com sucesso.');
    out('Gerencie a home em: ' . rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/admin/aparencia/home.php');
} catch (Throwable $e) {
    fail($e->getMessage());
}
