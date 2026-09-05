<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = __DIR__;
$dataFile = $root . DIRECTORY_SEPARATOR . 'calendario_2026_eventos.json';

require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

$pdo = Database::connection();

echo "Portal IECLB Parobé - importação do Calendário 2026\n";
echo str_repeat('=', 72) . "\n";

if (!is_file($dataFile)) {
    fwrite(STDERR, "[ERRO] calendario_2026_eventos.json não encontrado.\n");
    exit(1);
}

$items = json_decode((string)file_get_contents($dataFile), true);

if (!is_array($items) || !$items) {
    fwrite(STDERR, "[ERRO] Arquivo de dados inválido ou vazio.\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Confere ENUM
|--------------------------------------------------------------------------
*/

$column = $pdo->query(
    "SHOW COLUMNS FROM eventos LIKE 'tipo'"
)->fetch(PDO::FETCH_ASSOC);

$columnType = strtolower((string)($column['Type'] ?? ''));

foreach (['culto', 'festa', 'atividade', 'reuniao'] as $required) {
    if (!str_contains($columnType, "'" . $required . "'")) {
        fwrite(
            STDERR,
            "[ERRO] eventos.tipo ainda não aceita '{$required}'. Execute primeiro a R9 dos tipos de eventos.\n"
        );
        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| Autor
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query(
    "SELECT u.id
     FROM usuarios u
     LEFT JOIN perfis p ON p.id = u.perfil_id
     WHERE u.ativo = 1
     ORDER BY (p.slug = 'administrador') DESC, u.id ASC
     LIMIT 1"
);

$autorId = (int)$stmt->fetchColumn();

if ($autorId <= 0) {
    fwrite(STDERR, "[ERRO] Nenhum usuário ativo encontrado para autor_id.\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Comunidades
|--------------------------------------------------------------------------
*/

$communityMap = [];

foreach (
    [
        'parobe',
        'entrepelado',
        'fazenda-fialho',
        'santa-cruz-do-pinhal',
        'passo-dos-ferreiros',
    ]
    as $slug
) {
    $stmt = $pdo->prepare(
        'SELECT id FROM comunidades WHERE slug = :slug LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    $id = (int)$stmt->fetchColumn();

    $communityMap[$slug] = $id > 0 ? $id : null;
}

/*
|--------------------------------------------------------------------------
| Backup do banco
|--------------------------------------------------------------------------
*/

if (class_exists('BackupService')) {
    try {
        $backup = new BackupService($pdo, $root);
        $info = $backup->createDatabaseBackup('pre-calendario-2026');
        echo "[OK] Backup do banco criado: "
            . (string)($info['name'] ?? 'arquivo gerado')
            . "\n";
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "[ERRO] Não foi possível criar backup do banco: "
            . $e->getMessage()
            . "\n"
        );
        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| Importação idempotente
|--------------------------------------------------------------------------
*/

$sql = "
    INSERT INTO eventos (
        autor_id,
        comunidade_id,
        categoria_evento_id,
        tipo,
        titulo,
        slug,
        resumo,
        descricao,
        local,
        endereco,
        data_inicio,
        data_fim,
        santa_ceia,
        imagem_capa_id,
        seo_titulo,
        seo_descricao,
        seo_noindex,
        status
    ) VALUES (
        :autor_id,
        :comunidade_id,
        NULL,
        :tipo,
        :titulo,
        :slug,
        :resumo,
        :descricao,
        :local,
        NULL,
        :data_inicio,
        :data_fim,
        :santa_ceia,
        NULL,
        NULL,
        NULL,
        0,
        'publicado'
    )
    ON DUPLICATE KEY UPDATE
        autor_id = VALUES(autor_id),
        comunidade_id = VALUES(comunidade_id),
        tipo = VALUES(tipo),
        titulo = VALUES(titulo),
        resumo = VALUES(resumo),
        descricao = VALUES(descricao),
        local = VALUES(local),
        data_inicio = VALUES(data_inicio),
        data_fim = VALUES(data_fim),
        santa_ceia = VALUES(santa_ceia),
        status = VALUES(status)
";

$stmt = $pdo->prepare($sql);

$pdo->beginTransaction();

$processed = 0;

try {
    foreach ($items as $item) {
        $communitySlug = trim((string)($item['comunidade_slug'] ?? ''));

        $stmt->execute([
            'autor_id' => $autorId,
            'comunidade_id' =>
                $communitySlug !== ''
                    ? ($communityMap[$communitySlug] ?? null)
                    : null,
            'tipo' => (string)$item['tipo'],
            'titulo' => (string)$item['titulo'],
            'slug' => (string)$item['slug'],
            'resumo' => (string)$item['titulo'],
            'descricao' =>
                'Importado do Calendário de Atividades 2026 - página '
                . (int)($item['pagina_pdf'] ?? 0)
                . ' do PDF.',
            'local' =>
                trim((string)($item['local'] ?? '')) !== ''
                    ? (string)$item['local']
                    : null,
            'data_inicio' => (string)$item['data_inicio'],
            'data_fim' =>
                trim((string)($item['data_fim'] ?? '')) !== ''
                    ? (string)$item['data_fim']
                    : null,
            'santa_ceia' => (int)($item['santa_ceia'] ?? 0),
        ]);

        $processed++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        "[ERRO] Importação cancelada e revertida: "
        . $e->getMessage()
        . "\n"
    );

    exit(1);
}

echo "[OK] {$processed} registros processados.\n";

$counts = $pdo->query(
    "SELECT tipo, COUNT(*) AS total
     FROM eventos
     WHERE slug LIKE 'calendario-2026-%'
     GROUP BY tipo
     ORDER BY tipo"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($counts as $row) {
    echo "[OK] "
        . (string)$row['tipo']
        . ': '
        . (int)$row['total']
        . "\n";
}

$total = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM eventos
     WHERE slug LIKE 'calendario-2026-%'"
)->fetchColumn();

echo "[OK] Total no banco: {$total}\n";

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo str_repeat('=', 72) . "\n";
echo " IMPORTAÇÃO DO CALENDÁRIO 2026 CONCLUÍDA\n";
echo str_repeat('=', 72) . "\n";
