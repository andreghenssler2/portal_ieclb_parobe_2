<?php

declare(strict_types=1);

const TARGET_VERSION = '0.51.0';
const MIN_VERSION = '0.50.0';

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
            substr(
                $path,
                strlen($root)
            )
        ),
        '/'
    );

    $target = $backupDir . '/' . $relative;

    if (
        !is_dir(dirname($target))
        && !mkdir(
            dirname($target),
            0755,
            true
        )
        && !is_dir(dirname($target))
    ) {
        throw new RuntimeException(
            'Não foi possível criar backup de '
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
    $command = escapeshellarg(
        PHP_BINARY ?: 'php'
    )
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1';

    $lines = [];
    $code = 1;

    exec(
        $command,
        $lines,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            basename($path)
            . " não passou no php -l:\n"
            . implode(
                PHP_EOL,
                $lines
            )
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
        out(
            '[OK] '
            . $label
            . ' já estava atualizado.'
        );
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

    if (
        str_ends_with(
            strtolower($path),
            '.php'
        )
    ) {
        lintPhp($path);
    }

    out(
        '[OK] '
        . $label
        . ' atualizado.'
    );
}

function patchThemeHeader(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'theme/ieclb/header.php não encontrado.'
        );
    }

    $source = (string)file_get_contents(
        $path
    );

    if (
        str_contains(
            $source,
            'v0.51.0 - responsividade global'
        )
    ) {
        out(
            '[OK] Cabeçalho público já carrega a responsividade v0.51.0.'
        );
        return;
    }

    $anchor = <<<'PHP'
    <?php if ($activeThemeStyle): ?><link rel="stylesheet" href="<?= e($activeThemeStyle) ?>"><?php endif; ?>
PHP;

    if (!str_contains($source, $anchor)) {
        throw new RuntimeException(
            'Não foi possível localizar o carregamento do CSS do tema em theme/ieclb/header.php.'
        );
    }

    $addition = <<<'PHP'
    <?php if ($activeThemeStyle): ?><link rel="stylesheet" href="<?= e($activeThemeStyle) ?>"><?php endif; ?>
    <?php /* v0.51.0 - responsividade global */ ?>
    <link rel="stylesheet" href="<?= e(url('public/css/responsive-v51.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.51.0'))) ?>">
PHP;

    $source = str_replace(
        $anchor,
        $addition,
        $source,
        $count
    );

    if ($count !== 1) {
        throw new RuntimeException(
            'Estrutura inesperada ao atualizar o cabeçalho público.'
        );
    }

    writeChanged(
        $path,
        $source,
        'theme/ieclb/header.php'
    );
}

function patchHomeModular(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'public/home-modular.php não encontrado.'
        );
    }

    $source = (string)file_get_contents(
        $path
    );

    if (
        str_contains(
            $source,
            'v0.51.0 - responsividade da Home modular'
        )
    ) {
        out(
            '[OK] Home modular já carrega a responsividade v0.51.0.'
        );
        return;
    }

    $anchor = <<<'PHP'
<link rel="stylesheet" href="<?= e(url('public/css/home-modular.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.29.0'))) ?>">
PHP;

    if (!str_contains($source, $anchor)) {
        throw new RuntimeException(
            'Não foi possível localizar home-modular.css em public/home-modular.php.'
        );
    }

    $addition = <<<'PHP'
<link rel="stylesheet" href="<?= e(url('public/css/home-modular.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.29.0'))) ?>">
<?php /* v0.51.0 - responsividade da Home modular */ ?>
<link rel="stylesheet" href="<?= e(url('public/css/home-responsive-v51.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.51.0'))) ?>">
PHP;

    $source = str_replace(
        $anchor,
        $addition,
        $source,
        $count
    );

    if ($count !== 1) {
        throw new RuntimeException(
            'Estrutura inesperada ao atualizar a Home modular.'
        );
    }

    writeChanged(
        $path,
        $source,
        'public/home-modular.php'
    );
}

function updateVersion(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(
            'config/config.php não encontrado.'
        );
    }

    $source = (string)file_get_contents(
        $path
    );
    $original = $source;

    $pattern =
        "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (
        preg_match(
            $pattern,
            $source
        )
    ) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '"
            . TARGET_VERSION
            . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $anchor =
            'declare(strict_types=1);';

        if (
            !str_contains(
                $source,
                $anchor
            )
        ) {
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
out(
    'Responsividade geral do site público'
);
out(
    str_repeat(
        '-',
        80
    )
);

$config =
    $root
    . '/config/config.php';

$required = [
    $config,
    $root
        . '/theme/ieclb/header.php',
    $root
        . '/public/home-modular.php',
    $root
        . '/public/css/responsive-v51.css',
    $root
        . '/public/css/home-responsive-v51.css',
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

$current = defined(
    'APP_VERSION'
)
    ? (string)APP_VERSION
    : '0.0.0';

out(
    'Versão identificada: '
    . $current
);

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
    patchThemeHeader(
        $root
        . '/theme/ieclb/header.php'
    );

    patchHomeModular(
        $root
        . '/public/home-modular.php'
    );

    updateVersion($config);

    if (
        class_exists(
            'CacheService'
        )
    ) {
        try {
            CacheService::clearGroup(
                'page'
            );
            CacheService::clearGroup(
                'public'
            );
        } catch (Throwable $ignored) {
        }
    }

    if (
        function_exists(
            'opcache_reset'
        )
    ) {
        @opcache_reset();
    }

    out(
        str_repeat(
            '-',
            80
        )
    );
    out(
        '[OK] Atualização v'
        . TARGET_VERSION
        . ' concluída.'
    );
    out(
        '[OK] Cabeçalho/menu adaptados para tablet e celular.'
    );
    out(
        '[OK] Imagens de Posts/Páginas não ultrapassam mais o conteúdo.'
    );
    out(
        '[OK] Tabelas e iframes passam a respeitar o viewport.'
    );
    out(
        '[OK] Home Destaque + 2 empilha corretamente no celular.'
    );
    out(
        '[OK] Grade e carrossel da Home foram ajustados para telas pequenas.'
    );
    out(
        '[OK] Cards meia-largura passam a empilhar em celulares estreitos.'
    );
    out(
        '[OK] Blocos dinâmicos, galeria, vídeo e FAQ recebem ajustes móveis.'
    );
    out(
        'Faça Ctrl+F5 e teste novamente nas larguras 390px, 768px e 1024px.'
    );

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
    fail(
        $e->getMessage()
    );
}
