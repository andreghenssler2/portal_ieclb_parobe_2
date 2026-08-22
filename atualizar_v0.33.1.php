<?php

declare(strict_types=1);

const TARGET_VERSION_331 = '0.33.1';
const MINIMUM_VERSION_331 = '0.33.0';
const ITEMS_PER_PAGE_331 = 50;

function out331(string $message = ''): void { echo $message . PHP_EOL; }
function fail331(string $message): never { out331('[ERRO] ' . $message); exit(1); }

function ensureDir331(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar: ' . $dir);
    }
}

function backup331(string $file, string $backupDir): ?string
{
    if (!is_file($file)) return null;
    ensureDir331($backupDir);
    $hash = substr((string)hash_file('sha256', $file), 0, 8);
    $prefix = basename(dirname($file));
    $target = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR
        . $prefix . '-' . basename($file) . '.bak-' . date('Ymd-His') . '-' . $hash;
    if (!copy($file, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $file);
    }
    return $target;
}

function writePatched331(string $file, string $content, string $backupDir): void
{
    $current = (string)file_get_contents($file);
    if ($current === $content) return;
    $backup = backup331($file, $backupDir);
    if ($backup) out331('[OK] Backup: ' . str_replace('\\', '/', $backup));
    if (file_put_contents($file, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $file);
    }
}

function ensureSearchRequire331(string $src): string
{
    if (str_contains($src, "../_search.php")) return $src;

    $paginationRequire = "require_once __DIR__ . '/../_pagination.php';";
    if (str_contains($src, $paginationRequire)) {
        return str_replace(
            $paginationRequire,
            $paginationRequire . "\nrequire_once __DIR__ . '/../_search.php';",
            $src,
            $count
        );
    }

    $bootstrapRequire = "require_once __DIR__ . '/../../bootstrap.php';";
    if (!str_contains($src, $bootstrapRequire)) {
        throw new RuntimeException('Não foi possível localizar os includes administrativos necessários.');
    }
    return str_replace(
        $bootstrapRequire,
        $bootstrapRequire . "\nrequire_once __DIR__ . '/../_search.php';",
        $src,
        $count
    );
}

function replaceBetween331(string $src, string $startNeedle, string $endNeedle, string $replacement): string
{
    $start = strpos($src, $startNeedle);
    if ($start === false) {
        throw new RuntimeException('Marcador inicial não encontrado: ' . $startNeedle);
    }
    $end = strpos($src, $endNeedle, $start);
    if ($end === false) {
        throw new RuntimeException('Marcador final não encontrado: ' . $endNeedle);
    }
    return substr($src, 0, $start) . $replacement . "\n" . substr($src, $end);
}

function insertBeforeFirst331(string $src, string $needle, string $html, string $marker): string
{
    if (str_contains($src, $marker)) return $src;
    $pos = strpos($src, $needle);
    if ($pos === false) {
        throw new RuntimeException('Não foi possível localizar o ponto para inserir o campo de pesquisa.');
    }
    return substr($src, 0, $pos)
        . "<?php /* " . $marker . " */ ?>\n"
        . $html . "\n"
        . substr($src, $pos);
}

function patchPosts331(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.1: pesquisa de posts')) {
        out331('[OK] Posts/Notícias já possuem pesquisa v0.33.1.');
        return;
    }
    $src = ensureSearchRequire331($src);
    $isTrashView = str_contains($src, '$activeCount') && str_contains($src, '$trashCount') && str_contains($src, '$view');

    if ($isTrashView) {
        $replacement = <<<'PHP'
// v0.33.1: pesquisa de posts + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = " AND (
        p.titulo LIKE :post_q1
        OR p.slug LIKE :post_q2
        OR COALESCE(p.resumo,'') LIKE :post_q3
        OR COALESCE(p.conteudo,'') LIKE :post_q4
        OR COALESCE(c.nome,'') LIKE :post_q5
        OR COALESCE(cat.nome,'') LIKE :post_q6
        OR EXISTS (
            SELECT 1
            FROM post_categorias pcq
            INNER JOIN categorias cq ON cq.id=pcq.categoria_id
            WHERE pcq.post_id=p.id
              AND (cq.nome LIKE :post_q7 OR cq.slug LIKE :post_q8)
        )
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 8; $i++) $searchParams['post_q' . $i] = $like;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id
     LEFT JOIN categorias cat ON cat.id=p.categoria_id
     WHERE {$where}{$searchSql}"
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);

$listSql = "SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome,
            (SELECT COUNT(*) FROM revisoes r WHERE r.tipo='post' AND r.conteudo_id=p.id) AS total_revisoes
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id
     LEFT JOIN categorias cat ON cat.id=p.categoria_id
     WHERE {$where}{$searchSql}
     ORDER BY " . ($view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.id DESC')
     . " LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$posts = $listStmt->fetchAll();
PHP;
        $src = replaceBetween331(
            $src,
            '// v0.33.0: paginação de posts',
            '$pageTitle = $view === \'lixeira\' ? \'Lixeira de Notícias\' : \'Notícias\';',
            $replacement
        );
        $src = insertBeforeFirst331(
            $src,
            '<div class="card border-0 shadow-sm"><div class="table-responsive">',
            "<?= adminSearchHtml('admin/noticias/index.php', \$search, ['status' => \$view === 'lixeira' ? 'lixeira' : null], 'Pesquisar notícias…', \$totalItems) ?>",
            'v0.33.1-search-form-posts'
        );
        $oldPagination = "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null]) ?>";
        $newPagination = "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null, 'q' => \$search]) ?>";
        if (str_contains($src, $oldPagination)) {
            $src = str_replace($oldPagination, $newPagination, $src, $count);
        }
    } else {
        $replacement = <<<'PHP'
// v0.33.1: pesquisa de posts + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = " WHERE (
        p.titulo LIKE :post_q1
        OR p.slug LIKE :post_q2
        OR COALESCE(p.resumo,'') LIKE :post_q3
        OR COALESCE(p.conteudo,'') LIKE :post_q4
        OR COALESCE(c.nome,'') LIKE :post_q5
        OR EXISTS (
            SELECT 1
            FROM post_categorias pcq
            INNER JOIN categorias cq ON cq.id=pcq.categoria_id
            WHERE pcq.post_id=p.id
              AND (cq.nome LIKE :post_q6 OR cq.slug LIKE :post_q7)
        )
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 7; $i++) $searchParams['post_q' . $i] = $like;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id" . $searchSql
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);

$listSql = "SELECT p.*, c.nome AS comunidade_nome,
        (SELECT GROUP_CONCAT(cat.nome ORDER BY pc.principal DESC, cat.nome SEPARATOR '||')
         FROM post_categorias pc
         INNER JOIN categorias cat ON cat.id=pc.categoria_id
         WHERE pc.post_id=p.id) AS categorias_nomes
     FROM posts p
     LEFT JOIN comunidades c ON c.id=p.comunidade_id"
     . $searchSql
     . " ORDER BY p.id DESC LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$posts = $listStmt->fetchAll();
PHP;
        $src = replaceBetween331(
            $src,
            '// v0.33.0: paginação de posts',
            '$pageTitle = \'Notícias\';',
            $replacement
        );
        $src = insertBeforeFirst331(
            $src,
            '<div class="card border-0 shadow-sm"><div class="table-responsive">',
            "<?= adminSearchHtml('admin/noticias/index.php', \$search, [], 'Pesquisar notícias…', \$totalItems) ?>",
            'v0.33.1-search-form-posts'
        );
        $oldPagination = "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination) ?>";
        $newPagination = "<?= adminPaginationHtml('admin/noticias/index.php', \$pagination, ['q' => \$search]) ?>";
        if (str_contains($src, $oldPagination)) {
            $src = str_replace($oldPagination, $newPagination, $src, $count);
        }
    }

    writePatched331($file, $src, $backupDir);
    out331('[OK] Posts/Notícias: pesquisa adicionada.');
}

function patchCategories331(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.1: pesquisa de categorias')) {
        out331('[OK] Categorias já possuem pesquisa v0.33.1.');
        return;
    }
    $src = ensureSearchRequire331($src);

    $replacement = <<<'PHP'
// v0.33.1: pesquisa de categorias + paginação de 50 itens na tabela.
$search = adminSearchTerm();
$categoriasFiltradas = $search === ''
    ? $categorias
    : array_values(array_filter($categorias, static function (array $categoria) use ($search): bool {
        return adminSearchMatches(
            $search,
            $categoria['nome'] ?? '',
            $categoria['slug'] ?? '',
            $categoria['descricao'] ?? '',
            CategoryService::optionLabel($categoria)
        );
    }));
$pagination = adminPaginationState(count($categoriasFiltradas), 50);
$categoriasPagina = array_slice($categoriasFiltradas, $pagination['offset'], $pagination['limit']);
PHP;
    $src = replaceBetween331(
        $src,
        '// v0.33.0: paginação de categorias',
        '$countRows = $pdo->query(',
        $replacement
    );

    $src = insertBeforeFirst331(
        $src,
        '<div class="row g-4">',
        "<?= adminSearchHtml('admin/categorias/index.php', \$search, [], 'Pesquisar categorias…', (int)\$pagination['total']) ?>",
        'v0.33.1-search-form-categories'
    );

    $oldPagination = "<?= adminPaginationHtml('admin/categorias/index.php', \$pagination) ?>";
    $newPagination = "<?= adminPaginationHtml('admin/categorias/index.php', \$pagination, ['q' => \$search]) ?>";
    if (str_contains($src, $oldPagination)) {
        $src = str_replace($oldPagination, $newPagination, $src, $count);
    }

    writePatched331($file, $src, $backupDir);
    out331('[OK] Categorias: pesquisa adicionada.');
}

function patchTags331(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.1: pesquisa de tags')) {
        out331('[OK] Tags já possuem pesquisa v0.33.1.');
        return;
    }
    $src = ensureSearchRequire331($src);

    $replacement = <<<'PHP'
// v0.33.1: pesquisa de tags + paginação de 50 registros.
$search = adminSearchTerm();
$searchWhere = '';
$searchParams = [];
if ($search !== '') {
    $searchWhere = " WHERE (t.nome LIKE :tag_q1 OR t.slug LIKE :tag_q2 OR COALESCE(t.descricao,'') LIKE :tag_q3)";
    $like = '%' . $search . '%';
    $searchParams = ['tag_q1' => $like, 'tag_q2' => $like, 'tag_q3' => $like];
}
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM tags t' . $searchWhere);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$listStmt = $pdo->prepare(
    "SELECT t.id,t.nome,t.slug,t.descricao,COUNT(p.id) total_posts
     FROM tags t
     LEFT JOIN post_tags pt ON pt.tag_id=t.id
     LEFT JOIN posts p ON p.id=pt.post_id AND p.status <> 'lixeira'"
     . $searchWhere
     . " GROUP BY t.id,t.nome,t.slug,t.descricao
         ORDER BY t.nome
         LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset']
);
$listStmt->execute($searchParams);
$tags = $listStmt->fetchAll();
PHP;
    $src = replaceBetween331(
        $src,
        '// v0.33.0: paginação de tags',
        '$pageTitle=\'Tags de Posts\';',
        $replacement
    );

    $src = insertBeforeFirst331(
        $src,
        '<div class="row g-4">',
        "<?= adminSearchHtml('admin/tags/index.php', \$search, [], 'Pesquisar tags…', \$totalItems) ?>",
        'v0.33.1-search-form-tags'
    );

    $oldPagination = "<?= adminPaginationHtml('admin/tags/index.php', \$pagination) ?>";
    $newPagination = "<?= adminPaginationHtml('admin/tags/index.php', \$pagination, ['q' => \$search]) ?>";
    if (str_contains($src, $oldPagination)) {
        $src = str_replace($oldPagination, $newPagination, $src, $count);
    }

    writePatched331($file, $src, $backupDir);
    out331('[OK] Tags: pesquisa adicionada.');
}

function patchPages331(string $file, string $backupDir): void
{
    $src = (string)file_get_contents($file);
    if (str_contains($src, 'v0.33.1: pesquisa de páginas')) {
        out331('[OK] Páginas já possuem pesquisa v0.33.1.');
        return;
    }
    $src = ensureSearchRequire331($src);
    $isTrashView = str_contains($src, '$activeCount') && str_contains($src, '$trashCount') && str_contains($src, '$view');

    if ($isTrashView) {
        $replacement = <<<'PHP'
// v0.33.1: pesquisa de páginas + paginação de 50 registros.
$search = adminSearchTerm();
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = " AND (
        p.titulo LIKE :page_q1
        OR p.slug LIKE :page_q2
        OR COALESCE(p.resumo,'') LIKE :page_q3
        OR COALESCE(p.conteudo,'') LIKE :page_q4
        OR COALESCE(u.nome,'') LIKE :page_q5
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 5; $i++) $searchParams['page_q' . $i] = $like;
}
$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM paginas p
     LEFT JOIN usuarios u ON u.id=p.autor_id
     WHERE {$where}{$searchSql}"
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$listSql = "SELECT p.*, u.nome AS autor_nome,
            (SELECT COUNT(*) FROM revisoes r WHERE r.tipo='pagina' AND r.conteudo_id=p.id) AS total_revisoes
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id
     WHERE {$where}{$searchSql}
     ORDER BY " . ($view === 'lixeira' ? 'p.lixeira_em DESC, p.id DESC' : 'p.ordem ASC, p.id DESC')
     . " LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset'];
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($searchParams);
$paginas = $listStmt->fetchAll();
PHP;
        $src = replaceBetween331(
            $src,
            '// v0.33.0: paginação de páginas',
            '$pageTitle = $view === \'lixeira\' ? \'Lixeira de Páginas\' : \'Páginas\';',
            $replacement
        );
        $src = insertBeforeFirst331(
            $src,
            '<div class="card border-0 shadow-sm"><div class="table-responsive">',
            "<?= adminSearchHtml('admin/paginas/index.php', \$search, ['status' => \$view === 'lixeira' ? 'lixeira' : null], 'Pesquisar páginas…', \$totalItems) ?>",
            'v0.33.1-search-form-pages'
        );
        $oldPagination = "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null]) ?>";
        $newPagination = "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination, ['status' => \$view === 'lixeira' ? 'lixeira' : null, 'q' => \$search]) ?>";
        if (str_contains($src, $oldPagination)) {
            $src = str_replace($oldPagination, $newPagination, $src, $count);
        }
    } else {
        $replacement = <<<'PHP'
// v0.33.1: pesquisa de páginas + paginação de 50 registros.
$search = adminSearchTerm();
$searchWhere = '';
$searchParams = [];
if ($search !== '') {
    $searchWhere = " WHERE (
        p.titulo LIKE :page_q1
        OR p.slug LIKE :page_q2
        OR COALESCE(p.resumo,'') LIKE :page_q3
        OR COALESCE(p.conteudo,'') LIKE :page_q4
        OR COALESCE(u.nome,'') LIKE :page_q5
    )";
    $like = '%' . $search . '%';
    for ($i = 1; $i <= 5; $i++) $searchParams['page_q' . $i] = $like;
}
$countStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
     FROM paginas p
     LEFT JOIN usuarios u ON u.id=p.autor_id" . $searchWhere
);
$countStmt->execute($searchParams);
$totalItems = (int)$countStmt->fetchColumn();
$pagination = adminPaginationState($totalItems, 50);
$listStmt = $pdo->prepare(
    "SELECT p.*, u.nome AS autor_nome
     FROM paginas p
     LEFT JOIN usuarios u ON u.id = p.autor_id"
     . $searchWhere
     . " ORDER BY p.ordem ASC, p.id DESC LIMIT " . (int)$pagination['limit'] . " OFFSET " . (int)$pagination['offset']
);
$listStmt->execute($searchParams);
$paginas = $listStmt->fetchAll();
PHP;
        $src = replaceBetween331(
            $src,
            '// v0.33.0: paginação de páginas',
            '$pageTitle = \'Páginas\';',
            $replacement
        );
        $src = insertBeforeFirst331(
            $src,
            '<div class="card border-0 shadow-sm">',
            "<?= adminSearchHtml('admin/paginas/index.php', \$search, [], 'Pesquisar páginas…', \$totalItems) ?>",
            'v0.33.1-search-form-pages'
        );
        $oldPagination = "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination) ?>";
        $newPagination = "<?= adminPaginationHtml('admin/paginas/index.php', \$pagination, ['q' => \$search]) ?>";
        if (str_contains($src, $oldPagination)) {
            $src = str_replace($oldPagination, $newPagination, $src, $count);
        }
    }

    writePatched331($file, $src, $backupDir);
    out331('[OK] Páginas: pesquisa adicionada.');
}

function updateVersion331(string $config, string $backupDir): void
{
    $src = (string)file_get_contents($config);
    $patched = $src;
    $pattern = "/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";

    if (preg_match($pattern, $patched)) {
        $patched = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION_331 . "');", $patched, 1) ?? $patched;
    } else {
        $define = "define('APP_VERSION', '" . TARGET_VERSION_331 . "');";
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
        $backup = backup331($config, $backupDir);
        if ($backup) out331('[OK] Backup do config.php: ' . str_replace('\\', '/', $backup));
        if (file_put_contents($config, $patched, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
        }
    }
    out331('[OK] APP_VERSION = ' . TARGET_VERSION_331);
}

function lint331(string $file): void
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    $lines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('Erro de sintaxe em ' . $file . ': ' . implode(' ', $lines));
    }
    out331('[OK] php -l: ' . str_replace('\\', '/', $file));
}

// Permite testes automatizados das funções de patch sem executar a atualização completa.
if (defined('PORTAL_UPDATE_LIBRARY_ONLY') && PORTAL_UPDATE_LIBRARY_ONLY === true) {
    return;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este atualizador deve ser executado pelo terminal:\nphp atualizar_v0.33.1.php\n");
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$paginationFile = $root . '/admin/_pagination.php';
$searchFile = $root . '/admin/_search.php';
$postsFile = $root . '/admin/noticias/index.php';
$categoriesFile = $root . '/admin/categorias/index.php';
$tagsFile = $root . '/admin/tags/index.php';
$pagesFile = $root . '/admin/paginas/index.php';
$configBackupDir = $root . '/storage/config-backups';
$updateBackupDir = $root . '/storage/update-backups/v0.33.1';

out331('Portal IECLB Parobé - atualização para v' . TARGET_VERSION_331);
out331(str_repeat('-', 76));

foreach ([$config, $dbFile, $paginationFile, $searchFile, $postsFile, $categoriesFile, $tagsFile, $pagesFile] as $required) {
    if (!is_file($required)) {
        fail331('Arquivo necessário não encontrado: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $required));
    }
}

require_once $config;
require_once $dbFile;
$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : '';
if ($currentVersion !== '') {
    out331('Versão identificada: ' . $currentVersion);
    if (version_compare($currentVersion, MINIMUM_VERSION_331, '<')) {
        fail331('A v0.33.1 requer o Portal v' . MINIMUM_VERSION_331 . ' ou superior. Execute primeiro a v0.33.0.');
    }
    if (version_compare($currentVersion, TARGET_VERSION_331, '>')) {
        fail331('O Portal já está em uma versão superior (' . $currentVersion . '). Este pacote não será aplicado.');
    }
} else {
    out331('[AVISO] APP_VERSION não está definida; a constante será inserida automaticamente após declare(strict_types=1).');
}

try {
    ensureDir331($configBackupDir);
    ensureDir331($updateBackupDir);

    Database::connection();
    out331('[OK] Conexão com o banco realizada. Nenhuma migração SQL é necessária.');

    patchPosts331($postsFile, $updateBackupDir);
    patchCategories331($categoriesFile, $updateBackupDir);
    patchTags331($tagsFile, $updateBackupDir);
    patchPages331($pagesFile, $updateBackupDir);

    foreach ([$paginationFile, $searchFile, $postsFile, $categoriesFile, $tagsFile, $pagesFile] as $file) {
        lint331($file);
    }

    updateVersion331($config, $configBackupDir);

    if (function_exists('opcache_reset')) @opcache_reset();

    out331(str_repeat('-', 76));
    out331('Atualização v' . TARGET_VERSION_331 . ' concluída.');
    out331('Posts, Categorias, Tags e Páginas agora possuem pesquisa integrada à paginação de 50 itens.');
} catch (Throwable $e) {
    fail331($e->getMessage());
}
