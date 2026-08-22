<?php

declare(strict_types=1);

const TARGET_VERSION = '0.31.0';
const MINIMUM_VERSION = '0.30.0';

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
    $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    if (!is_file($htaccess) && file_put_contents($htaccess, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível proteger a pasta: ' . $path);
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

function ensureDatabase(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES " .
        "('Gerenciar performance','performance.gerenciar','Configurações','Configurar cache do Portal e limpar arquivos de cache.',92) " .
        "ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)"
    );

    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id) " .
        "SELECT p.id, pe.id FROM perfis p JOIN permissoes pe ON pe.slug='performance.gerenciar' WHERE p.slug='administrador'"
    );

    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo) '
        . 'ON DUPLICATE KEY UPDATE chave=VALUES(chave)'
    );
    $defaults = [
        ['performance_cache_enabled', '1', 'booleano'],
        ['performance_page_cache_enabled', '1', 'booleano'],
        ['performance_cache_ttl_seconds', '300', 'numero'],
        ['performance_page_cache_ttl_seconds', '120', 'numero'],
    ];
    foreach ($defaults as [$key, $value, $type]) {
        $stmt->execute(['chave' => $key, 'valor' => $value, 'tipo' => $type]);
    }

    out('[OK] Permissão e configurações de Performance criadas/verificadas.');
}

function patchBootstrap(string $file, string $backupDir): void
{
    if (!is_file($file)) {
        throw new RuntimeException('bootstrap.php não encontrado.');
    }
    $source = (string)file_get_contents($file);
    $original = $source;

    if (!str_contains($source, "app/Services/CacheService.php")) {
        $needle = "require_once __DIR__ . '/app/Helpers/functions.php';\n";
        if (!str_contains($source, $needle)) {
            throw new RuntimeException('Não foi possível localizar o carregamento de functions.php no bootstrap.php.');
        }
        $source = str_replace(
            $needle,
            $needle . "require_once __DIR__ . '/app/Services/CacheService.php';\n",
            $source
        );
    }

    if (!str_contains($source, 'CacheService::configure($bootstrapPdo);')) {
        $needle = "    \$bootstrapPdo = Database::connection();\n";
        if (!str_contains($source, $needle)) {
            throw new RuntimeException('Não foi possível localizar Database::connection() no bootstrap.php.');
        }
        $source = str_replace(
            $needle,
            $needle . "    CacheService::configure(\$bootstrapPdo);\n",
            $source
        );
    }

    if (!str_contains($source, 'CacheService::bootstrapPublicPageCache($bootstrapPdo);')) {
        $needle = "    enforceMaintenanceMode(\$bootstrapPdo);\n";
        if (!str_contains($source, $needle)) {
            throw new RuntimeException('Não foi possível localizar enforceMaintenanceMode() no bootstrap.php.');
        }
        $source = str_replace(
            $needle,
            $needle . "\n    // v0.31.0: cache seguro apenas para a Home pública e visitantes anônimos.\n    CacheService::bootstrapPublicPageCache(\$bootstrapPdo);\n",
            $source
        );
    }

    if ($source === $original) {
        out('[OK] bootstrap.php já possui integração de cache.');
        return;
    }

    $backup = backupFile($file, $backupDir, 'bootstrap');
    if (file_put_contents($file, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar bootstrap.php.');
    }
    out('[OK] bootstrap.php integrado ao CacheService.' . ($backup ? ' Backup: ' . $backup : ''));
}

function patchFunctions(string $file, string $backupDir): void
{
    if (!is_file($file)) {
        throw new RuntimeException('app/Helpers/functions.php não encontrado.');
    }
    $source = (string)file_get_contents($file);
    $original = $source;

    if (!str_contains($source, 'CacheService::invalidateForAction($acao, $entidade);')) {
        $signature = 'function logAction(PDO $pdo, string $acao, ?string $entidade = null, ?int $entidadeId = null, ?string $detalhes = null, string $nivel = \'info\'): void';
        $pos = strpos($source, $signature);
        if ($pos === false) {
            throw new RuntimeException('Não foi possível localizar logAction() em functions.php.');
        }
        $brace = strpos($source, '{', $pos + strlen($signature));
        if ($brace === false) {
            throw new RuntimeException('Estrutura de logAction() inválida.');
        }
        $block = "\n    // v0.31.0: alterações de conteúdo invalidam fragmentos/páginas públicas.\n"
            . "    if (class_exists('CacheService', false)) {\n"
            . "        CacheService::invalidateForAction(\$acao, \$entidade);\n"
            . "    }\n";
        $source = substr($source, 0, $brace + 1) . $block . substr($source, $brace + 1);
    }

    if (!str_contains($source, "CacheService::get('config.all', null)")) {
        $functionPos = strpos($source, 'function siteConfigAll(PDO $pdo, bool $refresh = false): array');
        if ($functionPos === false) {
            throw new RuntimeException('Não foi possível localizar siteConfigAll() em functions.php.');
        }
        $memoryBlock = "    if (!\$refresh && isset(\$GLOBALS['__portal_config_cache'][\$key])) {\n"
            . "        return \$GLOBALS['__portal_config_cache'][\$key];\n"
            . "    }\n";
        $memoryPos = strpos($source, $memoryBlock, $functionPos);
        if ($memoryPos === false) {
            throw new RuntimeException('Não foi possível localizar o cache em memória de siteConfigAll().');
        }
        $insertPos = $memoryPos + strlen($memoryBlock);
        $block = "    // v0.31.0: cache persistente entre requisições.\n"
            . "    if (!\$refresh && class_exists('CacheService', false) && CacheService::enabled()) {\n"
            . "        \$cached = CacheService::get('config.all', null);\n"
            . "        if (is_array(\$cached)) {\n"
            . "            \$GLOBALS['__portal_config_cache'][\$key] = \$cached;\n"
            . "            return \$cached;\n"
            . "        }\n"
            . "    }\n";
        $source = substr($source, 0, $insertPos) . $block . substr($source, $insertPos);
    }

    if (!str_contains($source, "CacheService::put('config.all', \$items")) {
        $functionPos = strpos($source, 'function siteConfigAll(PDO $pdo, bool $refresh = false): array');
        $assign = "        \$GLOBALS['__portal_config_cache'][\$key] = \$items;\n        return \$items;";
        $assignPos = strpos($source, $assign, $functionPos);
        if ($assignPos === false) {
            throw new RuntimeException('Não foi possível localizar o retorno de siteConfigAll().');
        }
        $replacement = "        \$GLOBALS['__portal_config_cache'][\$key] = \$items;\n"
            . "        if (class_exists('CacheService', false) && CacheService::enabled()) {\n"
            . "            CacheService::put('config.all', \$items, CacheService::defaultTtl(), 'config');\n"
            . "        }\n"
            . "        return \$items;";
        $source = substr_replace($source, $replacement, $assignPos, strlen($assign));
    }

    if (!str_contains($source, "CacheService::forget('config.all');")) {
        $functionPos = strpos($source, 'function saveSiteConfig(PDO $pdo, string $key, ?string $value, string $type = \'texto\'): void');
        if ($functionPos === false) {
            throw new RuntimeException('Não foi possível localizar saveSiteConfig() em functions.php.');
        }
        $needle = "    unset(\$GLOBALS['__portal_config_cache'][spl_object_id(\$pdo)]);";
        $pos = strpos($source, $needle, $functionPos);
        if ($pos === false) {
            throw new RuntimeException('Não foi possível localizar a invalidação em memória de saveSiteConfig().');
        }
        $replacement = $needle . "\n"
            . "    if (class_exists('CacheService', false)) {\n"
            . "        CacheService::forget('config.all');\n"
            . "        CacheService::clearGroup('page');\n"
            . "        CacheService::clearGroup('public');\n"
            . "    }";
        $source = substr_replace($source, $replacement, $pos, strlen($needle));
    }

    if ($source === $original) {
        out('[OK] functions.php já possui integração de cache.');
        return;
    }

    $backup = backupFile($file, $backupDir, 'functions');
    if (file_put_contents($file, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar functions.php.');
    }
    out('[OK] Configurações e alterações administrativas integradas ao cache.' . ($backup ? ' Backup: ' . $backup : ''));
}

function patchAdminHeader(string $file, string $backupDir): void
{
    if (!is_file($file)) {
        throw new RuntimeException('admin/_header.php não encontrado.');
    }
    $source = (string)file_get_contents($file);
    $original = $source;

    $oldCondition = "Auth::can('configuracoes.gerenciar') || Auth::can('seguranca.gerenciar') || Auth::can('email.gerenciar')";
    if (!str_contains($source, "Auth::can('performance.gerenciar')")) {
        if (!str_contains($source, $oldCondition)) {
            throw new RuntimeException('Não foi possível localizar a condição do menu Configurações em admin/_header.php.');
        }
        $source = str_replace($oldCondition, $oldCondition . " || Auth::can('performance.gerenciar')", $source);
    }

    if (!str_contains($source, "configuracoes/performance.php")) {
        $needle = "                        <?php if (Auth::can('email.gerenciar')): ?>";
        $pos = strpos($source, $needle);
        if ($pos === false) {
            throw new RuntimeException('Não foi possível localizar o item E-mail no menu Configurações.');
        }
        $block = "                        <?php if (Auth::can('performance.gerenciar')): ?>\n"
            . "                            <a class=\"<?= \$isPath('configuracoes/performance.php') ? 'active' : '' ?>\" href=\"<?= e(url('admin/configuracoes/performance.php')) ?>\">Performance</a>\n"
            . "                        <?php endif; ?>\n";
        $source = substr($source, 0, $pos) . $block . substr($source, $pos);
    }

    if ($source === $original) {
        out('[OK] Configurações > Performance já está no menu.');
        return;
    }

    $backup = backupFile($file, $backupDir, 'admin-header');
    if (file_put_contents($file, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar admin/_header.php.');
    }
    out('[OK] Configurações > Performance adicionado ao menu.' . ($backup ? ' Backup: ' . $backup : ''));
}

function patchRootHtaccess(string $file, string $backupDir): void
{
    $source = is_file($file) ? (string)file_get_contents($file) : '';
    if (str_contains($source, 'BEGIN IECLB V0.31 PERFORMANCE')) {
        out('[OK] Otimizações HTTP da v0.31.0 já estão instaladas.');
        return;
    }

    $backup = is_file($file) ? backupFile($file, $backupDir, 'root-htaccess') : null;
    $block = <<<'HTACCESS'

# BEGIN IECLB V0.31 PERFORMANCE
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json application/xml text/xml image/svg+xml
</IfModule>

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 day"
    ExpiresByType application/javascript "access plus 1 day"
    ExpiresByType text/javascript "access plus 1 day"
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType image/svg+xml "access plus 7 days"
    ExpiresByType font/woff2 "access plus 30 days"
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch "\.(?:css|js)$">
        Header append Vary Accept-Encoding
    </FilesMatch>
</IfModule>
# END IECLB V0.31 PERFORMANCE
HTACCESS;

    $newSource = rtrim($source) . PHP_EOL . $block . PHP_EOL;
    if (file_put_contents($file, $newSource, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar o .htaccess principal.');
    }
    out('[OK] Compressão e cache de arquivos estáticos configurados no Apache.' . ($backup ? ' Backup: ' . $backup : ''));
}

function lintPhp(string $file): void
{
    if (!is_file($file)) {
        throw new RuntimeException('Arquivo PHP não encontrado para validação: ' . $file);
    }
    if (!function_exists('proc_open')) {
        out('[AVISO] proc_open desativado; validação php -l interna ignorada para ' . basename($file) . '.');
        return;
    }

    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        out('[AVISO] Não foi possível executar php -l em ' . basename($file) . '.');
        return;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Falha de sintaxe em ' . basename($file) . ': ' . trim((string)$stdout . ' ' . (string)$stderr));
    }
    out('[OK] php -l: ' . basename($file));
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
        throw new RuntimeException('A constante APP_VERSION não foi encontrada em config/config.php.');
    }

    if (file_put_contents($config, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
    }
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado somente pelo terminal:\nphp atualizar_v0.31.0.php\n");
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$bootstrap = $root . '/bootstrap.php';
$functions = $root . '/app/Helpers/functions.php';
$cacheService = $root . '/app/Services/CacheService.php';
$performancePage = $root . '/admin/configuracoes/performance.php';
$header = $root . '/admin/_header.php';
$cacheDir = $root . '/storage/cache';
$configBackupDir = $root . '/storage/config-backups';
$updateBackupDir = $root . '/storage/update-backups/v0.31.0';

out('Portal IECLB Parobé - atualização para v' . TARGET_VERSION);
out(str_repeat('-', 76));

foreach ([$config, $dbFile, $bootstrap, $functions, $cacheService, $performancePage, $header] as $required) {
    if (!is_file($required)) {
        fail('Arquivo necessário não encontrado: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $required));
    }
}

require_once $config;
require_once $dbFile;
$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $currentVersion);
if (version_compare($currentVersion, MINIMUM_VERSION, '<')) {
    fail('A v0.31.0 requer o Portal v' . MINIMUM_VERSION . ' ou superior. Atualize primeiro para a v0.30.0.');
}

try {
    protectDirectory($cacheDir);
    protectDirectory($configBackupDir);
    protectDirectory($updateBackupDir);

    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    ensureDatabase($pdo);
    patchBootstrap($bootstrap, $updateBackupDir);
    patchFunctions($functions, $updateBackupDir);
    patchAdminHeader($header, $updateBackupDir);
    patchRootHtaccess($root . '/.htaccess', $updateBackupDir);

    lintPhp($cacheService);
    lintPhp($performancePage);
    lintPhp($bootstrap);
    lintPhp($functions);
    lintPhp($header);

    require_once $cacheService;
    CacheService::clearAll();
    out('[OK] Cache antigo limpo.');

    updateVersion($config, $configBackupDir);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Atualização v' . TARGET_VERSION . ' concluída com sucesso.');
    out('Acesse: Configurações > Performance.');
    out('A primeira visita à Home gera o cache; as seguintes podem retornar X-Portal-Cache: HIT.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
