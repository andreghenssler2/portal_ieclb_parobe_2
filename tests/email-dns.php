<?php
declare(strict_types=1);

ob_start();
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute pelo terminal.\n");
}
$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';
$pdo = Database::connection();

if (!class_exists('MailDnsHealthService')) {
    fwrite(STDERR, "[FALHA] MailDnsHealthService indisponível.\n");
    exit(1);
}

$r = MailDnsHealthService::report($pdo);
echo "Portal IECLB Parobé - diagnóstico DNS de e-mail\n";
echo str_repeat('=',72) . "\n";
echo "Domínio: " . ($r['domain'] ?: 'não configurado') . "\n";
echo "SPF: " . strtoupper((string)$r['spf']['status']) . " - " . $r['spf']['message'] . "\n";
echo "DKIM: " . strtoupper((string)$r['dkim']['status']) . " - " . $r['dkim']['message'] . "\n";
echo "DMARC: " . strtoupper((string)$r['dmarc']['status']) . " - " . $r['dmarc']['message'] . "\n";
echo "MX: " . strtoupper((string)$r['mx']['status']) . " - " . $r['mx']['message'] . "\n";
echo "Pontuação: " . (int)$r['score'] . "/" . (int)$r['max_score'] . "\n";
foreach ($r['warnings'] as $m) echo "[AVISO] {$m}\n";
foreach ($r['errors'] as $m) echo "[FALHA] {$m}\n";
echo str_repeat('=',72) . "\n";

if (!$r['dns_available'] || $r['domain'] === '') {
    echo "RESULTADO: diagnóstico estrutural indisponível.\n";
    exit(1);
}

echo "RESULTADO: consulta DNS concluída. Avisos indicam ajustes de entregabilidade, não falha do Portal.\n";
exit(0);
