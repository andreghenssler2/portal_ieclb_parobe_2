<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

echo "Portal IECLB Parobé - teste consolidação v1.2.0 R4\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version = defined('APP_VERSION')
    ? (string)APP_VERSION
    : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.2.0') {
    echo "[FALHA] APP_VERSION deve permanecer 1.2.0.\n";
    $errors++;
}

$expectedClasses = [
    'SessionSecurityService',
    'ContentAutosaveService',
    'UserActivityService',
    'DocumentService',
];

foreach ($expectedClasses as $class) {
    $ok = class_exists($class, false);

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] classe carregada: {$class}\n";

    if (!$ok) {
        $errors++;
    }
}

$compat = [
    'SessionSecurityService_v0.83.0.php',
    'app/Services/ContentAutosaveServiceV84R3.php',
    'app/Services/UserActivityServiceV87R2.php',
    'admin/app/Services/DocumentService.php',
    'admin/admin/documentos/categorias.php',
    'admin/admin/documentos/index.php',
    'admin/documento-baixar.php',
    'admin/documento.php',
    'admin/documentos.php',
    'categoria_paginacao_20.php',
];

foreach ($compat as $relative) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );

    if (!is_file($file)) {
        echo "[OK] duplicata removida: {$relative}\n";
        continue;
    }

    $content =
        file_get_contents($file)
        ?: '';

    $ok =
        str_contains(
            $content,
            'PORTAL_DUPLICATE_COMPAT_V120_R4'
        );

    echo '['
        . ($ok ? 'OK' : 'FALHA')
        . "] compatibilidade: {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: consolidação v1.2.0 R4 aprovada.\n";
exit(0);
