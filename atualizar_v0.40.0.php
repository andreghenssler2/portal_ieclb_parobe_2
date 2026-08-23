<?php

declare(strict_types=1);

const TARGET_VERSION = '0.40.0';
const MINIMUM_VERSION = '0.39.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

$root = __DIR__;
$backupDir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');

function backupFile(string $path): void
{
    global $root,$backupDir;
    if (!is_file($path)) return;

    $relative = ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
    if (!copy($path,$target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeChanged(string $path,string $content,string $label): void
{
    $old = is_file($path) ? (string)file_get_contents($path) : '';

    if ($old === $content) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    if (is_file($path)) backupFile($path);

    if (file_put_contents($path,$content,LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    out('[OK] ' . $label . ' atualizado.');
}

function lintPhp(string $file): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command,$lines,$code);

    if ($code !== 0) {
        throw new RuntimeException(
            basename($file) . " não passou no php -l:\n" . implode(PHP_EOL,$lines)
        );
    }
}

function tableExists(PDO $pdo,string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo,string $table,string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND column_name=?'
    );
    $stmt->execute([$table,$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureSchema(PDO $pdo): void
{
    if (!tableExists($pdo,'posts')) {
        throw new RuntimeException('Tabela posts não encontrada.');
    }

    if (!columnExists($pdo,'posts','visualizacoes')) {
        $pdo->exec(
            'ALTER TABLE posts
             ADD COLUMN visualizacoes BIGINT UNSIGNED NOT NULL DEFAULT 0'
        );
        out('[OK] Coluna posts.visualizacoes criada.');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_visualizacoes_diarias (
            post_id BIGINT UNSIGNED NOT NULL,
            data DATE NOT NULL,
            visualizacoes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (post_id,data),
            KEY idx_post_views_data (data),
            KEY idx_post_views_ranking (data,visualizacoes,post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    out('[OK] Estrutura de visualizações verificada.');
}

function patchBootstrap(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source,'NewsAnalyticsService.php')) {
        out('[OK] bootstrap.php já carrega NewsAnalyticsService.');
        return;
    }

    $anchors = [
        "require_once __DIR__ . '/app/Services/EventCalendarService.php';",
        "require_once __DIR__ . '/app/Services/SearchService.php';",
        "require_once __DIR__ . '/app/Services/HomeService.php';",
    ];

    foreach ($anchors as $anchor) {
        $position = strpos($source,$anchor);
        if ($position === false) continue;

        $insert = $anchor . "\nrequire_once __DIR__ . '/app/Services/NewsAnalyticsService.php';";
        $source = substr_replace($source,$insert,$position,strlen($anchor));

        writeChanged($path,$source,'bootstrap.php');
        lintPhp($path);
        return;
    }

    throw new RuntimeException('Não foi possível integrar NewsAnalyticsService no bootstrap.php.');
}

function patchNoticia(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (str_contains($source,'NewsAnalyticsService::trackView')) {
        out('[OK] noticia.php já usa NewsAnalyticsService.');
        return;
    }

    $old = <<<'PHP'
$pdo->prepare('UPDATE posts SET visualizacoes=visualizacoes+1 WHERE id=:id')->execute(['id'=>$post['id']]);
PHP;

    if (str_contains($source,$old)) {
        $source = str_replace(
            $old,
            "NewsAnalyticsService::trackView(\$pdo, (int)\$post['id']);",
            $source
        );
    } else {
        $pattern = '~\$pdo->prepare\(\s*[\'"]UPDATE posts SET visualizacoes\s*=\s*visualizacoes\s*\+\s*1 WHERE id=:id[\'"]\s*\)->execute\(\s*\[[\'"]id[\'"]\s*=>\s*\$post\[[\'"]id[\'"]\]\]\s*\);~';

        $source = preg_replace(
            $pattern,
            "NewsAnalyticsService::trackView(\$pdo, (int)\$post['id']);",
            $source,
            1,
            $count
        ) ?? $source;

        if (($count ?? 0) !== 1) {
            throw new RuntimeException('Não foi possível substituir o contador antigo em noticia.php.');
        }
    }

    if ($source === $original) {
        out('[OK] noticia.php já estava atualizado.');
        return;
    }

    writeChanged($path,$source,'noticia.php');
    lintPhp($path);
}

function patchRouter(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source,"'mais-lidas' => 'mais-lidas.php'")) {
        out('[OK] router.php já possui /mais-lidas.');
        return;
    }

    $anchors = [
        "'busca' => 'busca.php',",
        "'agenda' => 'agenda.php',",
    ];

    foreach ($anchors as $anchor) {
        $position = strpos($source,$anchor);
        if ($position === false) continue;

        $insert = $anchor . "\n        'mais-lidas' => 'mais-lidas.php',";
        $source = substr_replace($source,$insert,$position,strlen($anchor));

        writeChanged($path,$source,'router.php');
        lintPhp($path);
        return;
    }

    throw new RuntimeException('Não foi possível localizar as rotas estáticas em router.php.');
}

function patchAdminHeader(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source,'noticias/mais-lidas.php')) {
        out('[OK] Menu de Notícias já possui Mais Lidas.');
        return;
    }

    $anchor = <<<'PHP'
                            <a class="<?= $isPath('noticias/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/noticias/form.php')) ?>">Adicionar Novo</a>
PHP;

    $position = strpos($source,$anchor);
    if ($position === false) {
        throw new RuntimeException('Não foi possível localizar "Adicionar Novo" no menu de Notícias.');
    }

    $extra = <<<'PHP'

                            <a class="<?= $isPath('noticias/mais-lidas.php') ? 'active' : '' ?>" href="<?= e(url('admin/noticias/mais-lidas.php')) ?>">Mais Lidas</a>
PHP;

    $insert = $anchor . $extra;
    $source = substr_replace($source,$insert,$position,strlen($anchor));

    writeChanged($path,$source,'admin/_header.php');
    lintPhp($path);
}

function patchAdminNewsList(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source,'<th>Visualizações</th>')) {
        $old = '<th>Status</th><th>Publicação</th><th></th>';
        $new = '<th>Status</th><th>Publicação</th><th>Visualizações</th><th></th>';

        if (!str_contains($source,$old)) {
            throw new RuntimeException('Não foi possível localizar o cabeçalho da lista de Notícias.');
        }

        $source = str_replace($old,$new,$source);
    }

    $viewCellMarker = "number_format((int)(\$post['visualizacoes'] ?? 0)";
    if (!str_contains($source,$viewCellMarker)) {
        $old = <<<'PHP'
<td><?= e(formatDateBr($post['publicado_em'])) ?></td><td class="text-end">
PHP;
        $new = <<<'PHP'
<td><?= e(formatDateBr($post['publicado_em'])) ?></td><td><?= number_format((int)($post['visualizacoes'] ?? 0), 0, ',', '.') ?></td><td class="text-end">
PHP;

        if (!str_contains($source,$old)) {
            throw new RuntimeException('Não foi possível localizar a coluna Publicação na lista de Notícias.');
        }

        $source = str_replace($old,$new,$source);
    }

    $source = str_replace(
        '<tr><td colspan="6" class="text-secondary">Nenhuma notícia cadastrada.</td></tr>',
        '<tr><td colspan="7" class="text-secondary">Nenhuma notícia cadastrada.</td></tr>',
        $source
    );

    if ($source === $original) {
        out('[OK] Lista de Notícias já mostra Visualizações.');
        return;
    }

    writeChanged($path,$source,'admin/noticias/index.php');
    lintPhp($path);
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $original = $source;

    $pattern = "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern,$source)) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $declare = 'declare(strict_types=1);';
        $position = strpos($source,$declare);

        if ($position !== false) {
            $at = $position + strlen($declare);
            $source = substr($source,0,$at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source,$at);
        } else {
            $php = strpos($source,'<?php');
            if ($php === false) {
                throw new RuntimeException('config/config.php inválido.');
            }

            $at = $php + 5;
            $source = substr($source,0,$at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source,$at);
        }
    }

    if ($source !== $original) {
        writeChanged($config,$source,'config/config.php');
    } else {
        out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
    }
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Mais Lidas / Visualizações de Notícias');
out(str_repeat('-',76));

$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');

foreach ([
    'app/Services/NewsAnalyticsService.php',
    'admin/noticias/mais-lidas.php',
    'mais-lidas.php',
] as $required) {
    if (!is_file($root . '/' . $required)) {
        fail('Arquivo da v0.40.0 não encontrado: ' . $required);
    }
}

require_once $config;
require_once $dbFile;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current,MINIMUM_VERSION,'<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MINIMUM_VERSION . ' ou superior.');
}

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    ensureSchema($pdo);

    lintPhp($root . '/app/Services/NewsAnalyticsService.php');
    lintPhp($root . '/admin/noticias/mais-lidas.php');
    lintPhp($root . '/mais-lidas.php');
    out('[OK] Novos arquivos PHP validados.');

    patchBootstrap($root . '/bootstrap.php');
    patchNoticia($root . '/noticia.php');
    patchRouter($root . '/router.php');
    patchAdminHeader($root . '/admin/_header.php');
    patchAdminNewsList($root . '/admin/noticias/index.php');
    updateVersion($config);

    if (function_exists('opcache_reset')) @opcache_reset();

    out(str_repeat('-',76));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('Painel: Posts / Notícias > Mais Lidas');
    out('Página pública: /mais-lidas');

    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\','/',$backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
