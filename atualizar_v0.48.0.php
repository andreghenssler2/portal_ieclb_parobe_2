<?php

declare(strict_types=1);

const TARGET_VERSION = '0.48.0';
const MIN_VERSION = '0.47.0';

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
    string $label,
    bool $php = false
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

    if ($php) {
        lintPhp($path);
    }

    out('[OK] ' . $label . ' atualizado.');
}

function installResource(
    string $resourceRelative,
    string $targetRelative,
    string $marker,
    bool $php = false
): void {
    global $root;

    $target = $root . '/' . ltrim($targetRelative, '/');

    if (is_file($target) && $marker !== '') {
        $current = (string)file_get_contents($target);

        if (str_contains($current, $marker)) {
            out(
                '[OK] '
                . $targetRelative
                . ' já está na v'
                . TARGET_VERSION
                . '.'
            );
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
        $targetRelative,
        $php
    );
}

function updateVersion(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'config/config.php não encontrado.'
        );
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

    writeChanged(
        $path,
        $source,
        'config/config.php',
        true
    );
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Importar Galeria para dentro de Páginas e Posts/Notícias');
out(str_repeat('-', 84));

$config = $root . '/config/config.php';

foreach ([
    $config,
    $root . '/app/Services/ContentBlockService.php',
    $root . '/app/Services/DynamicContentBlockService.php',
    $root . '/admin/_content_blocks_editor.php',
    $root . '/public/js/content-block-editor.js',
] as $required) {
    if (!is_file($required)) {
        fail(
            'Arquivo obrigatório não encontrado: '
            . str_replace($root . '/', '', $required)
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
    installResource(
        'app/Services/ContentBlockService.php',
        'app/Services/ContentBlockService.php',
        '// v0.48.0 - galeria incorporada dentro do conteúdo.',
        true
    );

    installResource(
        'app/Services/DynamicContentBlockService.php',
        'app/Services/DynamicContentBlockService.php',
        '// v0.48.0 - galeria incorporada dentro de Página/Post.',
        true
    );

    installResource(
        'admin/_content_blocks_editor.php',
        'admin/_content_blocks_editor.php',
        'Editor compartilhado de blocos v0.48.0',
        true
    );

    installResource(
        'public/js/content-block-editor.js',
        'public/js/content-block-editor.js',
        'Portal IECLB Parobé v0.48.0',
        false
    );

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

    out(str_repeat('-', 84));
    out('[OK] Atualização v' . TARGET_VERSION . ' concluída.');
    out('[OK] Novo bloco: Importar Galeria.');
    out('[OK] Galerias publicadas podem ser incorporadas em Páginas e Notícias.');
    out('[OK] Layouts da galeria incorporada: Grade e Carrossel.');
    out('[OK] Lightbox com Anterior/Próxima e setas do teclado.');
    out('[OK] A galeria permanece vinculada ao cadastro original.');
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
