<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;

function daNorm(string $path): string
{
    return str_replace('\\', '/', $path);
}

function daRelative(string $root, string $file): string
{
    $realRoot = daNorm(realpath($root) ?: $root);
    $realFile = daNorm(realpath($file) ?: $file);
    $realRoot = rtrim($realRoot, '/');

    if (str_starts_with($realFile, $realRoot . '/')) {
        return substr($realFile, strlen($realRoot) + 1);
    }

    return $realFile;
}

function daSkip(string $relative): bool
{
    $relative = '/' . ltrim(daNorm($relative), '/');

    foreach (
        [
            '/.git/',
            '/vendor/',
            '/lib/',
            '/storage/',
            '/tests/',
            '/tools/',
            '/node_modules/',
            '/_update_payload_',
        ]
        as $needle
    ) {
        if (str_contains($relative, $needle)) {
            return true;
        }
    }

    $base = basename($relative);

    return (bool)preg_match(
        '/^(atualizar|diagnosticar|auditar|instalar|installer|update|repair|reparo)[^\/]*\.php$/i',
        $base
    );
}

/** @return array<int,string> */
function daPhpFiles(string $root): array
{
    $files = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        if (strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $relative = daRelative($root, $item->getPathname());

        if (!daSkip($relative)) {
            $files[] = $item->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * Retorna classes e funções/métodos com escopo correto.
 *
 * O auditor anterior confundia métodos de classes diferentes (por exemplo
 * GroupService::save e LeadershipService::save) como uma única função "save".
 *
 * @return array{classes:array<int,array<string,mixed>>,functions:array<int,array<string,mixed>>}
 */
function daSymbols(string $code, string $relative): array
{
    $tokens = token_get_all($code, TOKEN_PARSE);
    $classes = [];
    $functions = [];

    $namespace = '';
    $pendingClass = null;
    $classStack = [];
    $braceDepth = 0;
    $count = count($tokens);

    $classTokens = [
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
    ];

    if (defined('T_ENUM')) {
        $classTokens[] = T_ENUM;
    }

    $meaningfulNext = static function (array $tokens, int $start): ?int {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (
                is_array($token)
                && in_array(
                    $token[0],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )
            ) {
                continue;
            }

            return $i;
        }

        return null;
    };

    $meaningfulPrev = static function (array $tokens, int $start): ?int {
        for ($i = $start; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (
                is_array($token)
                && in_array(
                    $token[0],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )
            ) {
                continue;
            }

            return $i;
        }

        return null;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_NAMESPACE) {
            $parts = '';

            for ($j = $i + 1; $j < $count; $j++) {
                $part = $tokens[$j];

                if ($part === ';' || $part === '{') {
                    break;
                }

                if (
                    is_array($part)
                    && in_array(
                        $part[0],
                        [
                            T_STRING,
                            T_NS_SEPARATOR,
                            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1,
                        ],
                        true
                    )
                ) {
                    $parts .= $part[1];
                }
            }

            $namespace = trim($parts, '\\');
            continue;
        }

        if (
            is_array($token)
            && in_array($token[0], $classTokens, true)
        ) {
            $prev = $meaningfulPrev($tokens, $i - 1);

            if (
                $token[0] === T_CLASS
                && $prev !== null
                && is_array($tokens[$prev])
                && $tokens[$prev][0] === T_NEW
            ) {
                continue;
            }

            $nameIndex = $meaningfulNext($tokens, $i + 1);

            if (
                $nameIndex !== null
                && is_array($tokens[$nameIndex])
                && $tokens[$nameIndex][0] === T_STRING
            ) {
                $name = (string)$tokens[$nameIndex][1];

                $pendingClass = [
                    'name' => $name,
                    'fqn' => $namespace !== ''
                        ? $namespace . '\\' . $name
                        : $name,
                    'file' => $relative,
                    'line' => (int)$token[2],
                    'kind' => token_name($token[0]),
                ];

                $classes[] = $pendingClass;
            }

            continue;
        }

        if ($token === '{') {
            $braceDepth++;

            if ($pendingClass !== null) {
                $pendingClass['depth'] = $braceDepth;
                $classStack[] = $pendingClass;
                $pendingClass = null;
            }

            continue;
        }

        if ($token === '}') {
            if ($classStack) {
                $top = $classStack[array_key_last($classStack)];

                if ((int)($top['depth'] ?? -1) === $braceDepth) {
                    array_pop($classStack);
                }
            }

            $braceDepth = max(0, $braceDepth - 1);
            continue;
        }

        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $nameIndex = $meaningfulNext($tokens, $i + 1);

        if ($nameIndex !== null && $tokens[$nameIndex] === '&') {
            $nameIndex = $meaningfulNext($tokens, $nameIndex + 1);
        }

        if (
            $nameIndex === null
            || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING
        ) {
            continue;
        }

        $name = (string)$tokens[$nameIndex][1];
        $class = $classStack
            ? $classStack[array_key_last($classStack)]
            : null;

        $symbol = $class
            ? (string)$class['fqn'] . '::' . $name
            : (
                $namespace !== ''
                    ? $namespace . '\\' . $name
                    : $name
            );

        $functions[] = [
            'name' => $name,
            'symbol' => $symbol,
            'scope' => $class ? 'method' : 'function',
            'class' => $class['fqn'] ?? null,
            'file' => $relative,
            'line' => (int)$token[2],
        ];
    }

    return [
        'classes' => $classes,
        'functions' => $functions,
    ];
}

/** @return array<int,array<string,mixed>> */
function daExactDuplicateFiles(string $root, array $files): array
{
    $groups = [];

    foreach ($files as $file) {
        $hash = hash_file('sha256', $file);

        if (!is_string($hash) || $hash === '') {
            continue;
        }

        $groups[$hash][] = [
            'file' => daRelative($root, $file),
            'size' => (int)filesize($file),
        ];
    }

    $out = [];

    foreach ($groups as $hash => $items) {
        if (count($items) > 1) {
            $out[] = [
                'sha256' => $hash,
                'items' => $items,
            ];
        }
    }

    usort(
        $out,
        static fn(array $a, array $b): int =>
            count($b['items']) <=> count($a['items'])
    );

    return $out;
}

$files = daPhpFiles($root);
$classes = [];
$functions = [];
$parseErrors = [];

foreach ($files as $file) {
    $relative = daRelative($root, $file);
    $code = file_get_contents($file);

    if (!is_string($code)) {
        $parseErrors[] = $relative . ': leitura falhou';
        continue;
    }

    try {
        $symbols = daSymbols($code, $relative);
        array_push($classes, ...$symbols['classes']);
        array_push($functions, ...$symbols['functions']);
    } catch (ParseError $e) {
        $parseErrors[] = $relative . ': ' . $e->getMessage();
    }
}

$classGroups = [];

foreach ($classes as $class) {
    $classGroups[strtolower((string)$class['fqn'])][] = $class;
}

$duplicateClasses = array_values(
    array_filter(
        $classGroups,
        static fn(array $group): bool => count($group) > 1
    )
);

$symbolGroups = [];

foreach ($functions as $function) {
    $symbolGroups[strtolower((string)$function['symbol'])][] = $function;
}

$duplicateSymbols = array_values(
    array_filter(
        $symbolGroups,
        static fn(array $group): bool => count($group) > 1
    )
);

$exactDuplicates = daExactDuplicateFiles($root, $files);

$legacyCandidates = [
    'SessionSecurityService_v0.83.0.php',
    'app/Services/ContentAutosaveServiceV84R3.php',
    'app/Services/UserActivityServiceV87R2.php',
];

echo "Portal IECLB Parobé - Auditoria corrigida de duplicidades v1.2.0 R3\n";
echo str_repeat('=', 88) . "\n";
echo '[INFO] Arquivos PHP ativos analisados: ' . count($files) . "\n";
echo '[INFO] Classes: ' . count($classes) . "\n";
echo '[INFO] Funções/métodos nomeados: ' . count($functions) . "\n\n";

echo "1. DECLARAÇÕES REALMENTE DUPLICADAS\n";
echo str_repeat('-', 88) . "\n";

if (!$duplicateClasses && !$duplicateSymbols) {
    echo "[OK] Nenhuma declaração duplicada encontrada.\n";
} else {
    foreach ($duplicateClasses as $group) {
        echo '[CRÍTICO] Classe duplicada: ' . (string)$group[0]['fqn'] . "\n";

        foreach ($group as $item) {
            echo '  - ' . (string)$item['file'] . ':' . (int)$item['line'] . "\n";
        }
    }

    foreach ($duplicateSymbols as $group) {
        echo '[CRÍTICO] Símbolo duplicado: ' . (string)$group[0]['symbol'] . "\n";

        foreach ($group as $item) {
            echo '  - ' . (string)$item['file'] . ':' . (int)$item['line'] . "\n";
        }
    }
}

echo "\n2. ARQUIVOS PHP EXATAMENTE IGUAIS\n";
echo str_repeat('-', 88) . "\n";

if (!$exactDuplicates) {
    echo "[OK] Nenhum arquivo PHP ativo possui conteúdo exatamente igual a outro.\n";
} else {
    foreach ($exactDuplicates as $group) {
        echo '[REVISAR] SHA-256 ' . substr((string)$group['sha256'], 0, 16) . "…\n";

        foreach ((array)$group['items'] as $item) {
            echo '  - ' . (string)$item['file'] . ' (' . (int)$item['size'] . " bytes)\n";
        }
    }
}

echo "\n3. SOBRAS DE REVISÕES CONHECIDAS\n";
echo str_repeat('-', 88) . "\n";

$legacyFound = 0;

foreach ($legacyCandidates as $relative) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (is_file($file)) {
        echo "[REVISAR] {$relative}\n";
        $legacyFound++;
    } else {
        echo "[OK] ausente: {$relative}\n";
    }
}

echo "\n4. PARSE\n";
echo str_repeat('-', 88) . "\n";

if (!$parseErrors) {
    echo "[OK] Nenhum erro de parse.\n";
} else {
    foreach ($parseErrors as $error) {
        echo "[ERRO] {$error}\n";
    }
}

$critical = count($duplicateClasses)
    + count($duplicateSymbols)
    + count($parseErrors);

echo "\n" . str_repeat('=', 88) . "\n";
echo '[RESUMO] Críticos reais: ' . $critical . "\n";
echo '[RESUMO] Grupos de arquivos idênticos para revisão: ' . count($exactDuplicates) . "\n";
echo '[RESUMO] Sobras versionadas conhecidas: ' . $legacyFound . "\n";

if ($critical === 0) {
    echo "RESULTADO: nenhuma declaração duplicada real detectada.\n";
} else {
    echo "RESULTADO: ainda existem declarações duplicadas reais.\n";
}

exit($critical > 0 ? 2 : 0);
