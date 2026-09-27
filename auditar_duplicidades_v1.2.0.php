<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute esta auditoria pelo terminal.\n");
}

$root = __DIR__;

function dupNormPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function dupRelative(string $root, string $file): string
{
    $root = rtrim(dupNormPath(realpath($root) ?: $root), '/');
    $file = dupNormPath(realpath($file) ?: $file);

    if (str_starts_with($file, $root . '/')) {
        return substr($file, strlen($root) + 1);
    }

    return $file;
}

function dupShouldSkip(string $relative): bool
{
    $relative = '/' . ltrim(dupNormPath($relative), '/');

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

    if (
        preg_match(
            '/^(atualizar|diagnosticar|instalar|installer|update|repair|reparo)[^\/]*\.php$/i',
            $base
        )
    ) {
        return true;
    }

    return false;
}

/**
 * @return array<int,string>
 */
function dupPhpFiles(string $root): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        if (strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $relative = dupRelative(
            $root,
            $item->getPathname()
        );

        if (dupShouldSkip($relative)) {
            continue;
        }

        $files[] = $item->getPathname();
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * @param array<int,mixed> $tokens
 * @return array<int,int>
 */
function dupBraceMap(array $tokens): array
{
    $stack = [];
    $map = [];

    foreach ($tokens as $i => $token) {
        if ($token === '{') {
            $stack[] = $i;
            continue;
        }

        if ($token === '}') {
            $open = array_pop($stack);

            if (is_int($open)) {
                $map[$open] = $i;
                $map[$i] = $open;
            }
        }
    }

    return $map;
}

/**
 * @param array<int,mixed> $tokens
 */
function dupNextMeaningful(array $tokens, int $start): ?int
{
    $count = count($tokens);

    for ($i = $start; $i < $count; $i++) {
        $token = $tokens[$i];

        if (
            is_array($token)
            && in_array(
                $token[0],
                [
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                ],
                true
            )
        ) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 */
function dupPrevMeaningful(array $tokens, int $start): ?int
{
    for ($i = $start; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (
            is_array($token)
            && in_array(
                $token[0],
                [
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                ],
                true
            )
        ) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 */
function dupLine(array $tokens, int $index): int
{
    for ($i = $index; $i >= 0; $i--) {
        if (is_array($tokens[$i])) {
            return (int)$tokens[$i][2];
        }
    }

    return 1;
}

/**
 * @param array<int,mixed> $tokens
 */
function dupNamespaceAt(array $tokens, int $index): string
{
    $namespace = '';
    $current = '';

    for ($i = 0; $i < $index; $i++) {
        $token = $tokens[$i];

        if (
            is_array($token)
            && $token[0] === T_NAMESPACE
        ) {
            $current = '';

            for ($j = $i + 1; $j < count($tokens); $j++) {
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
                    $current .= $part[1];
                }
            }

            $namespace = trim($current, '\\');
        }
    }

    return $namespace;
}

/**
 * @param array<int,mixed> $tokens
 * @return array<int,array<string,mixed>>
 */
function dupClasses(
    array $tokens,
    array $braceMap,
    string $relative
): array {
    $classes = [];
    $count = count($tokens);

    $classTokens = [
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
    ];

    if (defined('T_ENUM')) {
        $classTokens[] = T_ENUM;
    }

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (
            !is_array($token)
            || !in_array($token[0], $classTokens, true)
        ) {
            continue;
        }

        $prev = dupPrevMeaningful($tokens, $i - 1);

        if (
            $token[0] === T_CLASS
            && $prev !== null
            && is_array($tokens[$prev])
            && $tokens[$prev][0] === T_NEW
        ) {
            continue;
        }

        $nameIndex = dupNextMeaningful(
            $tokens,
            $i + 1
        );

        if (
            $nameIndex === null
            || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING
        ) {
            continue;
        }

        $name = (string)$tokens[$nameIndex][1];
        $open = null;

        for ($j = $nameIndex + 1; $j < $count; $j++) {
            if ($tokens[$j] === '{') {
                $open = $j;
                break;
            }

            if ($tokens[$j] === ';') {
                break;
            }
        }

        if (
            $open === null
            || !isset($braceMap[$open])
        ) {
            continue;
        }

        $namespace = dupNamespaceAt(
            $tokens,
            $i
        );

        $classes[] = [
            'name' =>
                $name,
            'fqn' =>
                $namespace !== ''
                    ? $namespace . '\\' . $name
                    : $name,
            'file' =>
                $relative,
            'line' =>
                dupLine($tokens, $i),
            'open' =>
                $open,
            'close' =>
                $braceMap[$open],
            'kind' =>
                token_name($token[0]),
        ];
    }

    return $classes;
}

/**
 * @param array<int,mixed> $tokens
 * @return array{hash:string,tokens:int,chars:int}
 */
function dupBodyFingerprint(
    array $tokens,
    int $open,
    int $close
): array {
    $parts = [];
    $meaningful = 0;
    $chars = 0;

    for ($i = $open + 1; $i < $close; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (
                in_array(
                    $token[0],
                    [
                        T_WHITESPACE,
                        T_COMMENT,
                        T_DOC_COMMENT,
                    ],
                    true
                )
            ) {
                continue;
            }

            $text = $token[1];
            $parts[] =
                token_name($token[0])
                . ':'
                . $text;

            $meaningful++;
            $chars += strlen($text);
        } else {
            $parts[] = $token;
            $meaningful++;
            $chars++;
        }
    }

    $normalized = implode('|', $parts);

    return [
        'hash' =>
            hash('sha256', $normalized),
        'tokens' =>
            $meaningful,
        'chars' =>
            $chars,
    ];
}

/**
 * @param array<int,array<string,mixed>> $classes
 * @return array<string,mixed>|null
 */
function dupContainingClass(
    array $classes,
    int $index
): ?array {
    $matches = [];

    foreach ($classes as $class) {
        if (
            $index > (int)$class['open']
            && $index < (int)$class['close']
        ) {
            $matches[] = $class;
        }
    }

    if (!$matches) {
        return null;
    }

    usort(
        $matches,
        static function (
            array $a,
            array $b
        ): int {
            $sizeA =
                (int)$a['close']
                - (int)$a['open'];

            $sizeB =
                (int)$b['close']
                - (int)$b['open'];

            return $sizeA <=> $sizeB;
        }
    );

    return $matches[0];
}

/**
 * @param array<int,mixed> $tokens
 * @param array<int,array<string,mixed>> $classes
 * @return array<int,array<string,mixed>>
 */
function dupFunctions(
    array $tokens,
    array $braceMap,
    array $classes,
    string $relative
): array {
    $functions = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (
            !is_array($token)
            || $token[0] !== T_FUNCTION
        ) {
            continue;
        }

        $cursor =
            dupNextMeaningful(
                $tokens,
                $i + 1
            );

        if ($cursor === null) {
            continue;
        }

        if ($tokens[$cursor] === '&') {
            $cursor =
                dupNextMeaningful(
                    $tokens,
                    $cursor + 1
                );

            if ($cursor === null) {
                continue;
            }
        }

        if (
            !is_array($tokens[$cursor])
            || $tokens[$cursor][0] !== T_STRING
        ) {
            /*
             * Closure/arrow-like anonymous function.
             */
            continue;
        }

        $name =
            (string)$tokens[$cursor][1];

        $open = null;
        $end = null;

        for (
            $j = $cursor + 1;
            $j < $count;
            $j++
        ) {
            if ($tokens[$j] === '{') {
                $open = $j;
                $end = $braceMap[$j]
                    ?? null;
                break;
            }

            if ($tokens[$j] === ';') {
                break;
            }
        }

        $class =
            dupContainingClass(
                $classes,
                $i
            );

        $namespace =
            dupNamespaceAt(
                $tokens,
                $i
            );

        if ($class) {
            $symbol =
                (string)$class['fqn']
                . '::'
                . $name;

            $scope = 'method';
        } else {
            $symbol =
                $namespace !== ''
                    ? $namespace
                        . '\\'
                        . $name
                    : $name;

            $scope = 'function';
        }

        $fingerprint = [
            'hash' => '',
            'tokens' => 0,
            'chars' => 0,
        ];

        if (
            $open !== null
            && $end !== null
        ) {
            $fingerprint =
                dupBodyFingerprint(
                    $tokens,
                    $open,
                    $end
                );
        }

        $functions[] = [
            'name' =>
                $name,
            'symbol' =>
                $symbol,
            'scope' =>
                $scope,
            'class' =>
                $class['fqn']
                ?? null,
            'file' =>
                $relative,
            'line' =>
                dupLine($tokens, $i),
            'body_hash' =>
                $fingerprint['hash'],
            'body_tokens' =>
                $fingerprint['tokens'],
            'body_chars' =>
                $fingerprint['chars'],
        ];
    }

    return $functions;
}

/**
 * @return array<int,string>
 */
function dupBootstrapDuplicateRequires(
    string $root
): array {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . 'bootstrap.php';

    if (!is_file($file)) {
        return [];
    }

    $content =
        file_get_contents($file);

    if (!is_string($content)) {
        return [];
    }

    preg_match_all(
        '/require_once\s+([^;]+);/',
        $content,
        $matches
    );

    $counts = [];

    foreach (
        $matches[1]
        ?? []
        as $expression
    ) {
        $expression =
            preg_replace(
                '/\s+/',
                ' ',
                trim((string)$expression)
            )
            ?: '';

        if ($expression === '') {
            continue;
        }

        $counts[$expression] =
            ($counts[$expression] ?? 0)
            + 1;
    }

    $duplicates = [];

    foreach ($counts as $expression => $count) {
        if ($count > 1) {
            $duplicates[] =
                $expression
                . ' × '
                . $count;
        }
    }

    return $duplicates;
}

function dupSection(
    array &$lines,
    string $title
): void {
    $lines[] = '';
    $lines[] = str_repeat('-', 88);
    $lines[] = $title;
    $lines[] = str_repeat('-', 88);
}

$files = dupPhpFiles($root);

$allClasses = [];
$allFunctions = [];
$parseErrors = [];

foreach ($files as $file) {
    $relative =
        dupRelative(
            $root,
            $file
        );

    $code =
        file_get_contents(
            $file
        );

    if (!is_string($code)) {
        $parseErrors[] =
            $relative
            . ': não foi possível ler';
        continue;
    }

    try {
        $tokens =
            token_get_all(
                $code,
                TOKEN_PARSE
            );
    } catch (ParseError $e) {
        $parseErrors[] =
            $relative
            . ': '
            . $e->getMessage();
        continue;
    }

    $braceMap =
        dupBraceMap(
            $tokens
        );

    $classes =
        dupClasses(
            $tokens,
            $braceMap,
            $relative
        );

    $functions =
        dupFunctions(
            $tokens,
            $braceMap,
            $classes,
            $relative
        );

    array_push(
        $allClasses,
        ...$classes
    );

    array_push(
        $allFunctions,
        ...$functions
    );
}

/*
 * 1) Classes/interfaces/traits/enums declaradas mais de uma vez.
 */
$classGroups = [];

foreach ($allClasses as $class) {
    $key =
        strtolower(
            (string)$class['fqn']
        );

    $classGroups[$key][] =
        $class;
}

$duplicateClasses = [];

foreach ($classGroups as $group) {
    if (count($group) > 1) {
        $duplicateClasses[] =
            $group;
    }
}

/*
 * 2) Funções globais ou métodos com o mesmo símbolo declarados mais de uma vez.
 */
$functionGroups = [];

foreach ($allFunctions as $function) {
    $key =
        strtolower(
            (string)$function['symbol']
        );

    $functionGroups[$key][] =
        $function;
}

$duplicateDeclarations = [];

foreach ($functionGroups as $group) {
    if (count($group) > 1) {
        $duplicateDeclarations[] =
            $group;
    }
}

/*
 * 3) Implementações exatamente iguais em símbolos diferentes.
 *
 * Evita métodos muito pequenos, getters triviais e corpos vazios.
 */
$bodyGroups = [];

foreach ($allFunctions as $function) {
    if (
        (int)$function['body_tokens'] < 24
        || (int)$function['body_chars'] < 120
        || (string)$function['body_hash'] === ''
    ) {
        continue;
    }

    $bodyGroups[
        (string)$function['body_hash']
    ][] = $function;
}

$duplicateBodies = [];

foreach ($bodyGroups as $group) {
    $symbols =
        array_unique(
            array_map(
                static fn(array $row): string =>
                    (string)$row['symbol'],
                $group
            )
        );

    if (count($symbols) > 1) {
        $duplicateBodies[] =
            $group;
    }
}

/*
 * 4) Compatibilidade antiga que a consolidação deveria ter removido.
 */
$legacy = [];

$bootstrap =
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

if (is_file($bootstrap)) {
    $bootstrapContent =
        file_get_contents(
            $bootstrap
        );

    if (is_string($bootstrapContent)) {
        foreach (
            [
                '$contentPageCacheServiceFile',
                '$autosaveServiceFile',
                '$adminAdvancedSearchServiceFile',
                '$adminNotificationServiceFile',
                '$userActivityServiceFile',
            ]
            as $needle
        ) {
            if (
                str_contains(
                    $bootstrapContent,
                    $needle
                )
            ) {
                $legacy[] =
                    $needle;
            }
        }
    }
}

$duplicateRequires =
    dupBootstrapDuplicateRequires(
        $root
    );

$lines = [];

$lines[] =
    'Portal IECLB Parobé - Auditoria de duplicidades v1.2.0';

$lines[] =
    str_repeat(
        '=',
        88
    );

$lines[] =
    '[INFO] Arquivos PHP analisados: '
    . count($files);

$lines[] =
    '[INFO] Classes/interfaces/traits/enums: '
    . count($allClasses);

$globalCount =
    count(
        array_filter(
            $allFunctions,
            static fn(array $row): bool =>
                $row['scope']
                === 'function'
        )
    );

$methodCount =
    count($allFunctions)
    - $globalCount;

$lines[] =
    '[INFO] Funções globais: '
    . $globalCount;

$lines[] =
    '[INFO] Métodos: '
    . $methodCount;

dupSection(
    $lines,
    '1. DUPLICAÇÕES DE DECLARAÇÃO — prioridade máxima'
);

if (
    !$duplicateClasses
    && !$duplicateDeclarations
) {
    $lines[] =
        '[OK] Nenhuma classe, função ou método foi declarado duas vezes no código ativo analisado.';
} else {
    foreach ($duplicateClasses as $group) {
        $lines[] =
            '[CRÍTICO] Classe duplicada: '
            . (string)$group[0]['fqn'];

        foreach ($group as $row) {
            $lines[] =
                '  - '
                . (string)$row['file']
                . ':'
                . (int)$row['line'];
        }
    }

    foreach ($duplicateDeclarations as $group) {
        $lines[] =
            '[CRÍTICO] Declaração duplicada: '
            . (string)$group[0]['symbol'];

        foreach ($group as $row) {
            $lines[] =
                '  - '
                . (string)$row['file']
                . ':'
                . (int)$row['line'];
        }
    }
}

dupSection(
    $lines,
    '2. IMPLEMENTAÇÕES IDÊNTICAS — revisar possível código repetido'
);

if (!$duplicateBodies) {
    $lines[] =
        '[OK] Nenhum corpo não trivial exatamente igual foi encontrado em símbolos diferentes.';
} else {
    foreach ($duplicateBodies as $index => $group) {
        $lines[] =
            '[REVISAR] Grupo '
            . ($index + 1)
            . ' — '
            . count($group)
            . ' implementações idênticas:';

        foreach ($group as $row) {
            $lines[] =
                '  - '
                . (string)$row['symbol']
                . ' em '
                . (string)$row['file']
                . ':'
                . (int)$row['line'];
        }
    }
}

dupSection(
    $lines,
    '3. BOOTSTRAP / COMPATIBILIDADE'
);

if (!$legacy) {
    $lines[] =
        '[OK] Wrappers antigos de compatibilidade não foram encontrados no bootstrap.php.';
} else {
    $lines[] =
        '[REVISAR] Wrappers antigos ainda presentes no bootstrap.php:';

    foreach ($legacy as $needle) {
        $lines[] =
            '  - '
            . $needle;
    }
}

if (!$duplicateRequires) {
    $lines[] =
        '[OK] Nenhum require_once repetido no bootstrap.php.';
} else {
    $lines[] =
        '[REVISAR] require_once repetido no bootstrap.php:';

    foreach ($duplicateRequires as $row) {
        $lines[] =
            '  - '
            . $row;
    }
}

dupSection(
    $lines,
    '4. ERROS DE PARSE ENCONTRADOS DURANTE A LEITURA'
);

if (!$parseErrors) {
    $lines[] =
        '[OK] Todos os arquivos analisados foram tokenizados corretamente.';
} else {
    foreach ($parseErrors as $error) {
        $lines[] =
            '[ERRO] '
            . $error;
    }
}

dupSection(
    $lines,
    'RESUMO'
);

$critical =
    count($duplicateClasses)
    + count($duplicateDeclarations)
    + count($parseErrors);

$review =
    count($duplicateBodies)
    + count($legacy)
    + count($duplicateRequires);

$lines[] =
    '[RESUMO] Críticos: '
    . $critical;

$lines[] =
    '[RESUMO] Itens para revisão: '
    . $review;

if ($critical === 0 && $review === 0) {
    $lines[] =
        'RESULTADO: código ativo sem duplicações detectadas por esta auditoria.';
} elseif ($critical === 0) {
    $lines[] =
        'RESULTADO: sem duplicações fatais; existem itens de repetição/compatibilidade para revisão.';
} else {
    $lines[] =
        'RESULTADO: foram encontradas duplicações/erros que precisam de correção.';
}

$output =
    implode(
        PHP_EOL,
        $lines
    )
    . PHP_EOL;

echo $output;

$reportDir =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'reports';

if (
    (
        is_dir($reportDir)
        || @mkdir(
            $reportDir,
            0775,
            true
        )
    )
    && is_writable($reportDir)
) {
    $stamp =
        date(
            'Ymd-His'
        );

    $txt =
        $reportDir
        . DIRECTORY_SEPARATOR
        . 'duplicidades-v120-'
        . $stamp
        . '.txt';

    @file_put_contents(
        $txt,
        $output,
        LOCK_EX
    );

    $json =
        $reportDir
        . DIRECTORY_SEPARATOR
        . 'duplicidades-v120-'
        . $stamp
        . '.json';

    @file_put_contents(
        $json,
        json_encode(
            [
                'generated_at' =>
                    date(DATE_ATOM),
                'files' =>
                    count($files),
                'classes' =>
                    count($allClasses),
                'global_functions' =>
                    $globalCount,
                'methods' =>
                    $methodCount,
                'critical' =>
                    $critical,
                'review' =>
                    $review,
                'duplicate_classes' =>
                    $duplicateClasses,
                'duplicate_declarations' =>
                    $duplicateDeclarations,
                'duplicate_bodies' =>
                    $duplicateBodies,
                'legacy_bootstrap' =>
                    $legacy,
                'duplicate_requires' =>
                    $duplicateRequires,
                'parse_errors' =>
                    $parseErrors,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );

    echo PHP_EOL;
    echo '[INFO] Relatório TXT: '
        . dupRelative(
            $root,
            $txt
        )
        . PHP_EOL;

    echo '[INFO] Relatório JSON: '
        . dupRelative(
            $root,
            $json
        )
        . PHP_EOL;
}

exit(
    $critical > 0
        ? 2
        : (
            $review > 0
                ? 1
                : 0
        )
);
