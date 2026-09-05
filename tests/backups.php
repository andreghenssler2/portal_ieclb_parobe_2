<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

$pdo =
    Database::connection();

if (!class_exists('BackupRestoreTestService')) {
    fwrite(
        STDERR,
        "[FALHA] BackupRestoreTestService indisponível.\n"
    );

    exit(1);
}

$service =
    new BackupRestoreTestService(
        $pdo,
        $root
    );

echo "Portal IECLB Parobé - teste seguro de restaurabilidade\n";
echo str_repeat('=', 76) . "\n\n";

$quick =
    $service->quickCheck();

echo '[INFO] Pasta: '
    . (string)$quick['backup_directory']
    . "\n";

echo '[INFO] Backups do banco existentes: '
    . (int)$quick['database_backups']
    . "\n";

echo '[INFO] Backups completos existentes: '
    . (int)$quick['full_backups']
    . "\n";

echo '[INFO] ZipArchive: '
    . (!empty($quick['zip_supported']) ? 'disponível' : 'indisponível')
    . "\n\n";

foreach ((array)$quick['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

if (empty($quick['ok'])) {
    foreach ((array)$quick['issues'] as $issue) {
        echo "[FALHA] {$issue}\n";
    }

    exit(1);
}

/*
 * O teste CLI não inclui uploads para evitar backup muito grande.
 * Quando ZipArchive existe, temas são incluídos.
 */
$result =
    $service->run(
        true,
        !empty($quick['zip_supported']),
        false,
        true
    );

if (is_array($result['database'])) {
    $db =
        $result['database'];

    echo "\n[OK] Backup do banco criado e validado.\n";
    echo '     Arquivo: '
        . (string)$db['name']
        . "\n";
    echo '     Tabelas: '
        . (int)$db['dump_tables']
        . '/'
        . (int)$db['current_tables']
        . "\n";
    echo '     SHA-256: '
        . (string)$db['sha256']
        . "\n";
}

if (is_array($result['full'])) {
    $full =
        $result['full'];

    echo "\n[OK] Backup completo criado e validado.\n";
    echo '     Arquivo: '
        . (string)$full['name']
        . "\n";
    echo '     Arquivos verificados: '
        . (int)$full['files_verified']
        . '/'
        . (int)$full['files_manifest']
        . "\n";
    echo '     SHA-256: '
        . (string)$full['sha256']
        . "\n";
}

foreach ((array)$result['warnings'] as $warning) {
    echo "[AVISO] {$warning}\n";
}

foreach ((array)$result['errors'] as $error) {
    echo "[FALHA] {$error}\n";
}

echo "\n";
echo str_repeat('=', 76) . "\n";

if (empty($result['ok'])) {
    echo "RESULTADO: teste de restaurabilidade encontrou problema(s).\n";
    exit(1);
}

echo "RESULTADO: backups criados e validados para uma futura restauração.\n";
echo "Nenhuma restauração foi executada no banco ou nos arquivos ativos.\n";

exit(0);
