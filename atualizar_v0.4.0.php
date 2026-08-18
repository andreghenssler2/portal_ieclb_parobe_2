<?php

declare(strict_types=1);

const TARGET_VERSION = '0.4.0';

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

    $backupPath = $configPath . '.bak-' . date('Ymd-His');
    if (!copy($configPath, $backupPath)) {
        fail('Não foi possível criar o backup de config/config.php. O banco foi atualizado, mas a versão não foi alterada.');
    }

    $pattern = "/define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";
    if (preg_match($pattern, $contents)) {
        $replacement = "define('APP_VERSION', '" . $targetVersion . "');";
        $updated = preg_replace($pattern, $replacement, $contents, 1);
        if ($updated === null) {
            fail('Falha ao atualizar APP_VERSION. Backup criado em ' . basename($backupPath) . '.');
        }
        $message = '[OK] APP_VERSION alterado para ' . $targetVersion . '.';
    } else {
        $definition = "define('APP_VERSION', '" . $targetVersion . "');";

        // Mantém declare(strict_types=1) como a primeira instrução do arquivo.
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $contents, $declareMatch, PREG_OFFSET_CAPTURE)) {
            $full = $declareMatch[0][0];
            $offset = $declareMatch[0][1] + strlen($full);
            $updated = substr($contents, 0, $offset) . PHP_EOL . PHP_EOL . $definition . substr($contents, $offset);
        } elseif (preg_match('/<\?php\s*/', $contents, $phpMatch, PREG_OFFSET_CAPTURE)) {
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

$root = __DIR__;
$configPath = $root . '/config/config.php';
$dbClassPath = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 58));

if (!is_file($configPath)) {
    fail('Arquivo config/config.php não encontrado. Execute este arquivo na raiz do portal.');
}
if (!is_file($dbClassPath)) {
    fail('Arquivo mod/db/Database.php não encontrado. O pacote deve ser extraído na raiz do portal.');
}

require_once $configPath;
require_once $dbClassPath;

$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : 'não definida';
out('Versão identificada: ' . $currentVersion);

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS eventos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            autor_id INT UNSIGNED NOT NULL,
            comunidade_id INT UNSIGNED NULL,
            tipo ENUM('culto','evento') NOT NULL DEFAULT 'evento',
            titulo VARCHAR(220) NOT NULL,
            slug VARCHAR(240) NOT NULL UNIQUE,
            resumo TEXT NULL,
            descricao LONGTEXT NULL,
            local VARCHAR(255) NULL,
            endereco VARCHAR(255) NULL,
            data_inicio DATETIME NOT NULL,
            data_fim DATETIME NULL,
            santa_ceia TINYINT(1) NOT NULL DEFAULT 0,
            imagem_capa_id BIGINT UNSIGNED NULL,
            status ENUM('rascunho','publicado','cancelado','arquivado') NOT NULL DEFAULT 'rascunho',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_eventos_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
            CONSTRAINT fk_eventos_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidades(id) ON DELETE SET NULL,
            CONSTRAINT fk_eventos_imagem_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL,
            INDEX idx_eventos_status_data (status, data_inicio),
            INDEX idx_eventos_tipo_data (tipo, data_inicio),
            INDEX idx_eventos_comunidade (comunidade_id),
            INDEX idx_eventos_imagem_capa (imagem_capa_id)
        ) ENGINE=InnoDB"
    );

    if (!tableExists($pdo, 'eventos')) {
        fail('A tabela eventos não foi criada.');
    }

    out('[OK] Tabela eventos criada/verificada.');
} catch (Throwable $e) {
    fail('Falha ao atualizar o banco: ' . $e->getMessage());
}

updateConfigVersion($configPath, TARGET_VERSION);

out(str_repeat('-', 58));
out('Atualização concluída com sucesso.');
out('Agora acesse o painel e abra "Eventos e Cultos".');
