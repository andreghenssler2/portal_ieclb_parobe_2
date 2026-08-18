<?php

declare(strict_types=1);

/**
 * Atualizador CLI - Portal IECLB Parobé v0.2.0 -> v0.3.0
 *
 * Uso, na raiz do portal:
 *   php atualizar_v0.3.0.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado somente pelo terminal (CLI).\n");
}

$root = __DIR__;
$configFile = $root . '/config/config.php';
$databaseFile = $root . '/mod/db/Database.php';
$migrationFile = $root . '/migrations/2026_08_18_v0.3.0.sql';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, '[ERRO] ' . $message . PHP_EOL);
    exit($code);
}

out('Portal IECLB Parobé - atualização para v0.3.0');
out(str_repeat('-', 49));

if (!is_file($configFile)) {
    fail('Arquivo config/config.php não encontrado. Execute este arquivo na raiz do portal.');
}

if (!is_file($databaseFile)) {
    fail('Arquivo mod/db/Database.php não encontrado.');
}

if (!is_file($migrationFile)) {
    fail('Migração migrations/2026_08_18_v0.3.0.sql não encontrada.');
}

require_once $configFile;
require_once $databaseFile;

$currentVersion = defined('APP_VERSION') ? (string) APP_VERSION : 'desconhecida';
out('Versão identificada: ' . $currentVersion);

if (version_compare($currentVersion, '0.3.0', '>=')) {
    out('A versão informada no config já é v0.3.0 ou superior.');
}

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    // A v0.3.0 depende da Biblioteca de Mídia criada na v0.2.0.
    $stmt = $pdo->query("SHOW TABLES LIKE 'midias'");
    if (!$stmt->fetchColumn()) {
        fail('A tabela midias não existe. Atualize primeiro o portal para a v0.2.0.');
    }

    $sql = trim((string) file_get_contents($migrationFile));
    if ($sql === '') {
        fail('O arquivo de migração está vazio.');
    }

    $pdo->exec($sql);
    out('[OK] Tabela paginas criada/verificada.');

    $requiredColumns = [
        'id', 'autor_id', 'titulo', 'slug', 'resumo', 'conteudo',
        'imagem_capa_id', 'status', 'exibir_menu', 'ordem',
        'publicado_em', 'created_at', 'updated_at'
    ];

    $columns = $pdo->query('SHOW COLUMNS FROM paginas')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($requiredColumns, $columns));
    if ($missing !== []) {
        fail('A tabela paginas foi encontrada, mas faltam colunas: ' . implode(', ', $missing));
    }

    out('[OK] Estrutura da tabela paginas validada.');
} catch (PDOException $e) {
    fail('Falha no banco de dados: ' . $e->getMessage());
} catch (Throwable $e) {
    fail($e->getMessage());
}

$configContents = file_get_contents($configFile);
if ($configContents === false) {
    fail('Não foi possível ler config/config.php.');
}

$pattern = "/define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]+['\"]\\s*\\)\\s*;/";
$replacement = "define('APP_VERSION', '0.3.0');";
$updatedConfig = preg_replace($pattern, $replacement, $configContents, 1, $count);

if ($updatedConfig === null) {
    fail('Não foi possível processar APP_VERSION em config/config.php.');
}

if ($count === 0) {
    fail('A constante APP_VERSION não foi encontrada em config/config.php. O banco foi atualizado, mas a versão não foi alterada.');
}

if ($updatedConfig !== $configContents) {
    $backup = $configFile . '.bak-v' . preg_replace('/[^0-9A-Za-z._-]/', '_', $currentVersion) . '-' . date('Ymd-His');
    if (!copy($configFile, $backup)) {
        fail('Não foi possível criar o backup de config/config.php. A versão não foi alterada.');
    }

    if (file_put_contents($configFile, $updatedConfig, LOCK_EX) === false) {
        fail('Não foi possível atualizar APP_VERSION. Backup criado em: ' . $backup);
    }

    out('[OK] APP_VERSION alterado para 0.3.0.');
    out('[OK] Backup do config: ' . basename($backup));
} else {
    out('[OK] APP_VERSION já estava definido como 0.3.0.');
}

out(str_repeat('-', 49));
out('Atualização concluída com sucesso.');
out('Você já pode remover atualizar_v0.3.0.php do servidor.');
