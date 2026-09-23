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
    . '_update_payload_v1.1.3_R2';

$stamp = date('Ymd-His');

function r2Fail(string $message): never
{
    fwrite(STDERR, "[ERRO] {$message}\n");
    exit(1);
}

function r2Path(string $root, string $relative): string
{
    return
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );
}

function r2Read(string $file, string $label): string
{
    if (!is_file($file)) {
        r2Fail("Arquivo não encontrado: {$label}");
    }

    $content =
        file_get_contents(
            $file
        );

    if ($content === false) {
        r2Fail("Não foi possível ler: {$label}");
    }

    return $content;
}

function r2Lint(string $content, string $label): void
{
    if (!function_exists('exec')) {
        return;
    }

    $tmp =
        tempnam(
            sys_get_temp_dir(),
            'ieclb_v113r2_'
        );

    if (
        $tmp === false
        || file_put_contents(
            $tmp,
            $content
        ) === false
    ) {
        if ($tmp) {
            @unlink($tmp);
        }

        r2Fail("Não foi possível validar {$label}.");
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
        r2Fail(
            "Erro de sintaxe em {$label}:\n"
            . implode("\n", $output)
        );
    }
}

function r2Write(string $file, string $content): void
{
    $dir = dirname($file);

    if (
        !is_dir($dir)
        && !@mkdir(
            $dir,
            0775,
            true
        )
        && !is_dir($dir)
    ) {
        r2Fail("Não foi possível criar: {$dir}");
    }

    $tmp = $file . '.tmp-v113r2';

    if (
        file_put_contents(
            $tmp,
            $content,
            LOCK_EX
        ) === false
    ) {
        r2Fail("Não foi possível gravar: {$file}");
    }

    if (
        DIRECTORY_SEPARATOR === '\\'
        && is_file($file)
        && !@unlink($file)
    ) {
        @unlink($tmp);
        r2Fail("Não foi possível substituir: {$file}");
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        r2Fail("Não foi possível finalizar: {$file}");
    }
}

function r2PatchBootstrap(string $content): string
{
    $requireLine =
        "require_once __DIR__ . '/app/Services/MaintenanceExpiryService.php';";

    if (!str_contains($content, $requireLine)) {
        $anchor =
            "require_once __DIR__ . '/app/Services/CookieConsentService.php';";

        if (!str_contains($content, $anchor)) {
            r2Fail(
                'bootstrap.php: âncora CookieConsentService não encontrada.'
            );
        }

        $content =
            str_replace(
                $anchor,
                $anchor
                . PHP_EOL
                . $requireLine,
                $content,
                $count
            );

        if ($count !== 1) {
            r2Fail(
                'bootstrap.php: falha ao carregar MaintenanceExpiryService.'
            );
        }
    }

    if (
        !str_contains(
            $content,
            'MaintenanceExpiryService::expireIfDue($bootstrapPdo);'
        )
    ) {
        $anchor =
            '    enforceMaintenanceMode($bootstrapPdo);';

        if (!str_contains($content, $anchor)) {
            r2Fail(
                'bootstrap.php: enforceMaintenanceMode não encontrado.'
            );
        }

        $replacement = <<<'PHP'
    /*
     * PORTAL_MAINTENANCE_AUTO_EXPIRE_V113_R2
     * Encerra automaticamente a manutenção quando a previsão já venceu.
     */
    MaintenanceExpiryService::expireIfDue($bootstrapPdo);

    enforceMaintenanceMode($bootstrapPdo);
PHP;

        $content =
            str_replace(
                $anchor,
                $replacement,
                $content,
                $count
            );

        if ($count !== 1) {
            r2Fail(
                'bootstrap.php: falha ao inserir expiração automática.'
            );
        }
    }

    return $content;
}

echo "Portal IECLB Parobé - Correção v1.1.3 R2 Modo Manutenção\n";
echo str_repeat('=', 82) . "\n";

require_once r2Path(
    $root,
    'bootstrap.php'
);

$currentVersion =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "Versão atual: {$currentVersion}\n\n";

if ($currentVersion !== '1.1.3') {
    r2Fail(
        'A correção v1.1.3 R2 requer APP_VERSION 1.1.3.'
    );
}

$prepared = [];

$serviceRelative =
    'app/Services/MaintenanceExpiryService.php';

$serviceContent =
    r2Read(
        r2Path(
            $payload,
            $serviceRelative
        ),
        $serviceRelative
    );

r2Lint(
    $serviceContent,
    $serviceRelative
);

$serviceTarget =
    r2Path(
        $root,
        $serviceRelative
    );

$prepared[$serviceRelative] = [
    'file' => $serviceTarget,
    'original' =>
        is_file($serviceTarget)
            ? r2Read(
                $serviceTarget,
                $serviceRelative
            )
            : null,
    'patched' => $serviceContent,
];

$bootstrapRelative =
    'bootstrap.php';

$bootstrapFile =
    r2Path(
        $root,
        $bootstrapRelative
    );

$bootstrapOriginal =
    r2Read(
        $bootstrapFile,
        $bootstrapRelative
    );

$bootstrapPatched =
    r2PatchBootstrap(
        $bootstrapOriginal
    );

r2Lint(
    $bootstrapPatched,
    $bootstrapRelative
);

$prepared[$bootstrapRelative] = [
    'file' => $bootstrapFile,
    'original' => $bootstrapOriginal,
    'patched' => $bootstrapPatched,
];

foreach (
    [
        'tests/maintenance-expiry-v113-r2.php',
        'docs/RELEASE_v1.1.3_R2.md',
    ]
    as $relative
) {
    $content =
        r2Read(
            r2Path(
                $payload,
                $relative
            ),
            $relative
        );

    if (str_ends_with($relative, '.php')) {
        r2Lint(
            $content,
            $relative
        );
    }

    $target =
        r2Path(
            $root,
            $relative
        );

    $prepared[$relative] = [
        'file' => $target,
        'original' =>
            is_file($target)
                ? r2Read(
                    $target,
                    $relative
                )
                : null,
        'patched' => $content,
    ];
}

foreach (
    [
        'PORTAL_MAINTENANCE_AUTO_EXPIRE_V113_R2',
        'MaintenanceExpiryService::expireIfDue($bootstrapPdo);',
        'enforceMaintenanceMode($bootstrapPdo);',
    ]
    as $marker
) {
    if (
        !str_contains(
            $bootstrapPatched,
            $marker
        )
    ) {
        r2Fail(
            "Validação em memória falhou: {$marker}"
        );
    }
}

echo "[OK] Alterações validadas em memória.\n";

$backupRoot =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'update-backups'
    . DIRECTORY_SEPARATOR
    . 'v1.1.3-R2-manutencao-'
    . $stamp;

foreach ($prepared as $relative => $info) {
    if ($info['original'] === null) {
        continue;
    }

    $backup =
        r2Path(
            $backupRoot,
            $relative
        );

    $dir = dirname($backup);

    if (
        !is_dir($dir)
        && !@mkdir(
            $dir,
            0775,
            true
        )
        && !is_dir($dir)
    ) {
        r2Fail(
            'Não foi possível criar a pasta de backup.'
        );
    }

    if (
        file_put_contents(
            $backup,
            $info['original'],
            LOCK_EX
        ) === false
    ) {
        r2Fail(
            "Não foi possível fazer backup de {$relative}."
        );
    }
}

echo "[OK] Backup criado.\n";

foreach ($prepared as $relative => $info) {
    r2Write(
        $info['file'],
        $info['patched']
    );

    echo "[OK] {$relative} atualizado.\n";
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

if (function_exists('exec')) {
    $test =
        r2Path(
            $root,
            'tests/maintenance-expiry-v113-r2.php'
        );

    $output = [];
    $status = 0;

    @exec(
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($test)
        . ' 2>&1',
        $output,
        $status
    );

    echo "\n"
        . implode(
            "\n",
            $output
        )
        . "\n";

    echo
        $status === 0
            ? "\n[OK] Teste R2 aprovado.\n"
            : "\n[AVISO] Teste R2 encontrou problema.\n";
}

echo "\n";
echo str_repeat('=', 82) . "\n";
echo " CORREÇÃO v1.1.3 R2 CONCLUÍDA\n";
echo str_repeat('=', 82) . "\n\n";

echo "APP_VERSION permanece 1.1.3.\n";
echo "A previsão de retorno agora encerra automaticamente o modo manutenção.\n";
echo "Sem migração de banco.\n\n";
echo "Validação:\n";
echo "  php diagnosticar_v1.1.3_R2.php\n";
echo "  php tests/maintenance-expiry-v113-r2.php\n";
