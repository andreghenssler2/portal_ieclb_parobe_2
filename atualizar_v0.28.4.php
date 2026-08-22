<?php

declare(strict_types=1);

const TARGET_VERSION = '0.28.4';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
    $stmt->execute(['t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columns(PDO $pdo, string $table): array
{
    if (!tableExists($pdo, $table)) return [];
    $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) $out[(string)$row['Field']] = $row;
    return $out;
}

function findColumn(array $cols, array $candidates): ?string
{
    foreach ($candidates as $candidate) if (isset($cols[$candidate])) return $candidate;
    return null;
}

function ensureHomePostCategories(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS home_post_categorias (
            post_id BIGINT UNSIGNED NOT NULL,
            categoria_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (post_id,categoria_id),
            KEY idx_home_post_categorias_categoria (categoria_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    out('[OK] Relação estável de posts/categorias pronta.');

    if (!tableExists($pdo, 'posts')) return;
    $postCols = columns($pdo, 'posts');
    $direct = findColumn($postCols, ['categoria_id','category_id','categoria_principal_id','primary_category_id']);
    if (!$direct) return;

    $sql = 'INSERT IGNORE INTO home_post_categorias (post_id,categoria_id) '
         . 'SELECT id, `' . $direct . '` FROM posts WHERE `' . $direct . '` IS NOT NULL AND `' . $direct . '`>0';
    $count = $pdo->exec($sql);
    out('[OK] Categorias principais copiadas para a relação da Home: ' . (int)$count . '.');
}

function httpJson(string $url): ?array
{
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Portal-IECLB-Parobe/' . TARGET_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($raw) && $raw !== '' && $code >= 200 && $code < 300) $body = $raw;
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: Portal-IECLB-Parobe/" . TARGET_VERSION . "\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') $body = $raw;
    }
    if ($body === null) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Reconstitui, quando possível, as múltiplas categorias dos posts importados.
 * É best-effort: se o WordPress antigo estiver fora do ar, o usuário ainda pode
 * executar novamente Posts/Notícias em "Apenas novos" depois da atualização.
 */
function repairWordPressCategoryLinks(PDO $pdo): int
{
    if (!tableExists($pdo, 'wordpress_import_map') || !tableExists($pdo, 'home_post_categorias')) return 0;
    $mapCols = columns($pdo, 'wordpress_import_map');
    foreach (['origem_hash','origem_url','wp_id','wp_tipo','local_id'] as $required) {
        if (!isset($mapCols[$required])) return 0;
    }

    $origins = $pdo->query("SELECT DISTINCT origem_hash,origem_url FROM wordpress_import_map WHERE wp_tipo='post' AND origem_url IS NOT NULL AND origem_url<>''")->fetchAll() ?: [];
    if (!$origins) return 0;

    $insert = $pdo->prepare('INSERT IGNORE INTO home_post_categorias (post_id,categoria_id) VALUES (:post,:categoria)');
    $totalInserted = 0;

    foreach ($origins as $origin) {
        $hash = (string)$origin['origem_hash'];
        $base = rtrim((string)$origin['origem_url'], '/');
        if ($base === '') continue;

        $stmt = $pdo->prepare("SELECT wp_tipo,wp_id,local_id FROM wordpress_import_map WHERE origem_hash=:hash AND wp_tipo IN ('post','category')");
        $stmt->execute(['hash' => $hash]);
        $postMap = [];
        $categoryMap = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if ((string)$row['wp_tipo'] === 'post') $postMap[(int)$row['wp_id']] = (int)$row['local_id'];
            if ((string)$row['wp_tipo'] === 'category') $categoryMap[(int)$row['wp_id']] = (int)$row['local_id'];
        }
        if (!$postMap || !$categoryMap) continue;

        out('[INFO] Reparando categorias pela API do WordPress: ' . $base);
        for ($page = 1; $page <= 200; $page++) {
            $url = $base . '/wp-json/wp/v2/posts?per_page=100&page=' . $page . '&_fields=id,categories';
            $items = httpJson($url);
            if ($items === null) {
                if ($page === 1) out('[AVISO] Não foi possível consultar a API pública. Use o importador WordPress depois para reparar as categorias.');
                break;
            }
            if (!$items) break;

            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $wpPostId = (int)($item['id'] ?? 0);
                $localPostId = $postMap[$wpPostId] ?? 0;
                if ($localPostId <= 0) continue;
                foreach ((array)($item['categories'] ?? []) as $wpCategoryId) {
                    $localCategoryId = $categoryMap[(int)$wpCategoryId] ?? 0;
                    if ($localCategoryId <= 0) continue;
                    $insert->execute(['post' => $localPostId, 'categoria' => $localCategoryId]);
                    $totalInserted += $insert->rowCount();
                }
            }
            if (count($items) < 100) break;
        }
    }
    return $totalInserted;
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
    out('[OK] APP_VERSION atualizado para ' . TARGET_VERSION . '.');
    out('[OK] Backup do config.php: ' . basename($backup));
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

out('Portal IECLB Parobé - correção de categorias da Home v' . TARGET_VERSION);
out(str_repeat('-', 76));
if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
foreach (['app/Services/HomeService.php','app/Services/WordPressImportService.php','admin/aparencia/home.php','public/home-modular.php'] as $required) {
    if (!is_file($root . '/' . $required)) fail('Arquivo da v0.28.4 não encontrado: ' . $required);
}

require_once $config;
require_once $dbFile;
out('Versão identificada: ' . (defined('APP_VERSION') ? (string)APP_VERSION : 'não definida'));

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');
    ensureHomePostCategories($pdo);
    $repaired = repairWordPressCategoryLinks($pdo);
    out('[OK] Novos vínculos de categoria recuperados: ' . $repaired . '.');
    updateVersion($config);
    if (function_exists('opcache_reset')) @opcache_reset();
    out(str_repeat('-', 76));
    out('Atualização concluída.');
    out('Abra Aparência > Página Inicial: cada seção agora mostra quantos itens foram encontrados.');
    out('Se Comunidades/Paróquia ainda mostrarem 0, execute Posts/Notícias no importador WordPress em "Apenas novos"; a v0.28.4 reparará as categorias sem duplicar posts.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
