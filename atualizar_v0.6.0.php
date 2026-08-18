<?php

declare(strict_types=1);

const TARGET_VERSION = '0.6.0';

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
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
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
        fail('Não foi possível criar o backup de config/config.php. O banco foi atualizado, mas a versão não foi alterada.');
    }

    if (preg_match($pattern, $contents)) {
        $replacement = "define('APP_VERSION', '" . $targetVersion . "');";
        $updated = preg_replace($pattern, $replacement, $contents, 1);
        if ($updated === null) {
            fail('Falha ao atualizar APP_VERSION. Backup criado em ' . basename($backupPath) . '.');
        }
        $message = '[OK] APP_VERSION alterado para ' . $targetVersion . '.';
    } else {
        $definition = "define('APP_VERSION', '" . $targetVersion . "');";

        // Mantém declare(strict_types=1) como a primeira instrução PHP.
        if (preg_match('/declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/', $contents, $declareMatch, PREG_OFFSET_CAPTURE)) {
            $full = $declareMatch[0][0];
            $offset = $declareMatch[0][1] + strlen($full);
            $updated = substr($contents, 0, $offset) . PHP_EOL . PHP_EOL . $definition . substr($contents, $offset);
        } elseif (preg_match('/<\\?php\\s*/', $contents, $phpMatch, PREG_OFFSET_CAPTURE)) {
            $full = $phpMatch[0][0];
            $offset = $phpMatch[0][1] + strlen($full);
            $updated = substr($contents, 0, $offset) . PHP_EOL . PHP_EOL . $definition . substr($contents, $offset);
        } else {
            $updated = "<?php" . PHP_EOL . $definition . PHP_EOL . $contents;
        }
        $message = '[OK] APP_VERSION não existia e foi adicionada como ' . $targetVersion . '.';
    }

    if (file_put_contents($configPath, $updated) === false) {
        fail('Não foi possível gravar config/config.php. Backup criado em ' . basename($backupPath) . '.');
    }

    out($message);
    out('[OK] Backup do config: ' . basename($backupPath));
}

function seedPermission(PDO $pdo, string $nome, string $slug, string $grupo, string $descricao, int $ordem): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO permissoes (nome, slug, grupo, descricao, ordem)
         VALUES (:nome, :slug, :grupo, :descricao, :ordem)
         ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ordem = VALUES(ordem)'
    );
    $stmt->execute(compact('nome', 'slug', 'grupo', 'descricao', 'ordem'));

    $find = $pdo->prepare('SELECT id FROM permissoes WHERE slug = :slug LIMIT 1');
    $find->execute(['slug' => $slug]);
    return (int)$find->fetchColumn();
}

function addProfilePermission(PDO $pdo, string $perfilSlug, int $permissionId): void
{
    $stmt = $pdo->prepare('SELECT id FROM perfis WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $perfilSlug]);
    $perfilId = (int)$stmt->fetchColumn();
    if ($perfilId <= 0 || $permissionId <= 0) {
        return;
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:perfil_id, :permissao_id)');
    $insert->execute(['perfil_id' => $perfilId, 'permissao_id' => $permissionId]);
}

function seedSetting(PDO $pdo, string $key, string $value, string $type = 'texto'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave, valor, tipo)
         VALUES (:chave, :valor, :tipo)
         ON DUPLICATE KEY UPDATE tipo = VALUES(tipo)'
    );
    $stmt->execute(['chave' => $key, 'valor' => $value, 'tipo' => $type]);
}

$root = __DIR__;
$configPath = $root . '/config/config.php';
$dbClassPath = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 62));

if (!is_file($configPath)) {
    fail('Arquivo config/config.php não encontrado. Execute este arquivo na raiz do portal.');
}
if (!is_file($dbClassPath)) {
    fail('Arquivo mod/db/Database.php não encontrado. Extraia o pacote na raiz do portal.');
}

require_once $configPath;
require_once $dbClassPath;

$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : 'não definida';
out('Versão identificada: ' . $currentVersion);

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    foreach (['paginas', 'configuracoes', 'permissoes', 'perfil_permissoes'] as $requiredTable) {
        if (!tableExists($pdo, $requiredTable)) {
            fail('A tabela ' . $requiredTable . ' não existe. Atualize primeiro o portal até a v0.5.0.');
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS menus (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            slug VARCHAR(120) NOT NULL UNIQUE,
            localizacao VARCHAR(80) NOT NULL UNIQUE,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS menu_itens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            menu_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NULL,
            pagina_id INT UNSIGNED NULL,
            tipo ENUM('link','pagina') NOT NULL DEFAULT 'link',
            titulo VARCHAR(160) NOT NULL,
            url VARCHAR(500) NULL,
            nova_aba TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_menu_itens_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_itens_parent FOREIGN KEY (parent_id) REFERENCES menu_itens(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_itens_pagina FOREIGN KEY (pagina_id) REFERENCES paginas(id) ON DELETE SET NULL,
            INDEX idx_menu_itens_menu_ordem (menu_id, ordem),
            INDEX idx_menu_itens_parent (parent_id),
            INDEX idx_menu_itens_pagina (pagina_id)
        ) ENGINE=InnoDB"
    );

    if (!tableExists($pdo, 'menus') || !tableExists($pdo, 'menu_itens')) {
        fail('As tabelas de menus não foram criadas corretamente.');
    }
    out('[OK] Tabelas menus e menu_itens criadas/verificadas.');

    $menuPermissionId = seedPermission($pdo, 'Gerenciar menus', 'menus.gerenciar', 'Estrutura', 'Administrar os menus e links públicos do portal.', 80);
    $configPermissionId = seedPermission($pdo, 'Gerenciar configurações', 'configuracoes.gerenciar', 'Administração', 'Alterar identidade, contatos, redes sociais e SEO do portal.', 90);

    foreach (['administrador', 'secretaria', 'comunicacao'] as $perfil) {
        addProfilePermission($pdo, $perfil, $menuPermissionId);
    }
    foreach (['administrador', 'secretaria'] as $perfil) {
        addProfilePermission($pdo, $perfil, $configPermissionId);
    }
    out('[OK] Novas permissões cadastradas e aplicadas aos perfis padrão.');

    $settings = [
        ['site_endereco', '', 'texto'],
        ['site_logo_id', '', 'numero'],
        ['site_favicon_id', '', 'numero'],
        ['hero_titulo', 'IECLB Parobé', 'texto'],
        ['hero_subtitulo', 'Notícias, cultos, eventos e informações das comunidades da Paróquia de Parobé.', 'texto'],
        ['footer_texto', 'Paróquia Evangélica de Confissão Luterana de Parobé', 'texto'],
        ['seo_titulo', 'IECLB Parobé', 'texto'],
        ['seo_descricao', 'Portal da IECLB Parobé', 'texto'],
        ['seo_keywords', 'IECLB, Parobé, igreja luterana, cultos, eventos', 'texto'],
        ['seo_og_image_id', '', 'numero'],
    ];
    foreach ($settings as [$key, $value, $type]) {
        seedSetting($pdo, $key, $value, $type);
    }
    out('[OK] Configurações gerais e SEO criadas/verificadas.');

    $menuStmt = $pdo->prepare(
        "INSERT INTO menus (nome, slug, localizacao, ativo)
         VALUES ('Menu Principal', 'menu-principal', 'principal', 1)
         ON DUPLICATE KEY UPDATE nome = VALUES(nome), ativo = 1"
    );
    $menuStmt->execute();
    $menuId = (int)$pdo->query("SELECT id FROM menus WHERE localizacao='principal' LIMIT 1")->fetchColumn();
    if ($menuId <= 0) {
        fail('Não foi possível localizar o Menu Principal após a criação.');
    }

    $findLink = $pdo->prepare("SELECT COUNT(*) FROM menu_itens WHERE menu_id=:menu_id AND tipo='link' AND url=:url");
    $insertLink = $pdo->prepare("INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo) VALUES (:menu_id,'link',:titulo,:url,:ordem,1)");
    foreach ([
        ['Início', '/', 10],
        ['Agenda', 'agenda.php', 20],
        ['Comunidades', 'comunidades.php', 30],
    ] as [$title, $target, $order]) {
        $findLink->execute(['menu_id' => $menuId, 'url' => $target]);
        if ((int)$findLink->fetchColumn() === 0) {
            $insertLink->execute(['menu_id' => $menuId, 'titulo' => $title, 'url' => $target, 'ordem' => $order]);
        }
    }

    $pages = $pdo->query("SELECT id, titulo, ordem FROM paginas WHERE exibir_menu = 1 ORDER BY ordem, titulo")->fetchAll();
    $findPage = $pdo->prepare('SELECT COUNT(*) FROM menu_itens WHERE menu_id=:menu_id AND pagina_id=:pagina_id');
    $insertPage = $pdo->prepare("INSERT INTO menu_itens (menu_id,pagina_id,tipo,titulo,ordem,ativo) VALUES (:menu_id,:pagina_id,'pagina',:titulo,:ordem,1)");
    foreach ($pages as $page) {
        $findPage->execute(['menu_id' => $menuId, 'pagina_id' => $page['id']]);
        if ((int)$findPage->fetchColumn() === 0) {
            $insertPage->execute([
                'menu_id' => $menuId,
                'pagina_id' => $page['id'],
                'titulo' => $page['titulo'],
                'ordem' => 100 + (int)$page['ordem'],
            ]);
        }
    }
    out('[OK] Menu Principal criado e páginas existentes migradas.');
} catch (Throwable $e) {
    fail('Falha ao atualizar o banco: ' . $e->getMessage());
}

updateConfigVersion($configPath, TARGET_VERSION);

out(str_repeat('-', 62));
out('Atualização concluída com sucesso.');
out('Acesse o painel e abra "Menus" ou "Configurações".');
