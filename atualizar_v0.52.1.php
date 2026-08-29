<?php

declare(strict_types=1);

const TARGET_VERSION = '0.52.1';
const MIN_VERSION = '0.52.0';

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
        str_replace(
            '\\',
            '/',
            substr($path, strlen($root))
        ),
        '/'
    );

    $target = $backupDir . '/' . $relative;

    if (
        !is_dir(dirname($target))
        && !mkdir(dirname($target), 0755, true)
        && !is_dir(dirname($target))
    ) {
        throw new RuntimeException(
            'Não foi possível criar o diretório de backup de '
            . $relative
            . '.'
        );
    }

    if (!copy($path, $target)) {
        throw new RuntimeException(
            'Não foi possível criar backup de '
            . $relative
            . '.'
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

    if (
        file_put_contents(
            $path,
            $source,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'Não foi possível atualizar '
            . $label
            . '.'
        );
    }

    if (str_ends_with(strtolower($path), '.php')) {
        lintPhp($path);
    }

    out('[OK] ' . $label . ' atualizado.');
}

function patchTinyMceColors(
    string $path,
    string $label
): void {
    if (!is_file($path)) {
        throw new RuntimeException(
            $label . ' não encontrado.'
        );
    }

    $source = (string)file_get_contents($path);

    if (
        str_contains(
            $source,
            'v0.52.1 - cores personalizadas do TinyMCE'
        )
    ) {
        out(
            '[OK] '
            . $label
            . ' já possui cores personalizadas.'
        );
        return;
    }

    if (!str_contains($source, 'tinymce.init({')) {
        throw new RuntimeException(
            'TinyMCE não foi localizado em '
            . $label
            . '.'
        );
    }

    $original = $source;

    /*
     * Adiciona os controles no mesmo grupo de formatação.
     * Suporta as duas formas atuais usadas no Portal.
     */
    $replacements = [
        "| bold italic |"
            => "| bold italic forecolor backcolor |",
        "| bold italic underline |"
            => "| bold italic underline forecolor backcolor |",
    ];

    $toolbarChanged = false;

    foreach ($replacements as $before => $after) {
        if (
            !$toolbarChanged
            && str_contains($source, $before)
            && !str_contains($source, 'forecolor')
        ) {
            $source = str_replace(
                $before,
                $after,
                $source,
                $count
            );

            if ($count > 0) {
                $toolbarChanged = true;
            }
        }
    }

    if (
        !$toolbarChanged
        && !str_contains($source, 'forecolor')
    ) {
        throw new RuntimeException(
            'Não foi possível localizar a barra do TinyMCE em '
            . $label
            . '.'
        );
    }

    /*
     * Inserimos a configuração logo após a linha toolbar.
     * color_map define a paleta rápida e custom_colors mantém o
     * seletor para qualquer cor HEX/RGB escolhida pelo administrador.
     */
    $toolbarPattern =
        '/^(\s*toolbar\s*:\s*[\'"][^\r\n]+[\'"]\s*,\s*)$/m';

    if (!preg_match($toolbarPattern, $source, $matches)) {
        throw new RuntimeException(
            'Não foi possível localizar a configuração toolbar em '
            . $label
            . '.'
        );
    }

    $colorConfig = <<<'JS'
    // v0.52.1 - cores personalizadas do TinyMCE
    color_cols: 8,
    custom_colors: true,
    color_map: [
        '000000', 'Preto',
        '333333', 'Cinza escuro',
        '666666', 'Cinza',
        '999999', 'Cinza claro',
        'FFFFFF', 'Branco',
        'B91C1C', 'Vermelho',
        'DC2626', 'Vermelho vivo',
        'EA580C', 'Laranja',
        'F59E0B', 'Âmbar',
        'FACC15', 'Amarelo',
        '15803D', 'Verde',
        '16A34A', 'Verde vivo',
        '0F766E', 'Verde petróleo',
        '0369A1', 'Azul',
        '2563EB', 'Azul vivo',
        '4F46E5', 'Índigo',
        '7E22CE', 'Roxo',
        'C026D3', 'Magenta',
        'BE185D', 'Rosa',
        '7C2D12', 'Marrom'
    ],
JS;

    $source = preg_replace(
        $toolbarPattern,
        '$1' . "\n" . $colorConfig,
        $source,
        1,
        $configCount
    ) ?? $source;

    if ($configCount !== 1) {
        throw new RuntimeException(
            'Não foi possível adicionar a paleta de cores em '
            . $label
            . '.'
        );
    }

    if ($source === $original) {
        out('[OK] ' . $label . ' não precisou de alterações.');
        return;
    }

    writeChanged(
        $path,
        $source,
        $label
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
            "define('APP_VERSION', '"
            . TARGET_VERSION
            . "');",
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
        out(
            '[OK] APP_VERSION já é '
            . TARGET_VERSION
            . '.'
        );
        return;
    }

    writeChanged(
        $path,
        $source,
        'config/config.php'
    );
}

out(
    'Portal IECLB Parobé - atualização v'
    . TARGET_VERSION
);
out('TinyMCE: cor do texto e cor de fundo personalizadas');
out(str_repeat('-', 82));

$config = $root . '/config/config.php';

$required = [
    $config,
    $root . '/admin/noticias/form.php',
    $root . '/admin/paginas/form.php',
];

foreach ($required as $file) {
    if (!is_file($file)) {
        fail(
            'Arquivo obrigatório não encontrado: '
            . str_replace(
                $root . '/',
                '',
                $file
            )
        );
    }
}

require_once $config;

$current = defined('APP_VERSION')
    ? (string)APP_VERSION
    : '0.0.0';

out('Versão identificada: ' . $current);

if (
    version_compare(
        $current,
        MIN_VERSION,
        '<'
    )
) {
    fail(
        'A v'
        . TARGET_VERSION
        . ' requer Portal v'
        . MIN_VERSION
        . ' ou superior.'
    );
}

try {
    patchTinyMceColors(
        $root . '/admin/noticias/form.php',
        'admin/noticias/form.php'
    );

    patchTinyMceColors(
        $root . '/admin/paginas/form.php',
        'admin/paginas/form.php'
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

    out(str_repeat('-', 82));
    out(
        '[OK] Atualização v'
        . TARGET_VERSION
        . ' concluída.'
    );
    out('[OK] TinyMCE de Posts/Notícias: cor do texto ativada.');
    out('[OK] TinyMCE de Posts/Notícias: cor de fundo ativada.');
    out('[OK] TinyMCE de Páginas: cor do texto ativada.');
    out('[OK] TinyMCE de Páginas: cor de fundo ativada.');
    out('[OK] Paleta rápida com 20 cores adicionada.');
    out('[OK] Seletor de cor personalizada permanece habilitado.');
    out('Faça Ctrl+F5 antes de abrir novamente o editor.');

    if (is_dir($backupDir)) {
        out(
            'Backups: '
            . str_replace(
                '\\',
                '/',
                $backupDir
            )
        );
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
