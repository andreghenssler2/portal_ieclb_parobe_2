<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$settings = siteConfigAll($pdo);
header('Content-Type: text/plain; charset=UTF-8');
$allowIndex = ($settings['seo_robots_index'] ?? '1') === '1' && ($settings['privacy_allow_search_engines'] ?? '1') === '1';
echo "User-agent: *\n";
if ($allowIndex) {
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?? '');
    $adminPath = '/' . trim($basePath . '/admin/', '/');
    echo 'Disallow: ' . $adminPath . "/\n";
} else {
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?? '');
    $rootPath = $basePath !== '' && $basePath !== '/' ? '/' . trim($basePath, '/') . '/' : '/';
    echo 'Disallow: ' . $rootPath . "\n";
}
if (($settings['seo_sitemap_ativo'] ?? '1') === '1') echo 'Sitemap: ' . url('sitemap.xml') . "\n";
