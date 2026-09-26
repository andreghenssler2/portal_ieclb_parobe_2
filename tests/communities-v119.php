<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root =
    dirname(__DIR__);

require_once
    $root
    . DIRECTORY_SEPARATOR
    . 'bootstrap.php';

echo "Portal IECLB Parobé - teste Comunidades v1.1.9\n";
echo str_repeat('=', 88) . "\n";

$errors = 0;

$version =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "[INFO] APP_VERSION: {$version}\n";

if ($version !== '1.1.9') {
    echo "[FALHA] Versão esperada: 1.1.9\n";
    $errors++;
}

if (!class_exists('CommunityProfileService')) {
    echo "[FALHA] CommunityProfileService não carregado.\n";
    $errors++;
} else {
    echo "[OK] CommunityProfileService carregado.\n";
}

try {
    $pdo =
        Database::connection();

    CommunityProfileService::ensureSchema(
        $pdo
    );

    foreach (
        [
            'comunidade_perfis',
            'comunidade_fotos',
        ]
        as $table
    ) {
        $stmt =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );

        $stmt->execute([$table]);

        $ok =
            (int)$stmt->fetchColumn() > 0;

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] Tabela {$table}\n";

        if (!$ok) {
            $errors++;
        }
    }
} catch (Throwable $e) {
    echo '[FALHA] Banco: '
        . $e->getMessage()
        . "\n";

    $errors++;
}

$map =
    CommunityProfileService::mapEmbedUrl([
        'endereco' => 'Rua Teste, 123',
        'cidade' => 'Parobé',
        'uf' => 'RS',
    ]);

$mapOk =
    str_contains(
        $map,
        'www.google.com/maps?q='
    )
    && str_contains(
        $map,
        'output=embed'
    );

echo '['
    . ($mapOk ? 'OK' : 'FALHA')
    . "] Google Maps\n";

if (!$mapOk) {
    $errors++;
}

$wa =
    CommunityProfileService::whatsappUrl(
        '(51) 99999-9999'
    );

$waOk =
    $wa === 'https://wa.me/5551999999999';

echo '['
    . ($waOk ? 'OK' : 'FALHA')
    . "] WhatsApp\n";

if (!$waOk) {
    $errors++;
}

$checks = [
    'bootstrap.php' => [
        'PORTAL_COMMUNITY_PROFILE_V119',
        'CommunityProfileService.php',
    ],
    'admin/comunidades/form.php' => [
        'CommunityProfileService::save',
        'Horários de cultos',
        'Pastor(a) / responsável',
        'foto_ids[]',
        'Ver página pública',
    ],
    'admin/comunidades/index.php' => [
        'CommunityProfileService::ensureSchema',
        'Ver página',
    ],
    'comunidades.php' => [
        'Conhecer comunidade',
        'foto_capa_caminho',
        'horarios_cultos',
    ],
    'comunidade.php' => [
        'Horários de cultos',
        'Próximos eventos',
        'Google Maps',
        'CommunityProfileService::gallery',
        'CommunityProfileService::upcomingEvents',
    ],
];

foreach (
    $checks
    as $relative => $markers
) {
    $file =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative
        );

    $content =
        is_file($file)
            ? (file_get_contents($file) ?: '')
            : '';

    foreach ($markers as $marker) {
        $ok =
            str_contains(
                $content,
                $marker
            );

        echo '['
            . ($ok ? 'OK' : 'FALHA')
            . "] {$relative}: {$marker}\n";

        if (!$ok) {
            $errors++;
        }
    }
}

echo str_repeat('=', 88) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s).\n";
    exit(1);
}

echo "RESULTADO: Comunidades v1.1.9 aprovada.\n";
exit(0);
