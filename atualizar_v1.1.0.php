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
    . '_update_payload_v1.1.0';

$stamp = date('Ymd-His');

function v110Fail(string $message): never
{
    fwrite(STDERR, "[ERRO] {$message}\n");
    exit(1);
}

function v110Path(string $root, string $relative): string
{
    return $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function v110Read(string $file, string $label): string
{
    if (!is_file($file)) {
        v110Fail("Arquivo não encontrado: {$label}");
    }

    $content = file_get_contents($file);

    if ($content === false) {
        v110Fail("Não foi possível ler: {$label}");
    }

    return $content;
}

function v110Lint(string $content, string $label): void
{
    if (!function_exists('exec')) {
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ieclb_v110_');

    if (
        $tmp === false
        || file_put_contents($tmp, $content) === false
    ) {
        if ($tmp) {
            @unlink($tmp);
        }

        v110Fail("Não foi possível validar {$label}.");
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
        v110Fail(
            "Erro de sintaxe em {$label}:\n"
            . implode("\n", $output)
        );
    }
}

function v110WriteAtomic(string $file, string $content): void
{
    $dir = dirname($file);

    if (
        !is_dir($dir)
        && !@mkdir($dir, 0775, true)
        && !is_dir($dir)
    ) {
        v110Fail("Não foi possível criar: {$dir}");
    }

    $tmp = $file . '.tmp-v110';

    if (
        file_put_contents(
            $tmp,
            $content,
            LOCK_EX
        ) === false
    ) {
        v110Fail("Não foi possível gravar: {$file}");
    }

    if (
        DIRECTORY_SEPARATOR === '\\'
        && is_file($file)
        && !@unlink($file)
    ) {
        @unlink($tmp);
        v110Fail("Não foi possível substituir: {$file}");
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        v110Fail("Não foi possível finalizar: {$file}");
    }
}

function v110PatchVersion(
    string $content,
    string $label
): string {
    $pattern =
        '~define\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*\)\s*;~';

    $count = 0;

    $patched =
        preg_replace(
            $pattern,
            "define('APP_VERSION', '1.1.0');",
            $content,
            1,
            $count
        );

    if (
        !is_string($patched)
        || $count !== 1
    ) {
        v110Fail(
            "{$label}: APP_VERSION não encontrado."
        );
    }

    return $patched;
}

function v110PatchBootstrap(string $content): string
{
    $marker =
        "require_once __DIR__ . '/app/Services/PortalHealthSnapshotService.php';";

    if (str_contains($content, $marker)) {
        return $content;
    }

    $anchor =
        "require_once __DIR__ . '/app/Services/ProductionReadinessService.php';";

    if (!str_contains($content, $anchor)) {
        v110Fail(
            'bootstrap.php: ProductionReadinessService não encontrado.'
        );
    }

    return
        str_replace(
            $anchor,
            $anchor
            . PHP_EOL
            . $marker,
            $content
        );
}

function v110PatchAdminMenu(string $content): string
{
    if (
        str_contains(
            $content,
            'PORTAL_HEALTH_MENU_V110'
        )
    ) {
        return $content;
    }

    $needle =
        "href=\"<?= e(url('admin/ferramentas/pre-producao.php')) ?>\"";

    $pos =
        strpos(
            $content,
            $needle
        );

    if ($pos === false) {
        v110Fail(
            'admin/_header.php: link de Pré-produção não encontrado.'
        );
    }

    $end =
        strpos(
            $content,
            '</a>',
            $pos
        );

    if ($end === false) {
        v110Fail(
            'admin/_header.php: fechamento do link de Pré-produção não encontrado.'
        );
    }

    $end += 4;

    $link = <<<'PHP'

                            <?php /* PORTAL_HEALTH_MENU_V110 */ ?>
                            <a
                                class="<?= $isPath('ferramentas/saude-portal.php') ? 'active' : '' ?>"
                                href="<?= e(url('admin/ferramentas/saude-portal.php')) ?>"
                            >Saúde do Portal</a>
PHP;

    return
        substr(
            $content,
            0,
            $end
        )
        . $link
        . substr(
            $content,
            $end
        );
}

function v110PatchChangelog(string $content): string
{
    if (str_contains($content, '## v1.1.0')) {
        return $content;
    }

    $section = <<<'MD'

## v1.1.0 — Saúde do Portal

- adiciona painel operacional permanente em Ferramentas;
- adiciona snapshots manuais de saúde em JSON;
- mantém até 120 snapshots sem nova tabela de banco;
- adiciona histórico e tendência de pontuação;
- adiciona teste `tests/portal-health.php`;
- APP_VERSION passa para 1.1.0.

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

echo "Portal IECLB Parobé - Atualização v1.1.0 Saúde do Portal\n";
echo str_repeat('=', 82) . "\n";

require_once v110Path(
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
        '1.0.1',
        '<'
    )
) {
    v110Fail(
        'A v1.1.0 requer a v1.0.1 instalada.'
    );
}

if (
    version_compare(
        $currentVersion,
        '1.1.0',
        '>'
    )
) {
    v110Fail(
        "A instalação está em versão superior: {$currentVersion}."
    );
}

/*
 * Pré-flight operacional.
 */
if (class_exists('ProductionReadinessService')) {
    $pdo =
        Database::connection();

    $report =
        ProductionReadinessService::report(
            $pdo,
            $root
        );

    echo '[INFO] Saúde atual: '
        . (int)$report['passed']
        . '/'
        . (int)$report['checks']
        . ' aprovadas; '
        . count($report['warnings'])
        . ' aviso(s); '
        . count($report['blockers'])
        . " bloqueador(es).\n";

    if ($report['blockers']) {
        foreach ($report['blockers'] as $blocker) {
            echo "[BLOQUEADOR] {$blocker}\n";
        }

        v110Fail(
            'A v1.1.0 não será instalada enquanto houver bloqueadores.'
        );
    }
}

if (
    is_file(
        v110Path(
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
            v110Path(
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
        v110Fail(
            'A suíte geral falhou antes da atualização. Nenhum arquivo foi alterado.'
        );
    }

    echo "\n[OK] Pré-flight de qualidade aprovado.\n";
}

$prepared = [];

foreach (
    [
        'app/Services/PortalHealthSnapshotService.php',
        'admin/ferramentas/saude-portal.php',
        'tests/portal-health.php',
        'docs/RELEASE_v1.1.0.md',
    ]
    as $relative
) {
    $source =
        v110Path(
            $payload,
            $relative
        );

    $content =
        v110Read(
            $source,
            $relative
        );

    if (str_ends_with($relative, '.php')) {
        v110Lint(
            $content,
            $relative
        );
    }

    $target =
        v110Path(
            $root,
            $relative
        );

    $prepared[$relative] = [
        'file' => $target,
        'original' =>
            is_file($target)
                ? v110Read($target, $relative)
                : null,
        'patched' => $content,
    ];

    echo "[OK] {$relative} preparado.\n";
}

foreach (
    [
        'bootstrap.php' =>
            'v110PatchBootstrap',
        'admin/_header.php' =>
            'v110PatchAdminMenu',
    ]
    as $relative => $patcher
) {
    $file =
        v110Path(
            $root,
            $relative
        );

    $original =
        v110Read(
            $file,
            $relative
        );

    $patched =
        $patcher(
            $original
        );

    v110Lint(
        $patched,
        $relative
    );

    $prepared[$relative] = [
        'file' => $file,
        'original' => $original,
        'patched' => $patched,
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
        v110Path(
            $root,
            $relative
        );

    $original =
        v110Read(
            $file,
            $relative
        );

    $patched =
        v110PatchVersion(
            $original,
            $relative
        );

    v110Lint(
        $patched,
        $relative
    );

    $prepared[$relative] = [
        'file' => $file,
        'original' => $original,
        'patched' => $patched,
    ];

    echo "[OK] {$relative} preparado para 1.1.0.\n";
}

$changelog =
    v110Path(
        $root,
        'CHANGELOG.md'
    );

if (is_file($changelog)) {
    $original =
        v110Read(
            $changelog,
            'CHANGELOG.md'
        );

    $prepared['CHANGELOG.md'] = [
        'file' => $changelog,
        'original' => $original,
        'patched' =>
            v110PatchChangelog(
                $original
            ),
    ];

    echo "[OK] CHANGELOG.md preparado.\n";
}

$markers = [
    'app/Services/PortalHealthSnapshotService.php' =>
        'final class PortalHealthSnapshotService',
    'admin/ferramentas/saude-portal.php' =>
        "Auth::requirePermission('configuracoes.gerenciar')",
    'tests/portal-health.php' =>
        'teste Saúde do Portal v1.1.0',
    'bootstrap.php' =>
        'PortalHealthSnapshotService.php',
    'admin/_header.php' =>
        'PORTAL_HEALTH_MENU_V110',
];

foreach ($markers as $relative => $marker) {
    if (
        !isset($prepared[$relative])
        || !str_contains(
            $prepared[$relative]['patched'],
            $marker
        )
    ) {
        v110Fail(
            "Validação em memória falhou: {$relative} / {$marker}"
        );
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
    . 'v1.1.0-saude-portal-'
    . $stamp;

foreach ($prepared as $relative => $info) {
    if ($info['original'] === null) {
        continue;
    }

    $backupFile =
        v110Path(
            $backupRoot,
            $relative
        );

    $dir =
        dirname(
            $backupFile
        );

    if (
        !is_dir($dir)
        && !@mkdir(
            $dir,
            0775,
            true
        )
        && !is_dir($dir)
    ) {
        v110Fail(
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
        v110Fail(
            "Não foi possível fazer backup de {$relative}."
        );
    }
}

echo "[OK] Backup: storage/update-backups/v1.1.0-saude-portal-{$stamp}/\n";

foreach ($prepared as $relative => $info) {
    v110WriteAtomic(
        $info['file'],
        $info['patched']
    );

    echo "[OK] {$relative} atualizado.\n";
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "[OK] OPcache invalidado quando disponível.\n";

if (function_exists('exec')) {
    foreach (
        [
            'tests/run.php',
            'tests/portal-health.php',
            'tests/release-readiness.php',
            'tests/release-final.php',
        ]
        as $relative
    ) {
        $file =
            v110Path(
                $root,
                $relative
            );

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
            echo "\n[AVISO] {$relative} encontrou problema após a atualização.\n";
        } else {
            echo "\n[OK] {$relative} aprovado.\n";
        }
    }
}

echo "\n";
echo str_repeat('=', 82) . "\n";
echo " PORTAL IECLB PAROBÉ v1.1.0 CONCLUÍDO\n";
echo str_repeat('=', 82) . "\n\n";

echo "APP_VERSION agora é 1.1.0.\n";
echo "Não houve migração de banco.\n\n";
echo "Novo painel:\n";
echo "  Admin > Ferramentas > Saúde do Portal\n\n";
echo "Validação:\n";
echo "  php diagnosticar_v1.1.0.php\n";
echo "  php tests/portal-health.php\n";
echo "  php tests/release-readiness.php\n";
echo "  php tests/release-final.php\n";
