<?php

declare(strict_types=1);

const TARGET_VERSION_33 = '0.33.0';
const MINIMUM_VERSION_33 = '0.32.0';
const ITEMS_PER_PAGE_33 = 50;

function out33(string $message = ''): void { echo $message . PHP_EOL; }
function fail33(string $message): never { out33('[ERRO] ' . $message); exit(1); }

function ensureDir33(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar: ' . $dir);
    }
}

function backup33(string $file, string $backupDir): ?string
{
    if (!is_file($file)) return null;
    ensureDir33($backupDir);
    $hash = substr((string)hash_file('sha256', $file), 0, 8);
    $prefix = basename(dirname($file));
    $target = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR
        . $prefix . '-' . basename($file) . '.bak-' . date('Ymd-His') . '-' . $hash;
    if (!copy($file, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $file);
    }
    return $target;
}

function writePatched33(string $file, string $content, string $backupDir): void
{
    $current = (string)file_get_contents($file);
    if ($current === $content) return;
    $backup = backup33($file, $backupDir);
    if ($backup) out33('[OK] Backup: ' . str_replace('\\', '/', $backup));
    if (file_put_contents($file, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $file);
    }
}

function ensurePaginationRequire33(string $src): string
{
    if (str_contains($src, "../_pagination.php")) return $src;
    $needle = "require_once __DIR__ . '/../../bootstrap.php';";
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Não foi possível localizar bootstrap.php no arquivo administrativo.');
    }
    return str_replace(
        $needle,
        $needle . "\nrequire_once __DIR__ . '/../_pagination.php';",
        $src,
        $count
    );
}

function addPaginationBeforeFooter33(string $src, string $html): string
{
    if (str_contains($src, 'v0.33.0-pagination-render')) return $src;
    $needle = "<?php require __DIR__ . '/../_footer.php'; ?>";
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Rodapé administrativo não encontrado para inserir a paginação.');
    }
    $render = "<?php /* v0.33.0-pagination-render */ ?>\n" . $html . "\n" . $needle;
    return str_replace($needle, $render, $src, $count);
}

function patchPosts33(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.0: paginação de posts')) {
        out33('[OK] Posts/Notícias já possuem paginação v0.33.0.');
        return;
    }
    $src = ensurePaginationRequire33($src);

    if (str_contains($src, '$activeCount') && str_contains($src, '$trashCount') && str_contains($src, '$view')) {
        $whereNeedle = <<<'PHP'
$where = $view === 'lixeira' ? "p.status = 'lixeira'" : "p.status <> 'lixeira'";
PHP;
        if (!str_contains($src, $whereNeedle)) {
            throw new RuntimeException('Não foi possível localizar o filtro da listagem de notícias.');
        }
        $whereReplacement = $whereNeedle . "\n// v0.33.0: paginação de posts — 50 registros por página.\n"
            . "\$totalItems = \$view === 'lixeira' ? \$trashCount : \$activeCount;\n"
            . "\$pagination = adminPaginationState(\$totalItems, " . ITEMS_PER_PAGE_33 . ");";
        $src = str_replace($whereNeedle, $whereReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao preparar a paginação de notícias.');

        $orderNeedle = "ORDER BY \" . (\$view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.id DESC')";
        if (!str_contains($src, $orderNeedle)) {
            throw new RuntimeException('Não foi possível localizar a ordenação de notícias.');
        }
        $orderReplacement = $orderNeedle
            . " . \" LIMIT \" . (int)\$pagination['limit'] . \" OFFSET \" . (int)\$pagination['offset']";
        $src = str_replace($orderNeedle, $orderReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao limitar a consulta de notícias.');

        $render = "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null]) ?>";
        $src = addPaginationBeforeFooter33($src, $render);
    } else {
        $queryNeedle = <<<'PHP'
$posts = $pdo->query("SELECT p.*, c.nome AS comunidade_nome, (SELECT GROUP_CONCAT(cat.nome ORDER BY pc.principal DESC, cat.nome SEPARATOR '||') FROM post_categorias pc INNER JOIN categorias cat ON cat.id=pc.categoria_id WHERE pc.post_id=p.id) AS categorias_nomes FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id ORDER BY p.id DESC")->fetchAll();
PHP;
        if (!str_contains($src, $queryNeedle)) {
            throw new RuntimeException('Formato da listagem de notícias não reconhecido pela v0.33.0.');
        }
        $queryReplacement = <<<'PHP'
// v0.33.0: paginação de posts — 50 registros por página.
$totalItems = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$posts = $pdo->query("SELECT p.*, c.nome AS comunidade_nome, (SELECT GROUP_CONCAT(cat.nome ORDER BY pc.principal DESC, cat.nome SEPARATOR '||') FROM post_categorias pc INNER JOIN categorias cat ON cat.id=pc.categoria_id WHERE pc.post_id=p.id) AS categorias_nomes FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id ORDER BY p.id DESC LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'])->fetchAll();
PHP;
        $src = str_replace($queryNeedle, $queryReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao paginar a consulta de notícias.');
        $src = addPaginationBeforeFooter33($src, "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination) ?>");
    }

    writePatched33($file, $src, $backupDir);
    out33('[OK] Posts/Notícias: paginação de 50 itens ativada.');
}

function patchCategories33(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.0: paginação de categorias')) {
        out33('[OK] Categorias já possuem paginação v0.33.0.');
        return;
    }
    $src = ensurePaginationRequire33($src);

    $needle = '$categorias = CategoryService::tree($pdo);';
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Não foi possível localizar a árvore de categorias.');
    }
    $replacement = $needle . "\n// v0.33.0: paginação de categorias — preserva a árvore completa no seletor de ascendente.\n"
        . "\$pagination = adminPaginationState(count(\$categorias), " . ITEMS_PER_PAGE_33 . ");\n"
        . "\$categoriasPagina = array_slice(\$categorias, \$pagination['offset'], \$pagination['limit']);";
    $src = str_replace($needle, $replacement, $src, $count);
    if ($count !== 1) throw new RuntimeException('Falha ao preparar a paginação de categorias.');

    $tableBlock = <<<'PHP'
                    <?php if (!$categorias): ?>
                        <tr><td colspan="4" class="text-secondary">Nenhuma categoria cadastrada.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($categorias as $categoria): ?>
PHP;
    $tableReplacement = <<<'PHP'
                    <?php if (!$categoriasPagina): ?>
                        <tr><td colspan="4" class="text-secondary">Nenhuma categoria cadastrada.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($categoriasPagina as $categoria): ?>
PHP;
    if (!str_contains($src, $tableBlock)) {
        throw new RuntimeException('Não foi possível localizar a tabela de categorias.');
    }
    $src = str_replace($tableBlock, $tableReplacement, $src, $count);
    if ($count !== 1) throw new RuntimeException('Falha ao limitar a tabela de categorias.');

    $src = addPaginationBeforeFooter33($src, "<?= adminPaginationHtml('admin/categorias/index.php', \$pagination) ?>");
    writePatched33($file, $src, $backupDir);
    out33('[OK] Categorias: paginação de 50 itens ativada.');
}

function patchTags33(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.0: paginação de tags')) {
        out33('[OK] Tags já possuem paginação v0.33.0.');
        return;
    }
    $src = ensurePaginationRequire33($src);

    $needle = <<<'PHP'
$tags=$pdo->query("SELECT t.id,t.nome,t.slug,t.descricao,COUNT(p.id) total_posts FROM tags t LEFT JOIN post_tags pt ON pt.tag_id=t.id LEFT JOIN posts p ON p.id=pt.post_id AND p.status <> 'lixeira' GROUP BY t.id,t.nome,t.slug,t.descricao ORDER BY t.nome")->fetchAll();
PHP;
    if (!str_contains($src, $needle)) {
        throw new RuntimeException('Formato da listagem de tags não reconhecido pela v0.33.0.');
    }
    $replacement = <<<'PHP'
// v0.33.0: paginação de tags — 50 registros por página.
$totalItems = (int)$pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$tags=$pdo->query("SELECT t.id,t.nome,t.slug,t.descricao,COUNT(p.id) total_posts FROM tags t LEFT JOIN post_tags pt ON pt.tag_id=t.id LEFT JOIN posts p ON p.id=pt.post_id AND p.status <> 'lixeira' GROUP BY t.id,t.nome,t.slug,t.descricao ORDER BY t.nome LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'])->fetchAll();
PHP;
    $src = str_replace($needle, $replacement, $src, $count);
    if ($count !== 1) throw new RuntimeException('Falha ao paginar a consulta de tags.');

    $src = addPaginationBeforeFooter33($src, "<?= adminPaginationHtml('admin/tags/index.php', \$pagination) ?>");
    writePatched33($file, $src, $backupDir);
    out33('[OK] Tags: paginação de 50 itens ativada.');
}

function patchPages33(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.0: paginação de páginas')) {
        out33('[OK] Páginas já possuem paginação v0.33.0.');
        return;
    }
    $src = ensurePaginationRequire33($src);

    if (str_contains($src, '$activeCount') && str_contains($src, '$trashCount') && str_contains($src, '$view')) {
        $whereNeedle = <<<'PHP'
$where = $view === 'lixeira' ? "p.status = 'lixeira'" : "p.status <> 'lixeira'";
PHP;
        if (!str_contains($src, $whereNeedle)) {
            throw new RuntimeException('Não foi possível localizar o filtro da listagem de páginas.');
        }
        $whereReplacement = $whereNeedle . "\n// v0.33.0: paginação de páginas — 50 registros por página.\n"
            . "\$totalItems = \$view === 'lixeira' ? \$trashCount : \$activeCount;\n"
            . "\$pagination = adminPaginationState(\$totalItems, " . ITEMS_PER_PAGE_33 . ");";
        $src = str_replace($whereNeedle, $whereReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao preparar a paginação de páginas.');

        $orderNeedle = "ORDER BY \" . (\$view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.ordem ASC, p.id DESC')";
        if (!str_contains($src, $orderNeedle)) {
            throw new RuntimeException('Não foi possível localizar a ordenação de páginas.');
        }
        $orderReplacement = $orderNeedle
            . " . \" LIMIT \" . (int)\$pagination['limit'] . \" OFFSET \" . (int)\$pagination['offset']";
        $src = str_replace($orderNeedle, $orderReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao limitar a consulta de páginas.');

        $src = addPaginationBeforeFooter33(
            $src,
            "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null]) ?>"
        );
    } else {
        $queryNeedle = <<<'PHP'
$paginas = $pdo->query(
    "SELECT p.*, u.nome AS autor_nome
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     ORDER BY p.ordem ASC, p.id DESC"
)->fetchAll();
PHP;
        if (!str_contains($src, $queryNeedle)) {
            throw new RuntimeException('Formato da listagem de páginas não reconhecido pela v0.33.0.');
        }
        $queryReplacement = <<<'PHP'
// v0.33.0: paginação de páginas — 50 registros por página.
$totalItems = (int)$pdo->query('SELECT COUNT(*) FROM paginas')->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$paginas = $pdo->query(
    "SELECT p.*, u.nome AS autor_nome
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     ORDER BY p.ordem ASC, p.id DESC LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset']
)->fetchAll();
PHP;
        $src = str_replace($queryNeedle, $queryReplacement, $src, $count);
        if ($count !== 1) throw new RuntimeException('Falha ao paginar a consulta de páginas.');
        $src = addPaginationBeforeFooter33($src, "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination) ?>");
    }

    writePatched33($file, $src, $backupDir);
    out33('[OK] Páginas: paginação de 50 itens ativada.');
}

function updateVersion33(string $config, string $backupDir): void
{
    $src = (string)file_get_contents($config);
    $patched = $src;
    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";

    if (preg_match($pattern, $patched)) {
        $patched = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION_33 . "');", $patched, 1) ?? $patched;
    } else {
        $define = "define('APP_VERSION', '" . TARGET_VERSION_33 . "');";
        $declarePattern = '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/';
        if (preg_match($declarePattern, $patched)) {
            $patched = preg_replace($declarePattern, '$0' . "\n\n" . $define, $patched, 1) ?? $patched;
        } elseif (str_starts_with(ltrim($patched), '<?php')) {
            $patched = preg_replace('/^\s*<\?php\s*/', "<?php\n\n" . $define . "\n", $patched, 1) ?? $patched;
        } else {
            throw new RuntimeException('config/config.php não possui uma abertura PHP válida.');
        }
    }

    if ($patched !== $src) {
        $backup = backup33($config, $backupDir);
        if ($backup) out33('[OK] Backup do config.php: ' . str_replace('\\', '/', $backup));
        if (file_put_contents($config, $patched, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
        }
    }
    out33('[OK] APP_VERSION = ' . TARGET_VERSION_33);
}

function lint33(string $file): void
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    $lines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('Erro de sintaxe em ' . $file . ': ' . implode(' ', $lines));
    }
    out33('[OK] php -l: ' . str_replace('\\', '/', $file));
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado pelo terminal:\nphp atualizar_v0.33.0.php\n");
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$paginationFile = $root . '/admin/_pagination.php';
$postsFile = $root . '/admin/noticias/index.php';
$categoriesFile = $root . '/admin/categorias/index.php';
$tagsFile = $root . '/admin/tags/index.php';
$pagesFile = $root . '/admin/paginas/index.php';
$configBackupDir = $root . '/storage/config-backups';
$updateBackupDir = $root . '/storage/update-backups/v0.33.0';

out33('Portal IECLB Parobé - atualização para v' . TARGET_VERSION_33);
out33(str_repeat('-', 76));

foreach ([$config, $dbFile, $paginationFile, $postsFile, $categoriesFile, $tagsFile, $pagesFile] as $required) {
    if (!is_file($required)) {
        fail33('Arquivo necessário não encontrado: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $required));
    }
}

require_once $config;
require_once $dbFile;
$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : '';
if ($currentVersion !== '') {
    out33('Versão identificada: ' . $currentVersion);
    if (version_compare($currentVersion, MINIMUM_VERSION_33, '<')) {
        fail33('A v0.33.0 requer o Portal v' . MINIMUM_VERSION_33 . ' ou superior.');
    }
    if (version_compare($currentVersion, TARGET_VERSION_33, '>')) {
        fail33('O Portal já está em uma versão superior (' . $currentVersion . '). Este pacote não será aplicado.');
    }
} else {
    out33('[AVISO] APP_VERSION não está definida; a constante será inserida automaticamente após declare(strict_types=1).');
}

try {
    ensureDir33($configBackupDir);
    ensureDir33($updateBackupDir);

    // A conexão confirma que a instalação está funcional, embora a v0.33.0 não altere tabelas.
    Database::connection();
    out33('[OK] Conexão com o banco realizada. Nenhuma migração SQL é necessária.');

    patchPosts33($postsFile, $updateBackupDir);
    patchCategories33($categoriesFile, $updateBackupDir);
    patchTags33($tagsFile, $updateBackupDir);
    patchPages33($pagesFile, $updateBackupDir);

    foreach ([$paginationFile, $postsFile, $categoriesFile, $tagsFile, $pagesFile] as $file) {
        lint33($file);
    }

    updateVersion33($config, $configBackupDir);

    if (class_exists('CacheService')) {
        try {
            CacheService::clearAll();
            out33('[OK] Cache do Portal limpo.');
        } catch (Throwable $ignored) {}
    }
    if (function_exists('opcache_reset')) @opcache_reset();

    out33(str_repeat('-', 76));
    out33('Atualização v' . TARGET_VERSION_33 . ' concluída.');
    out33('Posts, Categorias, Tags e Páginas agora exibem no máximo 50 registros por página.');
} catch (Throwable $e) {
    fail33($e->getMessage());
}
