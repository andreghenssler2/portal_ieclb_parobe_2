<?php

declare(strict_types=1);

const TARGET_VERSION = '0.32.0';
const MINIMUM_VERSION = '0.31.0';

function out32(string $message = ''): void { echo $message . PHP_EOL; }
function fail32(string $message): never { out32('[ERRO] ' . $message); exit(1); }

function ensureDir32(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar: ' . $dir);
    }
}

function backup32(string $file, string $backupDir): ?string
{
    if (!is_file($file)) return null;
    ensureDir32($backupDir);
    $name = basename($file) . '.bak-' . date('Ymd-His') . '-' . substr(hash_file('sha256', $file), 0, 8);
    $target = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!copy($file, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $file);
    }
    return $target;
}

function writePatched32(string $file, string $content, string $backupDir): void
{
    $current = (string)file_get_contents($file);
    if ($current === $content) return;
    $backup = backup32($file, $backupDir);
    if ($backup) out32('[OK] Backup: ' . str_replace('\\', '/', $backup));
    if (file_put_contents($file, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $file);
    }
}

function ensureDatabase32(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS midia_variantes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            midia_id BIGINT UNSIGNED NOT NULL,
            largura INT UNSIGNED NOT NULL,
            altura INT UNSIGNED NOT NULL,
            formato VARCHAR(12) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            caminho VARCHAR(500) NOT NULL,
            tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
            qualidade TINYINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_midia_variante (midia_id, largura, formato),
            UNIQUE KEY uq_midia_variante_caminho (caminho),
            KEY idx_midia_variantes_midia (midia_id),
            CONSTRAINT fk_midia_variantes_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo)
         ON DUPLICATE KEY UPDATE chave=VALUES(chave)'
    );
    foreach ([
        ['media_optimize_enabled', '1', 'booleano'],
        ['media_generate_webp', '1', 'booleano'],
        ['media_variant_widths', '320,640,1024,1600', 'texto'],
        ['media_image_quality', '82', 'numero'],
    ] as [$key, $value, $type]) {
        $stmt->execute(['chave' => $key, 'valor' => $value, 'tipo' => $type]);
    }
    out32('[OK] Banco preparado para variantes de mídia.');
}

function patchMediaService32(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    $original = $src;

    if (!str_contains($src, "ImageOptimizationService.php")) {
        $needle = "declare(strict_types=1);";
        if (!str_contains($src, $needle)) throw new RuntimeException('Não foi possível localizar declare em MediaService.php.');
        $src = str_replace($needle, $needle . "\n\nrequire_once __DIR__ . '/ImageOptimizationService.php';", $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao registrar ImageOptimizationService em MediaService.php.');
    }

    if (!str_contains($src, 'v0.32.0: gera variantes automaticamente')) {
        $needle = "        if (!\$media) {\n            throw new RuntimeException('Não foi possível recuperar a mídia enviada.');\n        }\n\n        return \$media;";
        if (!str_contains($src, $needle)) throw new RuntimeException('Não foi possível localizar o retorno do upload em MediaService.php.');
        $replacement = "        if (!\$media) {\n            throw new RuntimeException('Não foi possível recuperar a mídia enviada.');\n        }\n\n        // v0.32.0: gera variantes automaticamente sem comprometer o upload.\n        if (self::isImage(\$media)) {\n            try {\n                \$optSettings = ImageOptimizationService::settings(\$pdo);\n                if (\$optSettings['enabled']) {\n                    ImageOptimizationService::optimizeMedia(\$pdo, \$id, false);\n                }\n            } catch (Throwable \$ignored) {\n                // O original já foi salvo; falha na otimização não cancela o upload.\n            }\n        }\n\n        return \$media;";
        $src = str_replace($needle, $replacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao integrar otimização automática ao upload.');
    }

    if (!str_contains($src, 'v0.32.0: remove derivados físicos')) {
        $needle = "        \$stmt = \$pdo->prepare('DELETE FROM midias WHERE id = :id');";
        if (!str_contains($src, $needle)) throw new RuntimeException('Não foi possível localizar a exclusão de mídia em MediaService.php.');
        $replacement = "        // v0.32.0: remove derivados físicos antes do registro principal.\n        try { ImageOptimizationService::deleteVariants(\$pdo, \$id, true); } catch (Throwable \$ignored) {}\n\n" . $needle;
        $src = str_replace($needle, $replacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao integrar exclusão das variantes.');
    }

    if ($src !== $original) {
        writePatched32($file, $src, $backupDir);
        out32('[OK] MediaService integrado à otimização de imagens.');
    } else {
        out32('[OK] MediaService já estava atualizado.');
    }
}

function patchFunctions32(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.32.0: prefere a maior variante otimizada')) {
        out32('[OK] Helper mediaUrl já estava atualizado.');
        return;
    }

    $pattern = '/function mediaUrl\(\?string \$path\): string\s*\{.*?^\}/ms';
    if (!preg_match($pattern, $src)) {
        throw new RuntimeException('Não foi possível localizar mediaUrl() em functions.php.');
    }
    $replacement = <<<'PHPFUNC'
function mediaUrl(?string $path): string
{
    if (!$path) {
        return '';
    }

    // v0.32.0: prefere a maior variante otimizada local, com fallback seguro.
    if (class_exists('ImageOptimizationService')) {
        return ImageOptimizationService::publicUrlForPath($path);
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return url(ltrim($path, '/'));
}
PHPFUNC;
    $patched = preg_replace($pattern, $replacement, $src, 1, $count);
    if (!is_string($patched) || $count !== 1) {
        throw new RuntimeException('Falha ao atualizar mediaUrl().');
    }
    writePatched32($file, $patched, $backupDir);
    out32('[OK] mediaUrl() passou a usar variantes otimizadas.');
}

function patchHomeService32(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.32.0: usa uma variante adequada na Home')) {
        out32('[OK] HomeService já estava atualizado.');
        return;
    }

    $needle = "    private function mediaUrl(int \$id): string\n    {\n";
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Não foi possível localizar mediaUrl() em HomeService.php.');
    }
    $insert = $needle
        . "        // v0.32.0: usa uma variante adequada na Home quando disponível.\n"
        . "        if (class_exists('ImageOptimizationService')) {\n"
        . "            try {\n"
        . "                \$optimized = ImageOptimizationService::bestUrlForMedia(\$this->pdo, \$id, 1600);\n"
        . "                if (\$optimized !== '') return \$optimized;\n"
        . "            } catch (Throwable \$ignored) {}\n"
        . "        }\n\n";
    $patched = str_replace($needle, $insert, $src, $count);
    if ($count !== 1) throw new RuntimeException('Falha ao integrar variantes ao HomeService.');
    writePatched32($file, $patched, $backupDir);
    out32('[OK] Home modular integrada às imagens otimizadas.');
}

function patchHeader32(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'midias/otimizacao.php')) {
        out32('[OK] Menu de otimização já existe.');
        return;
    }

    $needle = "                            <a class=\"<?= \$isPath('midias/index.php') ? 'active' : '' ?>\" href=\"<?= e(url('admin/midias/index.php')) ?>\">Biblioteca</a>";
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Não foi possível localizar Biblioteca no menu administrativo.');
    }
    $new = $needle . "\n                            <a class=\"<?= \$isPath('midias/otimizacao.php') ? 'active' : '' ?>\" href=\"<?= e(url('admin/midias/otimizacao.php')) ?>\">Otimização de imagens</a>";
    $patched = str_replace($needle, $new, $src, $count);
    if ($count !== 1) throw new RuntimeException('Falha ao adicionar Otimização de imagens ao menu.');
    writePatched32($file, $patched, $backupDir);
    out32('[OK] Menu Mídia atualizado.');
}

function updateVersion32(string $config, string $backupDir): void
{
    $src = (string)file_get_contents($config);
    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
    if (!preg_match($pattern, $src)) {
        throw new RuntimeException('A constante APP_VERSION não foi encontrada em config/config.php.');
    }
    $patched = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $src, 1);
    if (!is_string($patched)) throw new RuntimeException('Falha ao preparar APP_VERSION.');
    if ($patched !== $src) {
        $backup = backup32($config, $backupDir);
        if ($backup) out32('[OK] Backup do config.php: ' . str_replace('\\', '/', $backup));
        if (file_put_contents($config, $patched, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
        }
    }
    out32('[OK] APP_VERSION = ' . TARGET_VERSION);
}

function lint32(string $file): void
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    exec($cmd . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('Erro de sintaxe em ' . basename($file) . ': ' . implode(' ', $lines));
    }
    out32('[OK] php -l: ' . str_replace('\\', '/', $file));
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado pelo terminal:\nphp atualizar_v0.32.0.php\n");
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$mediaService = $root . '/app/Services/MediaService.php';
$imageService = $root . '/app/Services/ImageOptimizationService.php';
$functions = $root . '/app/Helpers/functions.php';
$homeService = $root . '/app/Services/HomeService.php';
$header = $root . '/admin/_header.php';
$page = $root . '/admin/midias/otimizacao.php';
$cli = $root . '/otimizar_imagens_v0.32.0.php';
$configBackupDir = $root . '/storage/config-backups';
$updateBackupDir = $root . '/storage/update-backups/v0.32.0';

out32('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out32(str_repeat('-', 76));

foreach ([$config,$dbFile,$mediaService,$imageService,$functions,$homeService,$header,$page,$cli] as $required) {
    if (!is_file($required)) fail32('Arquivo necessário não encontrado: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $required));
}

require_once $config;
require_once $dbFile;
$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out32('Versão identificada: ' . $currentVersion);
if (version_compare($currentVersion, MINIMUM_VERSION, '<')) {
    fail32('A v0.32.0 requer o Portal v' . MINIMUM_VERSION . ' ou superior. Atualize primeiro para a v0.31.0.');
}

try {
    ensureDir32($configBackupDir);
    ensureDir32($updateBackupDir);
    $pdo = Database::connection();
    out32('[OK] Conexão com o banco realizada.');
    ensureDatabase32($pdo);

    patchMediaService32($mediaService, $updateBackupDir);
    patchFunctions32($functions, $updateBackupDir);
    patchHomeService32($homeService, $updateBackupDir);
    patchHeader32($header, $updateBackupDir);

    foreach ([$imageService,$mediaService,$functions,$homeService,$header,$page,$cli] as $file) lint32($file);

    if (class_exists('CacheService')) {
        try { CacheService::clearAll(); out32('[OK] Cache público limpo.'); } catch (Throwable $ignored) {}
    }

    updateVersion32($config, $configBackupDir);
    if (function_exists('opcache_reset')) @opcache_reset();

    out32(str_repeat('-', 76));
    out32('Atualização v' . TARGET_VERSION . ' concluída.');
    out32('Abra Mídia > Otimização de imagens para processar a biblioteca existente.');
    out32('Para grandes bibliotecas: php otimizar_imagens_v0.32.0.php');
} catch (Throwable $e) {
    fail32($e->getMessage());
}
