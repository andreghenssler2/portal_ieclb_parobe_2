<?php

declare(strict_types=1);

const TARGET_VERSION = '0.34.0';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

function backupFile(string $path, string $root): ?string
{
    if (!is_file($path)) return null;

    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    $dir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');
    $target = $dir . '/' . $relative;

    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar pasta de backup para ' . $relative . '.');
    }
    if (!copy($path, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
    return $target;
}

function patchHeader(string $header, string $root): void
{
    if (!is_file($header)) throw new RuntimeException('admin/_header.php não encontrado.');

    $src = (string)file_get_contents($header);
    $original = $src;

    if (!str_contains($src, 'admin-menu-v34.css')) {
        $needle = '<link rel="stylesheet" href="<?= e(url(\'public/css/admin.css\')) ?>">';
        if (!str_contains($src, $needle)) {
            throw new RuntimeException('Não foi possível localizar admin.css em admin/_header.php.');
        }
        $src = str_replace(
            $needle,
            $needle . "\n    <link rel=\"stylesheet\" href=\"<?= e(url('public/css/admin-menu-v34.css')) ?>\">",
            $src
        );
    }

    if (!str_contains($src, 'admin-menu-v34.js')) {
        $needle = '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
        if (!str_contains($src, $needle)) {
            throw new RuntimeException('Não foi possível localizar Bootstrap JS em admin/_header.php.');
        }
        $src = str_replace(
            $needle,
            $needle . "\n    <script defer src=\"<?= e(url('public/js/admin-menu-v34.js')) ?>\"></script>",
            $src
        );
    }

    if (!str_contains($src, 'adminDesktopMenuToggle')) {
        $mobilePattern = '~(<button\s+class="btn btn-link text-white d-lg-none p-1"[^>]*data-bs-target="#adminSidebar"[^>]*>.*?</button>)~s';
        if (preg_match($mobilePattern, $src, $m)) {
            $replacement = $m[1] . "\n            <button class=\"admin-menu-trigger d-none d-lg-inline-flex\" type=\"button\" id=\"adminDesktopMenuToggle\" aria-expanded=\"true\" aria-label=\"Recolher menu administrativo\" title=\"Recolher menu\">\n                <i class=\"bi bi-list\"></i>\n            </button>";
            $src = preg_replace($mobilePattern, addcslashes($replacement, '\\$'), $src, 1) ?? $src;
        } else {
            $anchor = '<a class="navbar-brand fw-semibold mb-0"';
            $pos = strpos($src, $anchor);
            if ($pos === false) throw new RuntimeException('Não foi possível localizar a marca do painel para inserir o hambúrguer.');
            $button = "            <button class=\"admin-menu-trigger d-none d-lg-inline-flex\" type=\"button\" id=\"adminDesktopMenuToggle\" aria-expanded=\"true\" aria-label=\"Recolher menu administrativo\" title=\"Recolher menu\">\n                <i class=\"bi bi-list\"></i>\n            </button>\n";
            $src = substr($src, 0, $pos) . $button . substr($src, $pos);
        }
    }

    // Atualiza o botão móvel para o mesmo acabamento visual sem afetar o offcanvas.
    if (!str_contains($src, 'adminMobileMenuToggle')) {
        $src = preg_replace(
            '~<button\s+class="btn btn-link text-white d-lg-none p-1"\s+type="button"\s+data-bs-toggle="offcanvas"\s+data-bs-target="#adminSidebar"\s+aria-controls="adminSidebar"\s+aria-label="Abrir menu">~',
            '<button class="admin-menu-trigger d-lg-none" type="button" id="adminMobileMenuToggle" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Abrir menu">',
            $src,
            1
        ) ?? $src;
    }

    if ($src !== $original) {
        backupFile($header, $root);
        if (file_put_contents($header, $src, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível atualizar admin/_header.php.');
        }
        out('[OK] Menu administrativo integrado ao layout v0.34.0.');
    } else {
        out('[OK] admin/_header.php já estava atualizado.');
    }
}

function updateVersion(string $config, string $root): void
{
    if (!is_file($config)) throw new RuntimeException('config/config.php não encontrado.');
    $src = (string)file_get_contents($config);
    $original = $src;

    $pattern = "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";
    if (preg_match($pattern, $src)) {
        $src = preg_replace($pattern, "define('APP_VERSION', '" . TARGET_VERSION . "');", $src, 1) ?? $src;
    } else {
        // APP_VERSION deve ficar depois de declare(strict_types=1);
        $declare = 'declare(strict_types=1);';
        $pos = strpos($src, $declare);
        if ($pos !== false) {
            $insertAt = $pos + strlen($declare);
            $src = substr($src, 0, $insertAt)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($src, $insertAt);
        } else {
            $phpPos = strpos($src, '<?php');
            if ($phpPos === false) throw new RuntimeException('config.php inválido: <?php não encontrado.');
            $insertAt = $phpPos + 5;
            $src = substr($src, 0, $insertAt)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($src, $insertAt);
        }
    }

    if ($src !== $original) {
        backupFile($config, $root);
        if (file_put_contents($config, $src, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível atualizar APP_VERSION.');
        }
    }
    out('[OK] APP_VERSION = ' . TARGET_VERSION . '.');
}

$root = __DIR__;
$header = $root . '/admin/_header.php';
$config = $root . '/config/config.php';

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out(str_repeat('-', 72));

foreach ([
    'public/css/admin-menu-v34.css',
    'public/js/admin-menu-v34.js',
    'CHANGELOG_v0.34.0.md',
] as $required) {
    if (!is_file($root . '/' . $required)) fail('Arquivo da atualização não encontrado: ' . $required);
}

try {
    patchHeader($header, $root);
    updateVersion($config, $root);

    if (function_exists('opcache_reset')) @opcache_reset();

    out(str_repeat('-', 72));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('Atualize o painel com Ctrl+F5.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
