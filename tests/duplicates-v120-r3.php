<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste limpeza v1.2.0 R3\n";
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

$canonical = [
    'app/Services/SessionSecurityService.php',
    'app/Services/ContentAutosaveService.php',
    'app/Services/UserActivityService.php',
    'app/Services/DocumentService.php',
];

foreach ($canonical as $relative) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $ok = is_file($file) && filesize($file) > 0;

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] canônico: {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

$legacy = [
    'SessionSecurityService_v0.83.0.php',
    'app/Services/ContentAutosaveServiceV84R3.php',
    'app/Services/UserActivityServiceV87R2.php',
];

foreach ($legacy as $relative) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $ok = !is_file($file);

    echo '[' . ($ok ? 'OK' : 'REVISAR') . "] legado removido: {$relative}\n";

    if (!$ok) {
        /*
         * Pode permanecer somente quando o instalador detectou conteúdo
         * divergente ou referência ativa e decidiu não excluir.
         */
        $content = file_get_contents($file) ?: '';

        if (
            !str_contains($content, 'class ')
            && !str_contains($content, 'final class ')
        ) {
            echo "[FALHA] Arquivo legado restante parece inválido.\n";
            $errors++;
        }
    }
}

foreach (
    [
        'SessionSecurityService',
        'ContentAutosaveService',
        'UserActivityService',
        'DocumentService',
    ]
    as $class
) {
    $ok = class_exists($class);

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] classe {$class}\n";

    if (!$ok) {
        $errors++;
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: limpeza v1.2.0 R3 aprovada.\n";
exit(0);
