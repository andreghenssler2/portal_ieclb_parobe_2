<?php

declare(strict_types=1);

const PHPMAILER_TARGET = '7.1.1';

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function failUpdate(string $message): never
{
    line('[ERRO] ' . $message);
    exit(1);
}

function httpGet(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Portal-IECLB-PHPMailer-Installer/0.26.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('Falha ao baixar ' . $url . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')'));
        }
        return $body;
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'Portal-IECLB-PHPMailer-Installer/0.26.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Falha ao baixar ' . $url . ' usando file_get_contents().');
        }
        return $body;
    }

    throw new RuntimeException('O servidor não possui cURL e allow_url_fopen está desativado. Instale o PHPMailer manualmente em vendor/phpmailer/phpmailer/.');
}

function gitBlobSha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function atomicWrite(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta ' . $dir);
    }

    if (is_file($path)) {
        $backup = $path . '.bak-' . date('Ymd-His');
        if (!copy($path, $backup)) {
            throw new RuntimeException('Não foi possível criar backup de ' . $path);
        }
    }

    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar ' . $tmp);
    }
    @chmod($tmp, 0644);

    if (is_file($path) && !@unlink($path)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível substituir ' . $path);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível finalizar a gravação de ' . $path);
    }
}

$root = __DIR__;
$files = [
    'src/Exception.php' => [
        'url' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/refs/tags/v7.1.1/src/Exception.php',
        'sha' => '09c1a2cfefaa986c846b41743e856c945beff3cd',
    ],
    'src/PHPMailer.php' => [
        'url' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/refs/tags/v7.1.1/src/PHPMailer.php',
        'sha' => '4900cbc43afef9e6bdbc4ae5a862c26d0d496924',
    ],
    'src/SMTP.php' => [
        'url' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/refs/tags/v7.1.1/src/SMTP.php',
        'sha' => 'f0957b80a919f793be8ee8aa5a3b914655fc8ed5',
    ],
    'LICENSE' => [
        'url' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/refs/tags/v7.1.1/LICENSE',
        'sha' => 'f166cc57b2783565bc48e8999103c572fca4c0e4',
    ],
];
$vendor = $root . '/vendor/phpmailer/phpmailer';

line('Portal IECLB Parobé - correção de e-mail com PHPMailer ' . PHPMAILER_TARGET);
line(str_repeat('-', 76));

foreach (['app/Services/MailService.php', 'admin/configuracoes/email.php'] as $required) {
    if (!is_file($root . '/' . $required)) {
        failUpdate('Arquivo da correção não encontrado: ' . $required);
    }
}

try {
    foreach ($files as $relative => $meta) {
        $target = $vendor . '/' . $relative;
        if (is_file($target)) {
            $current = (string)file_get_contents($target);
            if (gitBlobSha($current) === $meta['sha']) {
                line('[OK] ' . $relative . ' já está correto.');
                continue;
            }
        }

        line('[...] Baixando ' . $relative . ' da versão oficial v' . PHPMAILER_TARGET . '...');
        $content = httpGet($meta['url']);
        $actual = gitBlobSha($content);
        if (!hash_equals($meta['sha'], $actual)) {
            throw new RuntimeException('Integridade inválida em ' . $relative . '. Esperado ' . $meta['sha'] . ', recebido ' . $actual . '.');
        }
        atomicWrite($target, $content);
        line('[OK] ' . $relative . ' instalado e verificado.');
    }

    require_once $vendor . '/src/Exception.php';
    require_once $vendor . '/src/PHPMailer.php';
    require_once $vendor . '/src/SMTP.php';

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('A classe PHPMailer não pôde ser carregada após a instalação.');
    }
    $version = (string)\PHPMailer\PHPMailer\PHPMailer::VERSION;
    if ($version !== PHPMAILER_TARGET) {
        throw new RuntimeException('Versão carregada inesperada: ' . $version . '.');
    }
    line('[OK] PHPMailer ' . $version . ' carregado corretamente.');

    $config = $root . '/config/config.php';
    $dbFile = $root . '/mod/db/Database.php';
    if (is_file($config) && is_file($dbFile)) {
        try {
            require_once $config;
            require_once $dbFile;
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('mail_smtp_port','mail_smtp_encryption','mail_smtp_host')");
            $stmt->execute();
            $cfg = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cfg[(string)$row['chave']] = (string)$row['valor'];
            }
            $port = (int)($cfg['mail_smtp_port'] ?? 0);
            $enc = strtolower($cfg['mail_smtp_encryption'] ?? '');
            if ($port === 485) {
                line('[AVISO] Sua configuração atual usa porta 485. Para SSL/TLS direto normalmente use 465; STARTTLS normalmente 587.');
            } elseif ($enc === 'ssl' && $port !== 0 && $port !== 465) {
                line('[AVISO] SSL/TLS direto está configurado na porta ' . $port . '. Confirme com o provedor; o padrão costuma ser 465.');
            } elseif ($enc === 'tls' && $port !== 0 && !in_array($port, [25, 587], true)) {
                line('[AVISO] STARTTLS está configurado na porta ' . $port . '. Confirme com o provedor; o padrão costuma ser 587.');
            }
        } catch (Throwable $e) {
            line('[AVISO] Não foi possível verificar a configuração SMTP atual: ' . $e->getMessage());
        }
    }

    line(str_repeat('-', 76));
    line('Correção concluída. Não há alteração no banco nem no APP_VERSION.');
    line('Abra Configurações > E-mail e use “Diagnosticar SMTP” antes de enviar o teste.');
    line('Sugestão inicial: 465 + SSL/TLS direto OU 587 + STARTTLS, conforme o provedor.');
} catch (Throwable $e) {
    failUpdate($e->getMessage());
}
