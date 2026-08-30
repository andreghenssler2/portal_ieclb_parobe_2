<?php

declare(strict_types=1);

/**
 * Portal IECLB Parobé - suíte leve de qualidade.
 *
 * Não usa banco de dados, não inicia sessão e não carrega bootstrap.php.
 * Pode ser executada localmente e no GitHub Actions.
 *
 * Uso:
 *   php tests/run.php
 *
 * Saída:
 *   exit 0 = todos os testes passaram
 *   exit 1 = existe falha que deve impedir o deploy
 */

$root = dirname(__DIR__);
$failures = [];
$warnings = [];
$passes = 0;

function testPass(string $message): void
{
    global $passes;
    $passes++;
    echo "[OK] {$message}\n";
}

function testFail(string $message): void
{
    global $failures;
    $failures[] = $message;
    echo "[FALHA] {$message}\n";
}

function testWarn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
    echo "[AVISO] {$message}\n";
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(
        normalizePath($root),
        '/'
    ) . '/';

    $path = normalizePath($path);

    if (str_starts_with($path, $root)) {
        return substr(
            $path,
            strlen($root)
        );
    }

    return $path;
}

function shouldSkipPath(string $relative): bool
{
    $relative = '/' . ltrim(
        normalizePath($relative),
        '/'
    );

    foreach (
        [
            '/.git/',
            '/lib/',
            '/vendor/',
            '/storage/',
            '/uploads/',
            '/node_modules/',
            '/_update_payload',
        ]
        as $needle
    ) {
        if (str_contains($relative, $needle)) {
            return true;
        }
    }

    /*
     * Instaladores históricos não fazem parte do runtime do Portal.
     * Eles são validados quando seus próprios pacotes são gerados.
     */
    $base = basename($relative);

    if (
        preg_match(
            '/^(?:atualizar|corrigir|reverter)_v.*\.php$/i',
            $base
        )
    ) {
        return true;
    }

    return false;
}

/**
 * Retorna arquivos recursivamente por extensão.
 *
 * @param string[] $extensions
 * @return string[]
 */
function collectFiles(
    string $root,
    array $extensions
): array {
    $files = [];

    $iterator =
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            )
        );

    foreach ($iterator as $item) {
        if (
            !$item->isFile()
            || $item->isLink()
        ) {
            continue;
        }

        $path =
            $item->getPathname();

        $relative =
            relativePath(
                $root,
                $path
            );

        if (shouldSkipPath($relative)) {
            continue;
        }

        $extension =
            strtolower(
                $item->getExtension()
            );

        if (
            in_array(
                $extension,
                $extensions,
                true
            )
        ) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

function parsePhpFile(
    string $file
): ?string {
    $content =
        file_get_contents($file);

    if ($content === false) {
        return 'não foi possível ler o arquivo';
    }

    try {
        token_get_all(
            $content,
            TOKEN_PARSE
        );

        return null;
    } catch (ParseError $e) {
        return $e->getMessage();
    }
}

function balancedCss(string $content): bool
{
    $withoutComments =
        preg_replace(
            '~\/\*.*?\*\/~s',
            '',
            $content
        );

    if (!is_string($withoutComments)) {
        return false;
    }

    return substr_count(
        $withoutComments,
        '{'
    ) === substr_count(
        $withoutComments,
        '}'
    );
}

echo "Portal IECLB Parobé - testes de qualidade\n";
echo str_repeat('=', 62) . "\n\n";

/*
|--------------------------------------------------------------------------
| 1. Plataforma mínima
|--------------------------------------------------------------------------
*/

if (
    version_compare(
        PHP_VERSION,
        '8.2.0',
        '>='
    )
) {
    testPass(
        'PHP '
        . PHP_VERSION
        . ' atende ao requisito >= 8.2.'
    );
} else {
    testFail(
        'PHP '
        . PHP_VERSION
        . ' é inferior ao requisito >= 8.2.'
    );
}

/*
|--------------------------------------------------------------------------
| 2. Estrutura essencial
|--------------------------------------------------------------------------
*/

$requiredFiles = [
    'bootstrap.php',
    'router.php',
    'index.php',
    'admin/index.php',
    'admin/_header.php',
    'admin/_footer.php',
    'app/Helpers/functions.php',
    'mod/db/Database.php',
    'mod/auth/Auth.php',
    'mod/auth/Session.php',
    'mod/security/Csrf.php',
    'config/config.example.php',
    'composer.json',
];

$missing = [];

foreach ($requiredFiles as $relative) {
    if (
        !is_file(
            $root
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            )
        )
    ) {
        $missing[] = $relative;
    }
}

if (!$missing) {
    testPass(
        'Estrutura essencial do Portal encontrada.'
    );
} else {
    foreach ($missing as $relative) {
        testFail(
            "Arquivo essencial ausente: {$relative}"
        );
    }
}

/*
|--------------------------------------------------------------------------
| 3. Sintaxe PHP sem executar código
|--------------------------------------------------------------------------
*/

$phpFiles =
    collectFiles(
        $root,
        ['php']
    );

$phpErrors = 0;

foreach ($phpFiles as $file) {
    $error =
        parsePhpFile($file);

    if ($error !== null) {
        $phpErrors++;

        testFail(
            'Sintaxe PHP em '
            . relativePath(
                $root,
                $file
            )
            . ': '
            . $error
        );
    }
}

if ($phpErrors === 0) {
    testPass(
        count($phpFiles)
        . ' arquivo(s) PHP analisado(s) sem erro de sintaxe.'
    );
}

/*
|--------------------------------------------------------------------------
| 4. composer.json
|--------------------------------------------------------------------------
*/

$composerFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'composer.json';

if (is_file($composerFile)) {
    $composerRaw =
        file_get_contents(
            $composerFile
        );

    $composer =
        is_string($composerRaw)
            ? json_decode(
                $composerRaw,
                true
            )
            : null;

    if (!is_array($composer)) {
        testFail(
            'composer.json não contém JSON válido.'
        );
    } else {
        $phpRequirement =
            (string)(
                $composer['require']['php']
                ?? ''
            );

        if ($phpRequirement === '') {
            testFail(
                'composer.json não declara requisito de PHP.'
            );
        } else {
            testPass(
                'composer.json válido; requisito PHP: '
                . $phpRequirement
                . '.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| 5. bootstrap.php: require_once simples apontam para arquivos existentes
|--------------------------------------------------------------------------
*/

$bootstrapFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

if (is_file($bootstrapFile)) {
    $bootstrap =
        (string)file_get_contents(
            $bootstrapFile
        );

    preg_match_all(
        '~require_once\s+__DIR__\s*\.\s*[\'"](/[^\'"]+)[\'"]\s*;~',
        $bootstrap,
        $matches
    );

    $brokenRequires = [];

    foreach (
        $matches[1] ?? []
        as $required
    ) {
        $relative =
            ltrim(
                (string)$required,
                '/'
            );

        $target =
            $root
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            );

        /*
         * config/config.php é propositalmente ignorado pelo Git.
         * Em CI, config.example.php é suficiente para validar a estrutura.
         */
        if (
            $relative === 'config/config.php'
            && !is_file($target)
        ) {
            $example =
                $root
                . DIRECTORY_SEPARATOR
                . 'config'
                . DIRECTORY_SEPARATOR
                . 'config.example.php';

            if (is_file($example)) {
                continue;
            }
        }

        if (!is_file($target)) {
            $brokenRequires[] =
                $relative;
        }
    }

    if (!$brokenRequires) {
        testPass(
            'Todos os require_once estáticos do bootstrap apontam para arquivos disponíveis.'
        );
    } else {
        foreach ($brokenRequires as $relative) {
            testFail(
                "bootstrap.php referencia arquivo inexistente: {$relative}"
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| 6. Marcadores de conflito do Git
|--------------------------------------------------------------------------
*/

$textFiles =
    collectFiles(
        $root,
        [
            'php',
            'css',
            'js',
            'json',
            'md',
            'yml',
            'yaml',
            'sql',
        ]
    );

$conflictFiles = [];

foreach ($textFiles as $file) {
    $content =
        file_get_contents($file);

    if (
        is_string($content)
        && preg_match(
            '/^(?:<<<<<<< |=======\s*$|>>>>>>> )/m',
            $content
        )
    ) {
        $conflictFiles[] =
            relativePath(
                $root,
                $file
            );
    }
}

if (!$conflictFiles) {
    testPass(
        'Nenhum marcador de conflito do Git encontrado.'
    );
} else {
    foreach ($conflictFiles as $relative) {
        testFail(
            "Marcador de conflito Git encontrado em {$relative}."
        );
    }
}

/*
|--------------------------------------------------------------------------
| 7. CSS administrativo consolidado da v0.64
|--------------------------------------------------------------------------
*/

$adminCss =
    $root
    . DIRECTORY_SEPARATOR
    . 'public'
    . DIRECTORY_SEPARATOR
    . 'css'
    . DIRECTORY_SEPARATOR
    . 'admin-v64.css';

$adminHeader =
    $root
    . DIRECTORY_SEPARATOR
    . 'admin'
    . DIRECTORY_SEPARATOR
    . '_header.php';

if (!is_file($adminCss)) {
    testFail(
        'public/css/admin-v64.css não existe. A v0.64 precisa estar concluída.'
    );
} else {
    $content =
        (string)file_get_contents(
            $adminCss
        );

    if (
        trim($content) !== ''
        && balancedCss($content)
    ) {
        testPass(
            'admin-v64.css existe e possui blocos CSS balanceados.'
        );
    } else {
        testFail(
            'admin-v64.css parece vazio ou possui chaves desbalanceadas.'
        );
    }
}

if (is_file($adminHeader)) {
    $header =
        (string)file_get_contents(
            $adminHeader
        );

    $newCount =
        substr_count(
            $header,
            'public/css/admin-v64.css'
        );

    if ($newCount === 1) {
        testPass(
            'admin/_header.php carrega admin-v64.css exatamente uma vez.'
        );
    } else {
        testFail(
            'admin/_header.php deve carregar admin-v64.css exatamente uma vez; encontrado: '
            . $newCount
            . '.'
        );
    }

    $legacyCss = [
        'public/css/admin.css',
        'public/css/admin-menu-v34.css',
        'public/css/admin-responsive-v52.css',
        'public/css/admin-sidebar-scroll-fix-v54.css',
    ];

    $legacyRefs = [];

    foreach ($legacyCss as $legacy) {
        if (
            str_contains(
                $header,
                $legacy
            )
        ) {
            $legacyRefs[] =
                $legacy;
        }
    }

    if (!$legacyRefs) {
        testPass(
            'Header não carrega mais os CSS administrativos legados.'
        );
    } else {
        foreach ($legacyRefs as $legacy) {
            testFail(
                "Header ainda carrega CSS legado: {$legacy}"
            );
        }
    }

    if (
        preg_match(
            '~^[ \t]*">\s*$~m',
            $header
        )
    ) {
        testFail(
            'admin/_header.php contém resíduo "> isolado da primeira v0.64.'
        );
    } else {
        testPass(
            'Nenhum resíduo "> isolado encontrado no header.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| 8. Funções/serviços críticos presentes no código-fonte
|--------------------------------------------------------------------------
*/

$sourceAssertions = [
    'app/Helpers/functions.php' => [
        'function contentUrl',
        'function routeSlug',
        'function permalinkPrefix',
    ],

    'app/Services/EditorialWorkflowService.php' => [
        'final class EditorialWorkflowService',
        'function contentHash',
    ],

    'app/Services/TwoFactorService.php' => [
        'final class TwoFactorService',
        'function verifyUserCode',
    ],

    'app/Services/AdminPendingService.php' => [
        'final class AdminPendingService',
        'function overview',
    ],
];

foreach ($sourceAssertions as $relative => $needles) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );

    if (!is_file($file)) {
        testFail(
            "Componente crítico ausente: {$relative}"
        );

        continue;
    }

    $content =
        (string)file_get_contents(
            $file
        );

    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            testFail(
                "{$relative} não contém a assinatura esperada: {$needle}"
            );
        }
    }
}

if (
    !array_filter(
        $failures,
        static fn(string $failure): bool =>
            str_contains(
                $failure,
                'assinatura esperada'
            )
            || str_contains(
                $failure,
                'Componente crítico ausente'
            )
    )
) {
    testPass(
        'Componentes críticos do Portal possuem as assinaturas esperadas.'
    );
}

/*
|--------------------------------------------------------------------------
| 9. Configuração de exemplo
|--------------------------------------------------------------------------
*/

$configExample =
    $root
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'config.example.php';

if (is_file($configExample)) {
    $configContent =
        (string)file_get_contents(
            $configExample
        );

    if (
        str_contains(
            $configContent,
            "define('APP_ENV', 'development')"
        )
    ) {
        testPass(
            'config.example.php permanece explicitamente em ambiente development.'
        );
    } else {
        testWarn(
            'config.example.php não declara APP_ENV=development no formato esperado.'
        );
    }

    if (
        preg_match(
            "~define\\(['\"]DB_PASS['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\)~",
            $configContent,
            $dbPass
        )
        && trim(
            (string)($dbPass[1] ?? '')
        ) !== ''
    ) {
        testFail(
            'config.example.php não deve conter senha de banco preenchida.'
        );
    } else {
        testPass(
            'config.example.php não contém senha de banco preenchida.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Resultado
|--------------------------------------------------------------------------
*/

echo "\n";
echo str_repeat('=', 62) . "\n";
echo "RESULTADO\n";
echo str_repeat('=', 62) . "\n";
echo "Testes OK: {$passes}\n";
echo "Avisos: " . count($warnings) . "\n";
echo "Falhas: " . count($failures) . "\n";

if ($warnings) {
    echo "\nAvisos:\n";

    foreach ($warnings as $warning) {
        echo " - {$warning}\n";
    }
}

if ($failures) {
    echo "\nFalhas que impedem o deploy:\n";

    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }

    exit(1);
}

echo "\n[SUCESSO] Qualidade básica aprovada para deploy.\n";
exit(0);
