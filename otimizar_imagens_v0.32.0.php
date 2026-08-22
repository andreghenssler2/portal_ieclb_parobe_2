<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute somente pelo terminal.\n");
}

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$force = in_array('--force', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
        $limit = max(1, (int)$m[1]);
    }
}

if (ImageOptimizationService::driver() === 'none') {
    fwrite(STDERR, "[ERRO] GD ou Imagick não está disponível no PHP.\n");
    exit(1);
}

$sql = "SELECT m.id,m.nome_original FROM midias m
        WHERE m.mime_type IN ('image/jpeg','image/png','image/webp')";
if (!$force) {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM midia_variantes v WHERE v.midia_id=m.id)";
}
$sql .= ' ORDER BY m.id ASC';
if ($limit > 0) $sql .= ' LIMIT ' . $limit;
$rows = $pdo->query($sql)->fetchAll() ?: [];

$total = count($rows);
echo 'Portal IECLB Parobé - Otimização de imagens v0.32.0' . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;
echo 'Motor: ' . ImageOptimizationService::driverLabel() . ' | WebP: ' . (ImageOptimizationService::webpSupported() ? 'sim' : 'não') . PHP_EOL;
echo 'Imagens a processar: ' . $total . ($force ? ' (regeneração)' : '') . PHP_EOL . PHP_EOL;

$ok = 0;
$fail = 0;
foreach ($rows as $i => $row) {
    $id = (int)$row['id'];
    $name = (string)$row['nome_original'];
    echo '[' . ($i + 1) . '/' . $total . '] #' . $id . ' ' . $name . ' ... ';
    try {
        $result = ImageOptimizationService::optimizeMedia($pdo, $id, $force);
        if ($result['ok']) {
            $ok++;
            echo 'OK - ' . $result['message'] . PHP_EOL;
        } else {
            $fail++;
            echo 'AVISO - ' . $result['message'] . PHP_EOL;
        }
    } catch (Throwable $e) {
        $fail++;
        echo 'ERRO - ' . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
echo 'Concluído. Sucesso: ' . $ok . ' | Avisos/falhas: ' . $fail . PHP_EOL;
exit($fail > 0 && $ok === 0 ? 1 : 0);
