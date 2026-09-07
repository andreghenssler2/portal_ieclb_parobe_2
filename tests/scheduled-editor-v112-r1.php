<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste agendamento/editor v1.1.2 R1\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

if ($version === '1.1.2') {
    echo "[OK] APP_VERSION = 1.1.2\n";
} else {
    echo "[FALHA] APP_VERSION esperado: 1.1.2; atual: {$version}\n";
    $errors++;
}

$checks = [
    'admin/noticias/form.php' => [
        'PORTAL_EDITOR_ORIGINAL_STATUS_V112_R1',
        '$originalPublicStatus',
        'assertStatusTransitionAllowed',
    ],
    'app/Services/EditorialWorkflowService.php' => [
        'PORTAL_ADMIN_WORKFLOW_OVERRIDE_V112_R1',
        'Auth::isAdmin()',
    ],
    'app/Services/HomeService.php' => [
        'PORTAL_PUBLIC_SCHEDULE_VISIBILITY_V112',
        '<= NOW()',
    ],
];

foreach ($checks as $relative => $markers) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    $content =
        is_file($file)
            ? (file_get_contents($file) ?: '')
            : '';

    if ($content === '') {
        echo "[FALHA] Arquivo ausente: {$relative}\n";
        $errors++;
        continue;
    }

    foreach ($markers as $marker) {
        if (str_contains($content, $marker)) {
            echo "[OK] {$relative}: {$marker}\n";
        } else {
            echo "[FALHA] {$relative}: marcador ausente {$marker}\n";
            $errors++;
        }
    }
}

try {
    $pdo = Database::connection();

    $service = new HomeService($pdo);
    $items = $service->itemsForSection([
        'origem' => 'posts',
        'limite' => 20,
        'categoria_id' => null,
    ]);

    $invalid = [];

    foreach ($items as $item) {
        $status = strtolower(trim((string)($item['status'] ?? '')));

        if ($status !== 'publicado') {
            $invalid[] = (int)($item['id'] ?? 0);
            continue;
        }

        $publishedAt = trim((string)($item['publicado_em'] ?? ''));

        if ($publishedAt !== '') {
            try {
                if (
                    new DateTimeImmutable($publishedAt)
                    > new DateTimeImmutable('now')
                ) {
                    $invalid[] = (int)($item['id'] ?? 0);
                }
            } catch (Throwable $ignored) {
            }
        }
    }

    if (!$invalid) {
        echo "[OK] Home não retorna conteúdo agendado/futuro.\n";
    } else {
        echo "[FALHA] Home retornou IDs inválidos: "
            . implode(',', array_unique($invalid))
            . "\n";
        $errors++;
    }

    $futureScheduled =
        (int)$pdo->query(
            "SELECT COUNT(*)
             FROM posts
             WHERE status='agendado'
               AND publicado_em IS NOT NULL
               AND publicado_em>NOW()"
        )->fetchColumn();

    echo "[INFO] Notícias agendadas futuras no banco: {$futureScheduled}\n";
} catch (Throwable $e) {
    echo "[FALHA] Teste dinâmico: {$e->getMessage()}\n";
    $errors++;
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na correção v1.1.2 R1.\n";
    exit(1);
}

echo "RESULTADO: agendamento do editor e visibilidade pública aprovados.\n";
exit(0);
