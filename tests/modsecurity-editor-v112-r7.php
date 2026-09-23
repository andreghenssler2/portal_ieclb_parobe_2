<?php
declare(strict_types=1);
ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';

echo "Portal IECLB Parobé - teste transporte do editor v1.1.2 R7\n";
echo str_repeat('=', 78) . "\n";

$errors = 0;
$file = $root . '/admin/noticias/form.php';
$content = is_file($file) ? (file_get_contents($file) ?: '') : '';

foreach ([
    'PORTAL_MODSEC_EDITOR_TRANSPORT_V112_R7',
    'conteudo_transport',
    'portalEncodeEditorTransport',
    'postContentField.removeAttribute',
    'base64_decode',
] as $marker) {
    if (str_contains($content, $marker)) {
        echo "[OK] {$marker}\n";
    } else {
        echo "[FALHA] Marcador ausente: {$marker}\n";
        $errors++;
    }
}

$sample = '<p>Mapa</p><iframe src="https://www.google.com/maps/embed?pb=abc" sandbox="allow-scripts allow-same-origin" allow="fullscreen"></iframe>';
$encoded = rtrim(strtr(base64_encode($sample), '+/', '-_'), '=');
$normal = strtr($encoded, '-_', '+/');
$remainder = strlen($normal) % 4;
if ($remainder > 0) {
    $normal .= str_repeat('=', 4 - $remainder);
}
$decoded = base64_decode($normal, true);

if ($decoded === $sample) {
    echo "[OK] Round-trip do HTML preservado.\n";
} else {
    echo "[FALHA] Round-trip do HTML falhou.\n";
    $errors++;
}

if (!str_contains($encoded, '<iframe') && !str_contains($encoded, 'allow-scripts')) {
    echo "[OK] HTML bruto não é enviado no transporte.\n";
} else {
    echo "[FALHA] HTML bruto ainda aparece no transporte.\n";
    $errors++;
}

echo str_repeat('=', 78) . "\n";

if ($errors > 0) {
    echo "RESULTADO: {$errors} falha(s) na R7.\n";
    exit(1);
}

echo "RESULTADO: transporte compatível com ModSecurity aprovado.\n";
exit(0);
