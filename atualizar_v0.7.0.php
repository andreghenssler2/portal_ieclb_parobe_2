<?php

declare(strict_types=1);

const TARGET_VERSION = '0.7.0';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message, int $code = 1): never
{
    out('[ERRO] ' . $message);
    exit($code);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function updateConfigVersion(string $configPath, string $targetVersion): void
{
    $contents = file_get_contents($configPath);
    if ($contents === false) {
        fail('Não foi possível ler config/config.php. O banco foi atualizado, mas a versão não foi alterada.');
    }

    $pattern = "/define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)\\s*;/";
    if (preg_match($pattern, $contents, $match) && ($match[1] ?? '') === $targetVersion) {
        out('[OK] APP_VERSION já está em ' . $targetVersion . '.');
        return;
    }

    $backupPath = $configPath . '.bak-' . date('Ymd-His');
    if (!copy($configPath, $backupPath)) {
        fail('Não foi possível criar o backup de config/config.php.');
    }

    if (preg_match($pattern, $contents)) {
        $updated = preg_replace($pattern, "define('APP_VERSION', '" . $targetVersion . "');", $contents, 1);
        if ($updated === null) {
            fail('Falha ao atualizar APP_VERSION.');
        }
        $message = '[OK] APP_VERSION alterado para ' . $targetVersion . '.';
    } else {
        $definition = "define('APP_VERSION', '" . $targetVersion . "');";
        if (preg_match('/declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $updated = substr($contents, 0, $offset) . PHP_EOL . PHP_EOL . $definition . substr($contents, $offset);
        } elseif (preg_match('/<\\?php\\s*/', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $updated = substr($contents, 0, $offset) . PHP_EOL . PHP_EOL . $definition . substr($contents, $offset);
        } else {
            $updated = "<?php" . PHP_EOL . $definition . PHP_EOL . $contents;
        }
        $message = '[OK] APP_VERSION não existia e foi adicionada como ' . $targetVersion . '.';
    }

    if (file_put_contents($configPath, $updated, LOCK_EX) === false) {
        fail('Não foi possível gravar config/config.php. Backup: ' . basename($backupPath));
    }
    out($message);
    out('[OK] Backup do config: ' . basename($backupPath));
}

function seedPermission(PDO $pdo, string $nome, string $slug, string $grupo, string $descricao, int $ordem): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO permissoes (nome, slug, grupo, descricao, ordem)
         VALUES (:nome,:slug,:grupo,:descricao,:ordem)
         ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)'
    );
    $stmt->execute(compact('nome', 'slug', 'grupo', 'descricao', 'ordem'));
    $find = $pdo->prepare('SELECT id FROM permissoes WHERE slug=:slug LIMIT 1');
    $find->execute(['slug' => $slug]);
    return (int)$find->fetchColumn();
}

function addProfilePermission(PDO $pdo, string $perfilSlug, int $permissionId): void
{
    $stmt = $pdo->prepare('SELECT id FROM perfis WHERE slug=:slug LIMIT 1');
    $stmt->execute(['slug' => $perfilSlug]);
    $perfilId = (int)$stmt->fetchColumn();
    if ($perfilId <= 0 || $permissionId <= 0) return;
    $insert = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:perfil,:permissao)');
    $insert->execute(['perfil' => $perfilId, 'permissao' => $permissionId]);
}

function installGalleryRoute(string $root): void
{
    $rulesPath = $root . '/rotas_v0.7.0.htaccess';
    if (!is_file($rulesPath)) {
        fail('Arquivo rotas_v0.7.0.htaccess não encontrado.');
    }
    $rules = trim((string)file_get_contents($rulesPath));
    if ($rules === '') {
        fail('Arquivo de rotas v0.7.0 está vazio.');
    }

    $htaccess = $root . '/.htaccess';
    $begin = '# BEGIN PORTAL_IECLB_ROTAS_V070';
    $end = '# END PORTAL_IECLB_ROTAS_V070';
    $block = $begin . PHP_EOL . $rules . PHP_EOL . $end;
    $existing = is_file($htaccess) ? (string)file_get_contents($htaccess) : '';
    $pattern = '/(?:\\R)?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '(?:\\R)?/s';
    $clean = trim((string)preg_replace($pattern, PHP_EOL, $existing));

    if (is_file($htaccess)) {
        $backup = $htaccess . '.bak-v070-' . date('Ymd-His');
        if (!copy($htaccess, $backup)) {
            fail('Não foi possível criar backup do .htaccess.');
        }
        out('[OK] Backup do .htaccess: ' . basename($backup));
    }

    $new = $block . PHP_EOL . ($clean !== '' ? PHP_EOL . $clean . PHP_EOL : '');
    if (file_put_contents($htaccess, $new, LOCK_EX) === false) {
        fail('Não foi possível gravar o .htaccess.');
    }
    out('[OK] Rota amigável /galeria/{slug} instalada/verificada.');
}

$root = __DIR__;
$configPath = $root . '/config/config.php';
$dbClassPath = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 64));

if (!is_file($configPath)) fail('config/config.php não encontrado. Execute este arquivo na raiz do portal.');
if (!is_file($dbClassPath)) fail('mod/db/Database.php não encontrado. Extraia o pacote na raiz do portal.');

$requiredFiles = [
    'admin/galerias/index.php', 'admin/galerias/form.php',
    'admin/banners/index.php', 'admin/banners/form.php',
    'galerias.php', 'galeria.php', 'app/Helpers/functions.php',
];
foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) fail('Arquivo da v0.7.0 não encontrado: ' . $file);
}

require_once $configPath;
require_once $dbClassPath;
$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : 'não definida';
out('Versão identificada: ' . $currentVersion);

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach (['midias','usuarios','perfis','permissoes','perfil_permissoes','menus','menu_itens'] as $table) {
        if (!tableExists($pdo, $table)) {
            fail('A tabela ' . $table . ' não existe. Atualize primeiro o portal até a v0.6.0.');
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS galerias (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            autor_id INT UNSIGNED NOT NULL,
            titulo VARCHAR(220) NOT NULL,
            slug VARCHAR(240) NOT NULL UNIQUE,
            descricao TEXT NULL,
            imagem_capa_id BIGINT UNSIGNED NULL,
            status ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
            publicado_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_galerias_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
            CONSTRAINT fk_galerias_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL,
            INDEX idx_galerias_status_data (status, publicado_em),
            INDEX idx_galerias_capa (imagem_capa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS galeria_midias (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            galeria_id INT UNSIGNED NOT NULL,
            midia_id BIGINT UNSIGNED NOT NULL,
            legenda VARCHAR(255) NULL,
            ordem INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_galeria_midias_galeria FOREIGN KEY (galeria_id) REFERENCES galerias(id) ON DELETE CASCADE,
            CONSTRAINT fk_galeria_midias_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE CASCADE,
            UNIQUE KEY uk_galeria_midia (galeria_id, midia_id),
            INDEX idx_galeria_midias_ordem (galeria_id, ordem),
            INDEX idx_galeria_midias_midia (midia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS banners (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(180) NULL,
            subtitulo VARCHAR(500) NULL,
            imagem_id BIGINT UNSIGNED NOT NULL,
            url_link VARCHAR(500) NULL,
            texto_botao VARCHAR(80) NULL,
            nova_aba TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 0,
            data_inicio DATETIME NULL,
            data_fim DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_banners_imagem FOREIGN KEY (imagem_id) REFERENCES midias(id) ON DELETE CASCADE,
            INDEX idx_banners_exibicao (ativo, data_inicio, data_fim, ordem),
            INDEX idx_banners_imagem (imagem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach (['galerias','galeria_midias','banners'] as $table) {
        if (!tableExists($pdo, $table)) fail('A tabela ' . $table . ' não foi criada corretamente.');
    }
    out('[OK] Tabelas galerias, galeria_midias e banners criadas/verificadas.');

    $galeriaPermission = seedPermission($pdo, 'Gerenciar galerias', 'galerias.gerenciar', 'Conteúdo', 'Criar, editar e publicar galerias de fotos.', 45);
    $bannerPermission = seedPermission($pdo, 'Gerenciar banners', 'banners.gerenciar', 'Conteúdo', 'Administrar os banners e destaques da página inicial.', 46);
    foreach (['administrador','secretaria','comunicacao'] as $perfil) addProfilePermission($pdo, $perfil, $galeriaPermission);
    foreach (['administrador','comunicacao'] as $perfil) addProfilePermission($pdo, $perfil, $bannerPermission);
    out('[OK] Permissões de Galerias e Banners cadastradas.');

    $menuId = (int)$pdo->query("SELECT id FROM menus WHERE localizacao='principal' LIMIT 1")->fetchColumn();
    if ($menuId > 0) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM menu_itens WHERE menu_id=:menu AND tipo='link' AND url='galerias.php'");
        $check->execute(['menu' => $menuId]);
        if ((int)$check->fetchColumn() === 0) {
            $stmt = $pdo->prepare("INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo) VALUES (:menu,'link','Galerias','galerias.php',40,1)");
            $stmt->execute(['menu' => $menuId]);
            out('[OK] Item Galerias adicionado ao Menu Principal.');
        } else {
            out('[OK] Item Galerias já existe no Menu Principal.');
        }
    }
} catch (Throwable $e) {
    fail('Falha ao atualizar o banco: ' . $e->getMessage());
}

installGalleryRoute($root);
updateConfigVersion($configPath, TARGET_VERSION);

out(str_repeat('-', 64));
out('Atualização concluída com sucesso.');
out('Novos módulos: Galerias e Banners.');
out('Rota pública: /galeria/minha-galeria');
