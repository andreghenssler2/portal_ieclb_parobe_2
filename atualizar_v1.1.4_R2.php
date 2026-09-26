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
    . '_update_payload_v1.1.4_R2';

$stamp =
    date(
        'Ymd-His'
    );

function r2Fail(string $message): never
{
    fwrite(
        STDERR,
        "[ERRO] {$message}\n"
    );

    exit(1);
}

function r2Path(
    string $root,
    string $relative
): string {
    return
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );
}

function r2Read(
    string $file,
    string $label
): string {
    if (!is_file($file)) {
        r2Fail(
            "Arquivo não encontrado: {$label}"
        );
    }

    $content =
        file_get_contents(
            $file
        );

    if ($content === false) {
        r2Fail(
            "Não foi possível ler: {$label}"
        );
    }

    return $content;
}

function r2Lint(
    string $content,
    string $label
): void {
    if (!function_exists('exec')) {
        return;
    }

    $tmp =
        tempnam(
            sys_get_temp_dir(),
            'ieclb_v114r2_'
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

        r2Fail(
            "Não foi possível validar {$label}."
        );
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
            . implode(
                "\n",
                $output
            )
        );
    }
}

function r2Write(
    string $file,
    string $content
): void {
    $dir =
        dirname(
            $file
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
        r2Fail(
            "Não foi possível criar: {$dir}"
        );
    }

    $tmp =
        $file
        . '.tmp-v114r2';

    if (
        file_put_contents(
            $tmp,
            $content,
            LOCK_EX
        ) === false
    ) {
        r2Fail(
            "Não foi possível gravar: {$file}"
        );
    }

    if (
        DIRECTORY_SEPARATOR === '\\'
        && is_file($file)
        && !@unlink($file)
    ) {
        @unlink($tmp);

        r2Fail(
            "Não foi possível substituir: {$file}"
        );
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);

        r2Fail(
            "Não foi possível finalizar: {$file}"
        );
    }
}

echo "Portal IECLB Parobé - v1.1.4 R2 PDF sem Google Viewer\n";
echo str_repeat('=', 84) . "\n";

require_once r2Path(
    $root,
    'config/config.php'
);

$currentVersion =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "Versão atual: {$currentVersion}\n\n";

if ($currentVersion !== '1.1.4') {
    r2Fail(
        'Esta correção requer APP_VERSION 1.1.4. '
        . 'Instale primeiro a v1.1.4 R1.'
    );
}

/*
 * Confirma que a 1.1.4 de MP4 está realmente aplicada.
 */
$mediaService =
    r2Read(
        r2Path(
            $root,
            'app/Services/MediaService.php'
        ),
        'app/Services/MediaService.php'
    );

if (
    !str_contains(
        $mediaService,
        "'video/mp4' => 'mp4'"
    )
) {
    r2Fail(
        'O suporte MP4 da v1.1.4 não foi encontrado. '
        . 'Instale a v1.1.4 R1 antes da R2.'
    );
}

$relative =
    'app/Services/TrustedEmbedService.php';

$file =
    r2Path(
        $root,
        $relative
    );

$original =
    r2Read(
        $file,
        $relative
    );

if (
    !str_contains(
        $original,
        'final class TrustedEmbedService'
    )
    || !str_contains(
        $original,
        'GOOGLE_MAPS_SANDBOX'
    )
) {
    r2Fail(
        'TrustedEmbedService atual não corresponde à base esperada. '
        . 'Nenhum arquivo foi alterado.'
    );
}

$patched =
    r2Read(
        r2Path(
            $payload,
            $relative
        ),
        $relative
    );

r2Lint(
    $patched,
    $relative
);

foreach (
    [
        'PORTAL_LOCAL_PDF_EMBED_V114_R2',
        'localPdfFromGoogleViewer',
        'isLocalPdfUrl',
        'GOOGLE_MAPS_SANDBOX',
    ]
    as $marker
) {
    if (
        !str_contains(
            $patched,
            $marker
        )
    ) {
        r2Fail(
            "Validação em memória falhou: {$marker}"
        );
    }
}

$prepared = [
    $relative => [
        'file' => $file,
        'original' => $original,
        'patched' => $patched,
    ],
];

foreach (
    [
        'tests/pdf-google-iframe-v114-r2.php',
        'docs/RELEASE_v1.1.4_R2.md',
    ]
    as $payloadRelative
) {
    $content =
        r2Read(
            r2Path(
                $payload,
                $payloadRelative
            ),
            $payloadRelative
        );

    if (
        str_ends_with(
            $payloadRelative,
            '.php'
        )
    ) {
        r2Lint(
            $content,
            $payloadRelative
        );
    }

    $target =
        r2Path(
            $root,
            $payloadRelative
        );

    $prepared[$payloadRelative] = [
        'file' => $target,
        'original' =>
            is_file($target)
                ? r2Read(
                    $target,
                    $payloadRelative
                )
                : null,
        'patched' => $content,
    ];
}

echo "[OK] Todas as alterações validadas em memória.\n";

$backupRoot =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'update-backups'
    . DIRECTORY_SEPARATOR
    . 'v1.1.4-R2-pdf-google-'
    . $stamp;

foreach (
    $prepared
    as $preparedRelative => $info
) {
    if ($info['original'] === null) {
        continue;
    }

    $backup =
        r2Path(
            $backupRoot,
            $preparedRelative
        );

    $dir =
        dirname(
            $backup
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
            "Não foi possível fazer backup de {$preparedRelative}."
        );
    }
}

echo "[OK] Backup criado.\n";

foreach (
    $prepared
    as $preparedRelative => $info
) {
    r2Write(
        $info['file'],
        $info['patched']
    );

    echo "[OK] {$preparedRelative} atualizado.\n";
}

/*
 * Limpa caches públicos para o post antigo refletir a correção imediatamente.
 */
require_once r2Path(
    $root,
    'bootstrap.php'
);

if (class_exists('CacheService')) {
    try {
        $removed =
            CacheService::clearGroup('page')
            + CacheService::clearGroup('public')
            + CacheService::clearGroup('content-page');

        echo "[OK] Cache público invalidado: {$removed} arquivo(s).\n";
    } catch (Throwable $e) {
        echo '[AVISO] Cache: '
            . $e->getMessage()
            . "\n";
    }
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

if (function_exists('exec')) {
    $test =
        r2Path(
            $root,
            'tests/pdf-google-iframe-v114-r2.php'
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
echo str_repeat('=', 84) . "\n";
echo " v1.1.4 R2 CONCLUÍDA\n";
echo str_repeat('=', 84) . "\n";
echo "APP_VERSION permanece 1.1.4.\n";
echo "PDF local não depende mais do Google Viewer.\n";
echo "Sem migração e sem alteração do conteúdo salvo no banco.\n";
