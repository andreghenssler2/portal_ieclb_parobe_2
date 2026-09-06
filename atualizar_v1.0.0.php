<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este atualizador somente pelo terminal.\n");
}

$root = __DIR__;
$payload =
    $root
    . DIRECTORY_SEPARATOR
    . '_update_payload_v1.0.0';

$stamp = date('Ymd-His');

function v100Fail(string $message): never
{
    fwrite(STDERR, "[ERRO] {$message}\n");
    exit(1);
}

function v100Path(string $root, string $relative): string
{
    return $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function v100Read(string $file, string $label): string
{
    if (!is_file($file)) {
        v100Fail("Arquivo não encontrado: {$label}");
    }

    $content = file_get_contents($file);

    if ($content === false) {
        v100Fail("Não foi possível ler: {$label}");
    }

    return $content;
}

function v100Lint(string $content, string $label): void
{
    if (!function_exists('exec')) {
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ieclb_v100_');

    if (
        $tmp === false
        || file_put_contents($tmp, $content) === false
    ) {
        if ($tmp) {
            @unlink($tmp);
        }

        v100Fail("Não foi possível validar {$label}.");
    }

    $output = [];
    $status = 0;

    @exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($tmp)
        . ' 2>&1',
        $output,
        $status
    );

    @unlink($tmp);

    if ($status !== 0) {
        v100Fail(
            "Erro de sintaxe em {$label}:\n"
            . implode("\n", $output)
        );
    }
}

function v100WriteAtomic(string $file, string $content): void
{
    $dir = dirname($file);

    if (
        !is_dir($dir)
        && !@mkdir($dir, 0775, true)
        && !is_dir($dir)
    ) {
        v100Fail("Não foi possível criar: {$dir}");
    }

    $tmp = $file . '.tmp-v100';

    if (
        file_put_contents(
            $tmp,
            $content,
            LOCK_EX
        ) === false
    ) {
        v100Fail("Não foi possível gravar: {$file}");
    }

    if (
        DIRECTORY_SEPARATOR === '\\'
        && is_file($file)
        && !@unlink($file)
    ) {
        @unlink($tmp);
        v100Fail("Não foi possível substituir: {$file}");
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        v100Fail("Não foi possível finalizar: {$file}");
    }
}

function v100PatchVersion(
    string $content,
    string $label
): string {
    $pattern =
        '~define\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*\)\s*;~';

    $count = 0;

    $patched =
        preg_replace(
            $pattern,
            "define('APP_VERSION', '1.0.0');",
            $content,
            1,
            $count
        );

    if (
        !is_string($patched)
        || $count !== 1
    ) {
        v100Fail(
            "{$label}: APP_VERSION não encontrado."
        );
    }

    return $patched;
}

function v100PatchChangelog(string $content): string
{
    if (
        str_contains(
            $content,
            '## v1.0.0'
        )
    ) {
        return $content;
    }

    $section = <<<'MD'

## v1.0.0 — Release estável de produção

- consolida o Portal como versão estável;
- não adiciona migração de banco;
- fecha o ciclo de pré-produção sem bloqueadores;
- adiciona teste final de release;
- adiciona guia de deploy de produção;
- adiciona histórico resumido do ciclo 0.x;
- APP_VERSION passa para 1.0.0.

MD;

    $firstBreak =
        strpos(
            $content,
            "\n"
        );

    return
        $firstBreak === false
            ? $content . $section
            : substr(
                $content,
                0,
                $firstBreak + 1
            )
                . $section
                . substr(
                    $content,
                    $firstBreak + 1
                );
}

echo "Portal IECLB Parobé - Atualização v1.0.0 Produção\n";
echo str_repeat('=', 82) . "\n";

require_once v100Path(
    $root,
    'bootstrap.php'
);

$currentVersion =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "Versão atual: {$currentVersion}\n\n";

if (
    version_compare(
        $currentVersion,
        '0.99.0',
        '<'
    )
) {
    v100Fail(
        'A v1.0.0 requer a v0.99.0 instalada.'
    );
}

if (
    version_compare(
        $currentVersion,
        '1.0.0',
        '>'
    )
) {
    v100Fail(
        "A instalação está em versão superior: {$currentVersion}."
    );
}

/*
 * Pré-flight antes de qualquer backup/gravação.
 */
if (class_exists('ProductionReadinessService')) {
    $pdo = Database::connection();

    $report =
        ProductionReadinessService::report(
            $pdo,
            $root
        );

    echo "[INFO] Pré-produção: "
        . (int)$report['passed']
        . '/'
        . (int)$report['checks']
        . ' aprovadas; '
        . count($report['warnings'])
        . ' aviso(s); '
        . count($report['blockers'])
        . " bloqueador(es).\n";

    foreach ($report['warnings'] as $warning) {
        echo "[AVISO] {$warning}\n";
    }

    if ($report['blockers']) {
        foreach ($report['blockers'] as $blocker) {
            echo "[BLOQUEADOR] {$blocker}\n";
        }

        v100Fail(
            'A v1.0.0 não será instalada enquanto houver bloqueadores.'
        );
    }
} else {
    v100Fail(
        'ProductionReadinessService não disponível.'
    );
}

if (
    is_file(
        v100Path(
            $root,
            'tests/run.php'
        )
    )
    && function_exists('exec')
) {
    $output = [];
    $status = 0;

    @exec(
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg(
            v100Path(
                $root,
                'tests/run.php'
            )
        )
        . ' 2>&1',
        $output,
        $status
    );

    echo "\n" . implode("\n", $output) . "\n";

    if ($status !== 0) {
        v100Fail(
            'A suíte geral falhou no pré-flight. Nenhum arquivo foi alterado.'
        );
    }

    echo "\n[OK] Pré-flight de qualidade aprovado.\n";
}

$prepared = [];

$newFiles = [
    'tests/release-final.php',
    'docs/RELEASE_v1.0.0.md',
    'docs/DEPLOY_PRODUCAO_v1.0.0.md',
    'docs/HISTORICO_v1.0.0.md',
];

foreach ($newFiles as $relative) {
    $source =
        v100Path(
            $payload,
            $relative
        );

    $content =
        v100Read(
            $source,
            $relative
        );

    if (str_ends_with($relative, '.php')) {
        v100Lint(
            $content,
            $relative
        );
    }

    $target =
        v100Path(
            $root,
            $relative
        );

    $prepared[$relative] = [
        'file' => $target,
        'original' =>
            is_file($target)
                ? v100Read($target, $relative)
                : null,
        'patched' => $content,
    ];

    echo "[OK] {$relative} preparado.\n";
}

foreach (
    [
        'config/config.php',
        'config/config.example.php',
    ]
    as $relative
) {
    $file =
        v100Path(
            $root,
            $relative
        );

    $original =
        v100Read(
            $file,
            $relative
        );

    $patched =
        v100PatchVersion(
            $original,
            $relative
        );

    v100Lint(
        $patched,
        $relative
    );

    $prepared[$relative] = [
        'file' => $file,
        'original' => $original,
        'patched' => $patched,
    ];

    echo "[OK] {$relative} preparado para 1.0.0.\n";
}

$changelogFile =
    v100Path(
        $root,
        'CHANGELOG.md'
    );

if (is_file($changelogFile)) {
    $original =
        v100Read(
            $changelogFile,
            'CHANGELOG.md'
        );

    $prepared['CHANGELOG.md'] = [
        'file' => $changelogFile,
        'original' => $original,
        'patched' =>
            v100PatchChangelog(
                $original
            ),
    ];

    echo "[OK] CHANGELOG.md preparado.\n";
}

foreach (
    [
        'tests/release-final.php' => [
            'validação final v1.0.0',
            'ProductionReadinessService',
            'AccessibilityAuditService',
        ],
        'docs/RELEASE_v1.0.0.md' => [
            'Release estável de produção',
        ],
        'docs/DEPLOY_PRODUCAO_v1.0.0.md' => [
            'Deploy de Produção',
        ],
    ]
    as $relative => $markers
) {
    $content =
        $prepared[$relative]['patched'];

    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) {
            v100Fail(
                "{$relative}: marcador ausente: {$marker}"
            );
        }
    }
}

echo "[OK] Todas as alterações validadas em memória.\n";

$backupRoot =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'update-backups'
    . DIRECTORY_SEPARATOR
    . 'v1.0.0-producao-'
    . $stamp;

foreach ($prepared as $relative => $info) {
    if ($info['original'] === null) {
        continue;
    }

    $backupFile =
        v100Path(
            $backupRoot,
            $relative
        );

    $dir = dirname($backupFile);

    if (
        !is_dir($dir)
        && !@mkdir($dir, 0775, true)
        && !is_dir($dir)
    ) {
        v100Fail(
            'Não foi possível criar a pasta de backup.'
        );
    }

    if (
        file_put_contents(
            $backupFile,
            $info['original'],
            LOCK_EX
        ) === false
    ) {
        v100Fail(
            "Não foi possível fazer backup de {$relative}."
        );
    }
}

echo "[OK] Backup: storage/update-backups/v1.0.0-producao-{$stamp}/\n";

foreach ($prepared as $relative => $info) {
    v100WriteAtomic(
        $info['file'],
        $info['patched']
    );

    echo "[OK] {$relative} atualizado.\n";
}

if (
    class_exists(
        'CacheService',
        false
    )
) {
    foreach (
        [
            'page',
            'public',
            'content-page',
        ]
        as $group
    ) {
        try {
            CacheService::clearGroup($group);
        } catch (Throwable $ignored) {
        }
    }
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "[OK] Cache/opcache invalidado quando disponível.\n";

if (function_exists('exec')) {
    foreach (
        [
            'tests/run.php',
            'tests/release-final.php',
        ]
        as $relative
    ) {
        $file = v100Path($root, $relative);

        if (!is_file($file)) {
            continue;
        }

        $output = [];
        $status = 0;

        @exec(
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($file)
            . ' 2>&1',
            $output,
            $status
        );

        echo "\n" . implode("\n", $output) . "\n";

        if ($status !== 0) {
            echo "\n[AVISO] {$relative} encontrou falha após a atualização.\n";
        } else {
            echo "\n[OK] {$relative} aprovado.\n";
        }
    }
}

echo "\n";
echo str_repeat('=', 82) . "\n";
echo " PORTAL IECLB PAROBÉ v1.0.0 CONCLUÍDO\n";
echo str_repeat('=', 82) . "\n\n";

echo "APP_VERSION agora é 1.0.0.\n";
echo "Documentação:\n";
echo "  docs/RELEASE_v1.0.0.md\n";
echo "  docs/DEPLOY_PRODUCAO_v1.0.0.md\n\n";
echo "Validação final:\n";
echo "  php diagnosticar_v1.0.0.php\n";
echo "  php tests/release-final.php\n";
