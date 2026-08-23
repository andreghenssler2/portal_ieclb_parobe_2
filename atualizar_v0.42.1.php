<?php

declare(strict_types=1);

const TARGET_VERSION = '0.42.1';
const MIN_VERSION = '0.40.0';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

function readVersion(string $configPath): string
{
    if (!is_file($configPath)) {
        return '0.0.0';
    }

    $source = (string)file_get_contents($configPath);

    if (preg_match(
        "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/",
        $source,
        $match
    )) {
        return trim((string)$match[1]);
    }

    return '0.0.0';
}

function backupFile(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        return;
    }

    $relative = ltrim(
        str_replace('\\', '/', substr($path, strlen($root))),
        '/'
    );

    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target))
        && !mkdir(dirname($target), 0755, true)
        && !is_dir(dirname($target))) {
        throw new RuntimeException(
            'Não foi possível criar a pasta de backup de ' . $relative . '.'
        );
    }

    if (!copy($path, $target)) {
        throw new RuntimeException(
            'Não foi possível criar backup de ' . $relative . '.'
        );
    }
}

function writeChanged(
    string $root,
    string $backupDir,
    string $path,
    string $source,
    string $label
): void {
    $current = is_file($path) ? (string)file_get_contents($path) : '';

    if ($current === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    backupFile($root, $backupDir, $path);

    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException(
            'Não foi possível atualizar ' . $label . '.'
        );
    }

    lintPhp($path);
    out('[OK] ' . $label . ' atualizado.');
}

function lintPhp(string $path): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php')
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1';

    $lines = [];
    $code = 1;
    exec($command, $lines, $code);

    if ($code !== 0) {
        throw new RuntimeException(
            $path . " não passou no php -l:\n"
            . implode(PHP_EOL, $lines)
        );
    }
}

function patchAnalytics(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        throw new RuntimeException(
            'app/Services/NewsAnalyticsService.php não encontrado.'
        );
    }

    $source = (string)file_get_contents($path);

    $old = "m.caminho imagem_capa_midia,m.alt_text imagem_capa_alt,";
    $new = "COALESCE(NULLIF(m.caminho,''),NULLIF(p.imagem_capa,'')) imagem_capa_midia,m.alt_text imagem_capa_alt,";

    if (str_contains($source, $new)) {
        out('[OK] NewsAnalyticsService já possui fallback da imagem de capa.');
        return;
    }

    $count = substr_count($source, $old);
    if ($count < 2) {
        throw new RuntimeException(
            'Não foi possível localizar as duas consultas do ranking em NewsAnalyticsService.php.'
        );
    }

    $source = str_replace($old, $new, $source);

    writeChanged(
        $root,
        $backupDir,
        $path,
        $source,
        'app/Services/NewsAnalyticsService.php'
    );
}

function patchMostRead(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        throw new RuntimeException('mais-lidas.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, 'most-read-cover')) {
        out('[OK] mais-lidas.php já exibe a capa no lugar da numeração.');
        return;
    }

    $old = <<<'PHP'
<div class="col-12"><article class="card border-0 shadow-sm overflow-hidden"><div class="row g-0">
<?php if(!empty($post['imagem_capa_midia'])):?><div class="col-md-4 col-lg-3"><a href="<?=e(contentUrl('noticia',(string)$post['slug']))?>" class="d-block h-100"><img src="<?=e(mediaUrl((string)$post['imagem_capa_midia']))?>" alt="<?=e((string)($post['imagem_capa_alt'] ?: $post['titulo']))?>" class="w-100 h-100" style="object-fit:cover;min-height:190px"></a></div><?php endif;?>
<div class="<?=!empty($post['imagem_capa_midia'])?'col-md-8 col-lg-9':'col-12'?>"><div class="card-body p-4"><div class="d-flex gap-3">
<div class="display-6 fw-bold text-secondary opacity-50" style="min-width:2.5rem"><?=str_pad((string)($index+1),2,'0',STR_PAD_LEFT)?></div>
<div class="flex-grow-1">
PHP;

    $new = <<<'PHP'
<div class="col-12"><article class="card border-0 shadow-sm overflow-hidden">
<div class="card-body p-4"><div class="d-flex flex-column flex-md-row gap-3 gap-md-4">
<?php if(!empty($post['imagem_capa_midia'])):?>
<a href="<?=e(contentUrl('noticia',(string)$post['slug']))?>" class="most-read-cover d-block flex-shrink-0 overflow-hidden rounded">
<img src="<?=e(mediaUrl((string)$post['imagem_capa_midia']))?>" alt="<?=e((string)($post['imagem_capa_alt'] ?: $post['titulo']))?>" class="w-100 h-100" style="object-fit:cover">
</a>
<?php else:?>
<div class="most-read-cover flex-shrink-0 rounded bg-light border d-flex align-items-center justify-content-center text-secondary" aria-hidden="true">
<i class="bi bi-image fs-2"></i>
</div>
<?php endif;?>
<div class="flex-grow-1">
PHP;

    if (!str_contains($source, $old)) {
        throw new RuntimeException(
            'Não foi possível localizar o bloco atual do ranking em mais-lidas.php.'
        );
    }

    $source = str_replace($old, $new, $source);

    $oldClose = <<<'PHP'
</div></div></div></div>
</div></article></div>
PHP;

    $newClose = <<<'PHP'
</div></div></div>
</article></div>
PHP;

    if (!str_contains($source, $oldClose)) {
        throw new RuntimeException(
            'Não foi possível ajustar o fechamento do card em mais-lidas.php.'
        );
    }

    $source = str_replace($oldClose, $newClose, $source);

    $styleAnchor = <<<'PHP'
<section class="container py-5">
PHP;

    $style = <<<'PHP'
<section class="container py-5">
<style>
.most-read-cover{width:180px;min-width:180px;height:120px}
@media (max-width:767.98px){
    .most-read-cover{width:100%;min-width:0;height:200px}
}
</style>
PHP;

    if (!str_contains($source, $styleAnchor)) {
        throw new RuntimeException(
            'Não foi possível inserir o estilo das capas em mais-lidas.php.'
        );
    }

    $source = str_replace($styleAnchor, $style, $source);

    writeChanged(
        $root,
        $backupDir,
        $path,
        $source,
        'mais-lidas.php'
    );
}

function updateVersion(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        throw new RuntimeException('config/config.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (preg_match(
        "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]+['\"]\s*\)\s*;/",
        $source
    )) {
        $source = (string)preg_replace(
            "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"][^'\"]+['\"]\s*\)\s*;/",
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        );
    } else {
        $anchor = "declare(strict_types=1);";
        if (str_contains($source, $anchor)) {
            $source = str_replace(
                $anchor,
                $anchor . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');",
                $source
            );
        } else {
            $source = "<?php\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');\n"
                . ltrim(preg_replace('/^<\?php\s*/', '', $source) ?? $source);
        }
    }

    writeChanged(
        $root,
        $backupDir,
        $path,
        $source,
        'config/config.php'
    );
}

$root = __DIR__;
$configPath = $root . '/config/config.php';

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Mais Lidas: capa no lugar da numeração');
out(str_repeat('-', 72));

$currentVersion = readVersion($configPath);
out('Versão identificada: ' . $currentVersion);

if (version_compare($currentVersion, MIN_VERSION, '<')) {
    fail(
        'A v' . TARGET_VERSION
        . ' requer Portal v' . MIN_VERSION
        . ' ou superior.'
    );
}

if (version_compare($currentVersion, TARGET_VERSION, '>')) {
    fail(
        'O Portal já está em uma versão superior (' . $currentVersion . ').'
    );
}

$backupDir = $root
    . '/storage/update-backups/v'
    . TARGET_VERSION
    . '-'
    . date('Ymd-His');

try {
    patchAnalytics(
        $root,
        $backupDir,
        $root . '/app/Services/NewsAnalyticsService.php'
    );

    patchMostRead(
        $root,
        $backupDir,
        $root . '/mais-lidas.php'
    );

    updateVersion(
        $root,
        $backupDir,
        $configPath
    );

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 72));
    out('[OK] Atualização v' . TARGET_VERSION . ' concluída.');
    if (is_dir($backupDir)) {
        out('Backup: ' . str_replace('\\', '/', $backupDir));
    }
    out('Limpe o cache do navegador com Ctrl+F5.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
