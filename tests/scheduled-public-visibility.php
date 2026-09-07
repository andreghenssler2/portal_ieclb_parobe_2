<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste agendamento público v1.1.2\n";
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

$homeFile =
    $root
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'Services'
    . DIRECTORY_SEPARATOR
    . 'HomeService.php';

$homeContent =
    is_file($homeFile)
        ? (file_get_contents($homeFile) ?: '')
        : '';

foreach (
    [
        'PORTAL_PUBLIC_SCHEDULE_VISIBILITY_V112',
        "LOWER(COALESCE(`\$table`.`\$status`,'')) = 'publicado'",
        '$publicationDate',
        '<= NOW()',
    ]
    as $marker
) {
    if (str_contains($homeContent, $marker)) {
        echo "[OK] HomeService marker: {$marker}\n";
    } else {
        echo "[FALHA] HomeService marker ausente: {$marker}\n";
        $errors++;
    }
}

try {
    $pdo =
        Database::connection();

    $scheduledIds =
        $pdo->query(
            "SELECT id
             FROM posts
             WHERE status='agendado'
               AND publicado_em IS NOT NULL
               AND publicado_em>NOW()"
        )->fetchAll(PDO::FETCH_COLUMN)
        ?: [];

    $futurePublishedIds =
        $pdo->query(
            "SELECT id
             FROM posts
             WHERE status='publicado'
               AND publicado_em IS NOT NULL
               AND publicado_em>NOW()"
        )->fetchAll(PDO::FETCH_COLUMN)
        ?: [];

    echo '[INFO] Agendados futuros existentes: '
        . count($scheduledIds)
        . "\n";
    echo '[INFO] Publicados com data futura existentes: '
        . count($futurePublishedIds)
        . "\n";

    $service =
        new HomeService(
            $pdo
        );

    $items =
        $service->itemsForSection(
            [
                'origem' => 'posts',
                'limite' => 20,
                'categoria_id' => null,
            ]
        );

    $itemIds =
        array_map(
            'intval',
            array_column(
                $items,
                'id'
            )
        );

    $leakedScheduled =
        array_values(
            array_intersect(
                array_map('intval', $scheduledIds),
                $itemIds
            )
        );

    $leakedFuturePublished =
        array_values(
            array_intersect(
                array_map('intval', $futurePublishedIds),
                $itemIds
            )
        );

    if (!$leakedScheduled) {
        echo "[OK] Nenhum post agendado futuro vazou para a Home modular.\n";
    } else {
        echo "[FALHA] Agendados futuros encontrados na Home: "
            . implode(',', $leakedScheduled)
            . "\n";
        $errors++;
    }

    if (!$leakedFuturePublished) {
        echo "[OK] Nenhum post com data futura vazou para a Home modular.\n";
    } else {
        echo "[FALHA] Posts publicados com data futura encontrados na Home: "
            . implode(',', $leakedFuturePublished)
            . "\n";
        $errors++;
    }

    $invalidPublicRows = [];

    foreach ($items as $item) {
        $status =
            strtolower(
                trim(
                    (string)($item['status'] ?? '')
                )
            );

        $publishedAt =
            trim(
                (string)($item['publicado_em'] ?? '')
            );

        if ($status !== 'publicado') {
            $invalidPublicRows[] =
                (int)($item['id'] ?? 0);
            continue;
        }

        if ($publishedAt !== '') {
            try {
                if (
                    new DateTimeImmutable($publishedAt)
                    > new DateTimeImmutable('now')
                ) {
                    $invalidPublicRows[] =
                        (int)($item['id'] ?? 0);
                }
            } catch (Throwable $ignored) {
            }
        }
    }

    if (!$invalidPublicRows) {
        echo "[OK] Itens retornados pela Home respeitam status e data pública.\n";
    } else {
        echo "[FALHA] Itens públicos inválidos na Home: "
            . implode(',', array_unique($invalidPublicRows))
            . "\n";
        $errors++;
    }
} catch (Throwable $e) {
    echo "[FALHA] Teste dinâmico: {$e->getMessage()}\n";
    $errors++;
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na visibilidade de conteúdos agendados.\n";
    exit(1);
}

echo "RESULTADO: conteúdos agendados permanecem ocultos até publicação efetiva.\n";
exit(0);
