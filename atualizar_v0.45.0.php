<?php

declare(strict_types=1);

const TARGET_VERSION = '0.45.0';
const MIN_VERSION = '0.44.1';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

$root = __DIR__;
$backupDir = $root
    . '/storage/update-backups/v'
    . TARGET_VERSION
    . '-'
    . date('Ymd-His');

function backupFile(string $path): void
{
    global $root, $backupDir;

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
            'Não foi possível criar backup de ' . $relative . '.'
        );
    }

    if (!copy($path, $target)) {
        throw new RuntimeException(
            'Não foi possível criar backup de ' . $relative . '.'
        );
    }
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
            basename($path)
            . " não passou no php -l:\n"
            . implode(PHP_EOL, $lines)
        );
    }
}

function writeChanged(
    string $path,
    string $source,
    string $label
): void {
    $current = is_file($path)
        ? (string)file_get_contents($path)
        : '';

    if ($current === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    backupFile($path);

    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException(
            'Não foi possível atualizar ' . $label . '.'
        );
    }

    if (str_ends_with(strtolower($path), '.php')) {
        lintPhp($path);
    }

    out('[OK] ' . $label . ' atualizado.');
}

function installResource(
    string $resourceRelative,
    string $targetRelative,
    string $marker
): void {
    global $root;

    $target = $root . '/' . ltrim($targetRelative, '/');

    if (is_file($target) && $marker !== '') {
        $current = (string)file_get_contents($target);

        if (str_contains($current, $marker)) {
            out('[OK] ' . $targetRelative . ' já está na v' . TARGET_VERSION . '.');
            return;
        }
    }

    $resource = $root
        . '/update-resources/v'
        . TARGET_VERSION
        . '/'
        . ltrim($resourceRelative, '/');

    if (!is_file($resource)) {
        throw new RuntimeException(
            'Recurso da atualização não encontrado: '
            . $resourceRelative
        );
    }

    writeChanged(
        $target,
        (string)file_get_contents($resource),
        $targetRelative
    );
}

function patchBootstrap(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('bootstrap.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, 'DynamicContentBlockService.php')) {
        out('[OK] bootstrap.php já carrega DynamicContentBlockService.');
        return;
    }

    $anchor =
        "require_once __DIR__ . '/app/Services/ContentBlockService.php';";

    if (!str_contains($source, $anchor)) {
        throw new RuntimeException(
            'Não foi possível localizar ContentBlockService no bootstrap.php.'
        );
    }

    $replacement = $anchor
        . "\nrequire_once __DIR__ . '/app/Services/DynamicContentBlockService.php';";

    $source = str_replace(
        $anchor,
        $replacement,
        $source,
        $count
    );

    if ($count !== 1) {
        throw new RuntimeException(
            'Estrutura inesperada no bootstrap.php.'
        );
    }

    writeChanged($path, $source, 'bootstrap.php');
}

function updateVersion(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('config/config.php não encontrado.');
    }

    $source = (string)file_get_contents($path);
    $original = $source;

    $pattern =
        "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern, $source)) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $anchor = 'declare(strict_types=1);';

        if (!str_contains($source, $anchor)) {
            throw new RuntimeException(
                'Não foi possível atualizar APP_VERSION.'
            );
        }

        $source = str_replace(
            $anchor,
            $anchor
            . "\n\ndefine('APP_VERSION', '"
            . TARGET_VERSION
            . "');",
            $source
        );
    }

    if ($source === $original) {
        out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
        return;
    }

    writeChanged($path, $source, 'config/config.php');
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Blocos dinâmicos do Portal em Páginas e Notícias');
out(str_repeat('-', 82));

$config = $root . '/config/config.php';

$required = [
    $config,
    $root . '/app/Services/ContentBlockService.php',
    $root . '/app/Services/DynamicContentBlockService.php',
    $root . '/admin/_content_blocks_editor.php',
    $root . '/public/js/content-block-editor.js',
];

foreach ($required as $file) {
    if (!is_file($file)) {
        fail(
            'Arquivo obrigatório não encontrado: '
            . str_replace($root . '/', '', $file)
        );
    }
}

require_once $config;

$current = defined('APP_VERSION')
    ? (string)APP_VERSION
    : '0.0.0';

out('Versão identificada: ' . $current);

if (version_compare($current, MIN_VERSION, '<')) {
    fail(
        'A v' . TARGET_VERSION
        . ' requer Portal v'
        . MIN_VERSION
        . ' ou superior.'
    );
}

try {
    lintPhp(
        $root . '/app/Services/DynamicContentBlockService.php'
    );

    installResource(
        'app/Services/ContentBlockService.php',
        'app/Services/ContentBlockService.php',
        '// v0.45.0 - blocos dinâmicos do Portal.'
    );

    installResource(
        'admin/_content_blocks_editor.php',
        'admin/_content_blocks_editor.php',
        'Editor compartilhado de blocos v0.45.0'
    );

    installResource(
        'public/js/content-block-editor.js',
        'public/js/content-block-editor.js',
        'Portal IECLB Parobé v0.45.0'
    );

    patchBootstrap($root . '/bootstrap.php');
    updateVersion($config);

    if (class_exists('CacheService')) {
        try {
            CacheService::clearGroup('page');
            CacheService::clearGroup('public');
        } catch (Throwable $ignored) {
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 82));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('[OK] Últimas Notícias disponível como bloco dinâmico.');
    out('[OK] Agenda/Eventos disponível como bloco dinâmico.');
    out('[OK] Documentos disponível como bloco dinâmico.');
    out('[OK] Galerias disponível como bloco dinâmico.');
    out('[OK] Comunidades disponível como bloco dinâmico.');
    out('Faça Ctrl+F5 antes de abrir o editor.');

    if (is_dir($backupDir)) {
        out(
            'Backups: '
            . str_replace('\\', '/', $backupDir)
        );
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
