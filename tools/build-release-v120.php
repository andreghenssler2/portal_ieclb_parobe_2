<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!defined('APP_VERSION') || (string)APP_VERSION !== '1.2.0') {
    fwrite(STDERR, "[ERRO] O gerador requer APP_VERSION 1.2.0.\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(
        STDERR,
        "[ERRO] A extensão ZIP do PHP é necessária para gerar a distribuição oficial.\n"
    );
    exit(1);
}

$pdo = Database::connection();
$audit = ReleaseAuditService::run($pdo, $root);

if ((int)$audit['blockers'] > 0) {
    fwrite(
        STDERR,
        "[ERRO] A auditoria encontrou bloqueadores. Execute php diagnosticar_v1.2.0.php.\n"
    );
    exit(1);
}

$releaseDir =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'releases';

if (!is_dir($releaseDir) && !@mkdir($releaseDir, 0775, true) && !is_dir($releaseDir)) {
    fwrite(STDERR, "[ERRO] Não foi possível criar storage/releases.\n");
    exit(1);
}

$out =
    $releaseDir
    . DIRECTORY_SEPARATOR
    . 'portal_ieclb_parobe_v1.2.0_oficial.zip';

@unlink($out);

$zip = new ZipArchive();

if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[ERRO] Não foi possível criar o ZIP de release.\n");
    exit(1);
}

$rootReal = realpath($root) ?: $root;

$excludedDirNames = [
    '.git',
    '.idea',
    '.vscode',
    'node_modules',
];

$excludedPrefixes = [
    'storage/cache/',
    'storage/logs/',
    'storage/update-backups/',
    'storage/releases/',
    'storage/cron/',
    'storage/sessions/',
    'uploads/',
    'public/uploads/',
];

$excludedFiles = [
    'config/config.php',
    'MANIFEST.txt',
    'SHA256SUMS.txt',
];

$rootPattern = '~^(?:atualizar|diagnosticar)_v[0-9].*\\.php$~i';
$readmePattern = '~^LEIA-ME_v[0-9].*\\.txt$~i';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $rootReal,
        FilesystemIterator::SKIP_DOTS
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

$manifest = [];
$files = 0;
$bytes = 0;

foreach ($iterator as $item) {
    $full = $item->getPathname();
    $relative = ltrim(
        str_replace('\\', '/', substr($full, strlen($rootReal))),
        '/'
    );

    if ($relative === '') {
        continue;
    }

    $parts = explode('/', $relative);

    if (array_intersect($excludedDirNames, $parts)) {
        continue;
    }

    if (str_starts_with($relative, '_update_payload_')) {
        continue;
    }

    $skip = false;

    foreach ($excludedPrefixes as $prefix) {
        if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
            $skip = true;
            break;
        }
    }

    if ($skip) {
        continue;
    }

    if (in_array($relative, $excludedFiles, true)) {
        continue;
    }

    if (
        count($parts) === 1
        && (
            preg_match($rootPattern, $relative)
            || preg_match($readmePattern, $relative)
            || str_ends_with(strtolower($relative), '.zip')
        )
    ) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }

    if (!$item->isFile()) {
        continue;
    }

    if (!$zip->addFile($full, $relative)) {
        $zip->close();
        @unlink($out);
        fwrite(STDERR, "[ERRO] Falha ao adicionar {$relative}.\n");
        exit(1);
    }

    $size = (int)$item->getSize();
    $files++;
    $bytes += $size;
    $manifest[] = [
        'path' => $relative,
        'size' => $size,
        'sha256' => hash_file('sha256', $full) ?: '',
    ];
}

/*
 * A distribuição não leva segredos, cache, backups nem uploads do site atual.
 * Mantemos placeholders para deixar clara a estrutura esperada.
 */
foreach (
    [
        'storage/cache/.gitkeep',
        'storage/logs/.gitkeep',
        'storage/update-backups/.gitkeep',
        'storage/releases/.gitkeep',
        'uploads/.gitkeep',
        'public/uploads/.gitkeep',
    ]
    as $placeholder
) {
    $zip->addFromString($placeholder, '');
}

$install = <<<TXT
PORTAL IECLB PAROBÉ v1.2.0 — DISTRIBUIÇÃO OFICIAL
==================================================

Este pacote foi gerado a partir de uma instalação v1.2.0 aprovada pela
ReleaseAuditService.

DEPLOY SOBRE PORTAL EXISTENTE
-----------------------------
1. Faça backup do banco, config/config.php e uploads.
2. Extraia o pacote sobre o código do Portal.
3. Preserve o config/config.php do ambiente.
4. Preserve uploads/ e public/uploads/ do ambiente.
5. Execute:
   php diagnosticar_v1.2.0.php
   php tests/consolidation-v120.php

NOVA INSTALAÇÃO DE CÓDIGO
-------------------------
- Copie config/config.example.php para config/config.php e configure o ambiente.
- A base de dados precisa ter o schema do Portal já migrado/consolidado.
- Este release não contém senhas, cache, backups ou uploads do portal de origem.

APP_VERSION=1.2.0
TXT;

$zip->addFromString('INSTALL_v1.2.0.txt', $install);
$zip->addFromString(
    'RELEASE-MANIFEST.json',
    json_encode(
        [
            'product' => 'Portal IECLB Parobé',
            'version' => '1.2.0',
            'generated_at' => date(DATE_ATOM),
            'files' => $files,
            'bytes' => $bytes,
            'audit' => [
                'ok' => (int)$audit['ok'],
                'warnings' => (int)$audit['warnings'],
                'blockers' => (int)$audit['blockers'],
            ],
            'entries' => $manifest,
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    ) . "\n"
);

$zip->close();

$hash = hash_file('sha256', $out) ?: '';

file_put_contents(
    $out . '.sha256.txt',
    $hash . '  ' . basename($out) . "\n"
);

echo "Portal IECLB Parobé - distribuição oficial v1.2.0\n";
echo str_repeat('=', 88) . "\n";
echo "[OK] Arquivos: {$files}\n";
echo "[OK] Conteúdo: " . number_format($bytes / 1024 / 1024, 2, ',', '.') . " MB\n";
echo "[OK] ZIP: {$out}\n";
echo "[OK] SHA-256: {$hash}\n";
