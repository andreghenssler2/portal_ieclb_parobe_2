<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

$errors = 0;
$warnings = 0;

echo "Portal IECLB Parobé - bateria consolidada v1.2.0\n";
echo str_repeat('=', 88) . "\n";

try {
    $pdo = Database::connection();
    $audit = ReleaseAuditService::run($pdo, $root);

    foreach ($audit['checks'] as $check) {
        $status = (string)$check['status'];
        $label = (string)$check['label'];
        $detail = (string)$check['detail'];

        if ($status === 'ok') {
            echo "[OK] {$label}: {$detail}\n";
        } elseif ($status === 'warning') {
            echo "[AVISO] {$label}: {$detail}\n";
            $warnings++;
        } else {
            echo "[FALHA] {$label}: {$detail}\n";
            $errors++;
        }
    }
} catch (Throwable $e) {
    echo '[FALHA] Auditoria: ' . $e->getMessage() . "\n";
    $errors++;
}

$routes = [
    'index.php',
    'noticia.php',
    'pagina.php',
    'agenda.php',
    'evento.php',
    'comunidades.php',
    'comunidade.php',
    'busca.php',
    'sitemap.php',
    'robots.php',
    'social-image.php',
    'admin/index.php',
    'admin/noticias/form.php',
    'admin/paginas/form.php',
    'admin/eventos/form.php',
    'admin/midias/index.php',
    'admin/seguranca.php',
    'admin/seo/redirects.php',
];

foreach ($routes as $relative) {
    $file = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $ok = is_file($file);

    echo '[' . ($ok ? 'OK' : 'FALHA') . "] rota {$relative}\n";

    if (!$ok) {
        $errors++;
    }
}

$bootstrap = @file_get_contents($root . DIRECTORY_SEPARATOR . 'bootstrap.php') ?: '';

foreach (
    [
        '$contentPageCacheServiceFile',
        '$autosaveServiceFile',
        '$adminAdvancedSearchServiceFile',
        '$adminNotificationServiceFile',
        '$userActivityServiceFile',
    ]
    as $legacy
) {
    $ok = !str_contains($bootstrap, $legacy);
    echo '[' . ($ok ? 'OK' : 'FALHA') . "] compatibilidade removida: {$legacy}\n";

    if (!$ok) {
        $errors++;
    }
}

/*
 * Lint completo do código PHP da distribuição ativa.
 * Não percorre dados gerados, bibliotecas externas ou uploads.
 */
if (!function_exists('exec')) {
    echo "[AVISO] exec() indisponível; lint recursivo não executado.\n";
    $warnings++;
} else {
    $excluded = [
        DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
    ];

    $phpFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $path = $item->getPathname();
        $skip = false;

        foreach ($excluded as $needle) {
            if (str_contains($path, $needle)) {
                $skip = true;
                break;
            }
        }

        if (!$skip) {
            $phpFiles[] = $path;
        }
    }

    sort($phpFiles);
    $lintErrors = 0;

    foreach ($phpFiles as $file) {
        $out = [];
        $status = 0;

        @exec(
            escapeshellarg(PHP_BINARY)
            . ' -l '
            . escapeshellarg($file)
            . ' 2>&1',
            $out,
            $status
        );

        if ($status !== 0) {
            $lintErrors++;
            echo "[FALHA] PHP lint: {$file}\n";
            echo implode("\n", $out) . "\n";
        }
    }

    if ($lintErrors === 0) {
        echo '[OK] PHP lint completo: ' . count($phpFiles) . " arquivo(s).\n";
    } else {
        $errors += $lintErrors;
    }
}

echo str_repeat('=', 88) . "\n";
echo "OK: " . ($errors === 0 ? 'sim' : 'não') . "\n";
echo "Avisos: {$warnings}\n";
echo "Falhas: {$errors}\n";

if ($errors > 0) {
    echo "RESULTADO: consolidação v1.2.0 com falhas.\n";
    exit(1);
}

echo "RESULTADO: consolidação v1.2.0 aprovada.\n";
exit(0);
