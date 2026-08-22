<?php

declare(strict_types=1);

final class MailService
{
    public const PHPMAILER_VERSION = '7.1.1';

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function transport(PDO $pdo): string
    {
        $transport = strtolower(trim(siteConfig($pdo, 'mail_transport', 'smtp')));
        return in_array($transport, ['mail', 'smtp'], true) ? $transport : 'smtp';
    }

    public static function transportLabel(PDO $pdo): string
    {
        return self::transport($pdo) === 'smtp' ? 'SMTP via PHPMailer' : 'PHP mail() via PHPMailer';
    }

    public static function libraryInstalled(): bool
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            return true;
        }

        $base = self::libraryPath();
        return is_file($base . '/Exception.php')
            && is_file($base . '/PHPMailer.php')
            && is_file($base . '/SMTP.php');
    }

    public static function libraryVersion(): string
    {
        try {
            self::loadLibrary();
            return defined('PHPMailer\\PHPMailer\\PHPMailer::VERSION')
                ? (string)constant('PHPMailer\\PHPMailer\\PHPMailer::VERSION')
                : 'instalado';
        } catch (Throwable $e) {
            return 'não instalado';
        }
    }

    public static function configurationIssue(PDO $pdo, array $options = []): ?string
    {
        if (!self::libraryInstalled()) {
            return 'PHPMailer não está instalado. Execute: php atualizar_phpmailer_v0.26.0.php';
        }

        $from = trim((string)($options['from_email'] ?? siteConfig($pdo, 'mail_from_email', siteConfig($pdo, 'site_email', ''))));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'Configure um e-mail remetente válido em Configurações > E-mail.';
        }

        if (self::transport($pdo) === 'mail') {
            return function_exists('mail') ? null : 'A função PHP mail() não está disponível neste servidor. Prefira SMTP.';
        }

        $host = trim(siteConfig($pdo, 'mail_smtp_host', ''));
        $port = (int)siteConfig($pdo, 'mail_smtp_port', '587');
        if ($host === '') {
            return 'Informe o servidor SMTP em Configurações > E-mail.';
        }
        if ($port < 1 || $port > 65535) {
            return 'A porta SMTP configurada é inválida.';
        }
        if (siteConfig($pdo, 'mail_smtp_auth', '1') === '1') {
            if (trim(siteConfig($pdo, 'mail_smtp_username', '')) === '') {
                return 'Informe o usuário SMTP em Configurações > E-mail.';
            }
            if (!self::hasSmtpPassword($pdo)) {
                return 'Informe a senha SMTP em Configurações > E-mail.';
            }
        }
        return null;
    }

    /** @return string[] */
    public static function configurationWarnings(PDO $pdo): array
    {
        $warnings = [];
        if (self::transport($pdo) !== 'smtp') {
            $warnings[] = 'PHP mail() depende de um servidor de e-mail local configurado no PHP. Para hospedagem e produção, prefira SMTP.';
            return $warnings;
        }

        $port = (int)siteConfig($pdo, 'mail_smtp_port', '587');
        $encryption = strtolower(trim(siteConfig($pdo, 'mail_smtp_encryption', 'tls')));

        if ($port === 485) {
            $warnings[] = 'A porta 485 parece incomum. Para SSL/TLS direto (SMTPS), normalmente use 465; para STARTTLS, normalmente use 587.';
        } elseif ($encryption === 'ssl' && $port !== 465) {
            $warnings[] = 'SSL/TLS direto normalmente usa a porta 465. Confirme a porta ' . $port . ' com o provedor de e-mail.';
        } elseif ($encryption === 'tls' && !in_array($port, [25, 587], true)) {
            $warnings[] = 'STARTTLS normalmente usa a porta 587 (ou 25 em alguns servidores). Confirme a porta ' . $port . ' com o provedor.';
        }

        if (siteConfig($pdo, 'mail_smtp_verify_peer', '1') !== '1') {
            $warnings[] = 'A verificação do certificado TLS está desativada. Use isso apenas temporariamente para diagnóstico.';
        }

        return $warnings;
    }

    public static function hasSmtpPassword(PDO $pdo): bool
    {
        return trim(siteConfig($pdo, 'mail_smtp_password', '')) !== '';
    }

    public static function setSmtpPassword(PDO $pdo, string $plainPassword): void
    {
        if ($plainPassword === '') {
            saveSiteConfig($pdo, 'mail_smtp_password', '', 'secreto');
            return;
        }
        saveSiteConfig($pdo, 'mail_smtp_password', self::encryptSecret($plainPassword), 'secreto');
    }

    public static function sendHtml(PDO $pdo, string $to, string $subject, string $html, array $options = []): bool
    {
        self::$lastError = '';
        $to = trim($to);
        $subject = self::singleLine(trim($subject));
        $transport = self::transport($pdo);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Destinatário inválido.';
            self::logAttempt($pdo, $to, $subject, $transport, false, self::$lastError, null);
            return false;
        }
        if ($subject === '') {
            self::$lastError = 'O assunto do e-mail está vazio.';
            self::logAttempt($pdo, $to, $subject, $transport, false, self::$lastError, null);
            return false;
        }

        $fromEmail = trim((string)($options['from_email'] ?? siteConfig($pdo, 'mail_from_email', siteConfig($pdo, 'site_email', ''))));
        $fromName = self::singleLine(trim((string)($options['from_name'] ?? siteConfig($pdo, 'mail_from_name', siteConfig($pdo, 'site_nome', defined('APP_NAME') ? APP_NAME : 'Portal IECLB Parobé')))));
        $replyTo = trim((string)($options['reply_to'] ?? siteConfig($pdo, 'mail_reply_to', $fromEmail)));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'E-mail remetente inválido.';
            self::logAttempt($pdo, $to, $subject, $transport, false, self::$lastError, null);
            return false;
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'E-mail de resposta inválido.';
            self::logAttempt($pdo, $to, $subject, $transport, false, self::$lastError, null);
            return false;
        }

        $siteName = siteConfig($pdo, 'site_nome', defined('APP_NAME') ? APP_NAME : 'Portal IECLB Parobé');
        $wrappedHtml = self::wrapHtml($html, $siteName);
        $messageId = self::messageId($fromEmail);

        try {
            self::loadLibrary();
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            self::configureCommonMailer($mail, $to, $subject, $wrappedHtml, $fromEmail, $fromName, $replyTo, $messageId);

            if ($transport === 'smtp') {
                self::configureSmtpMailer($mail, $pdo);
            } else {
                $mail->isMail();
            }

            $ok = $mail->send();
            if (!$ok) {
                self::$lastError = self::friendlyMailerError($mail->ErrorInfo ?: 'O servidor não aceitou o envio da mensagem.');
            }
            self::logAttempt($pdo, $to, $subject, $transport, $ok, $ok ? null : self::$lastError, $messageId);
            return $ok;
        } catch (Throwable $e) {
            self::$lastError = self::friendlyMailerError($e->getMessage());
            self::logAttempt($pdo, $to, $subject, $transport, false, self::$lastError, $messageId);
            return false;
        }
    }

    /**
     * Testa DNS, conexão, TLS e autenticação SMTP sem enviar mensagem.
     *
     * @return array{ok:bool,summary:string,host:string,port:int,encryption:string,ips:array<int,string>,debug:array<int,string>}
     */
    public static function diagnoseSmtp(PDO $pdo): array
    {
        self::$lastError = '';
        self::loadLibrary();

        $host = trim(siteConfig($pdo, 'mail_smtp_host', ''));
        $port = max(1, min(65535, (int)siteConfig($pdo, 'mail_smtp_port', '587')));
        $encryption = strtolower(trim(siteConfig($pdo, 'mail_smtp_encryption', 'tls')));
        $username = trim(siteConfig($pdo, 'mail_smtp_username', ''));
        $password = siteConfig($pdo, 'mail_smtp_auth', '1') === '1' ? self::smtpPassword($pdo) : '';
        $debug = [];

        if ($host === '') {
            throw new RuntimeException('Servidor SMTP não configurado.');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } elseif (function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = array_values(array_unique($resolved));
            }
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        self::configureSmtpMailer($mail, $pdo);
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
        $mail->Debugoutput = static function ($line, $level) use (&$debug, $username, $password): void {
            $clean = self::redactDebug((string)$line, [$username, $password]);
            if ($clean !== '') {
                $debug[] = '[' . (int)$level . '] ' . $clean;
                if (count($debug) > 120) {
                    array_shift($debug);
                }
            }
        };

        try {
            $ok = $mail->smtpConnect();
            if (!$ok) {
                $message = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'Falha ao conectar/autenticar no SMTP.';
                throw new RuntimeException($message);
            }
            $mail->smtpClose();

            return [
                'ok' => true,
                'summary' => 'Conexão, criptografia e autenticação SMTP concluídas com sucesso.',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'ips' => $ips,
                'debug' => $debug,
            ];
        } catch (Throwable $e) {
            try {
                $mail->smtpClose();
            } catch (Throwable $ignored) {
            }
            return [
                'ok' => false,
                'summary' => self::friendlyMailerError($e->getMessage()),
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'ips' => $ips,
                'debug' => $debug,
            ];
        }
    }

    public static function cleanupLogs(PDO $pdo): void
    {
        try {
            $days = max(7, min(3650, (int)siteConfig($pdo, 'mail_log_retention_days', '90')));
            $pdo->exec('DELETE FROM email_envios WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        } catch (Throwable $ignored) {
        }
    }

    private static function configureCommonMailer(
        \PHPMailer\PHPMailer\PHPMailer $mail,
        string $to,
        string $subject,
        string $html,
        string $fromEmail,
        string $fromName,
        string $replyTo,
        string $messageId
    ): void {
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
        $mail->setFrom($fromEmail, $fromName);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = self::htmlToText($html);
        $mail->MessageID = '<' . $messageId . '>';
        $mail->XMailer = 'Portal IECLB/' . (defined('APP_VERSION') ? APP_VERSION : 'dev') . ' PHPMailer/' . self::PHPMAILER_VERSION;
    }

    private static function configureSmtpMailer(\PHPMailer\PHPMailer\PHPMailer $mail, PDO $pdo): void
    {
        $host = trim(siteConfig($pdo, 'mail_smtp_host', ''));
        $port = max(1, min(65535, (int)siteConfig($pdo, 'mail_smtp_port', '587')));
        $encryption = strtolower(trim(siteConfig($pdo, 'mail_smtp_encryption', 'tls')));
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $encryption = 'tls';
        }
        $timeout = max(3, min(60, (int)siteConfig($pdo, 'mail_timeout_seconds', '15')));
        $verifyPeer = siteConfig($pdo, 'mail_smtp_verify_peer', '1') === '1';
        $auth = siteConfig($pdo, 'mail_smtp_auth', '1') === '1';
        $username = trim(siteConfig($pdo, 'mail_smtp_username', ''));
        $password = $auth ? self::smtpPassword($pdo) : '';

        if ($host === '') {
            throw new RuntimeException('Servidor SMTP não configurado.');
        }
        if ($auth && ($username === '' || $password === '')) {
            throw new RuntimeException('Usuário ou senha SMTP não configurados.');
        }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = $auth;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Timeout = $timeout;
        $mail->SMTPAutoTLS = false;

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
        }

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
                'peer_name' => $host,
            ],
        ];
    }

    private static function loadLibrary(): void
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            return;
        }

        $base = self::libraryPath();
        $files = [$base . '/Exception.php', $base . '/PHPMailer.php', $base . '/SMTP.php'];
        foreach ($files as $file) {
            if (!is_file($file)) {
                throw new RuntimeException(
                    'PHPMailer não está instalado. Extraia a correção e execute: php atualizar_phpmailer_v0.26.0.php'
                );
            }
            require_once $file;
        }

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new RuntimeException('PHPMailer foi encontrado, mas não pôde ser carregado.');
        }

        $version = (string)\PHPMailer\PHPMailer\PHPMailer::VERSION;
        if (version_compare($version, '7.1.1', '<')) {
            throw new RuntimeException('PHPMailer ' . $version . ' é antigo. Instale a versão 7.1.1 ou superior.');
        }
    }

    private static function libraryPath(): string
    {
        return dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src';
    }

    private static function smtpPassword(PDO $pdo): string
    {
        $encrypted = trim(siteConfig($pdo, 'mail_smtp_password', ''));
        if ($encrypted === '') {
            return '';
        }
        return self::decryptSecret($encrypted);
    }

    private static function encryptSecret(string $plain): string
    {
        $key = self::secretKey(true);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
            return 's1:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                throw new RuntimeException('Não foi possível criptografar a senha SMTP.');
            }
            return 'o1:' . base64_encode($iv . $tag . $cipher);
        }
        throw new RuntimeException('O servidor precisa da extensão Sodium ou OpenSSL para armazenar a senha SMTP com segurança.');
    }

    private static function decryptSecret(string $encoded): string
    {
        $key = self::secretKey(false);
        if ($key === '') {
            throw new RuntimeException('A chave privada do SMTP não foi encontrada. Informe novamente a senha em Configurações > E-mail.');
        }
        if (str_starts_with($encoded, 's1:')) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('A extensão Sodium necessária para ler a senha SMTP não está disponível.');
            }
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('Senha SMTP criptografada inválida.');
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException('Não foi possível descriptografar a senha SMTP. Informe-a novamente.');
            }
            return $plain;
        }
        if (str_starts_with($encoded, 'o1:')) {
            if (!function_exists('openssl_decrypt')) {
                throw new RuntimeException('A extensão OpenSSL necessária para ler a senha SMTP não está disponível.');
            }
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) <= 28) {
                throw new RuntimeException('Senha SMTP criptografada inválida.');
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new RuntimeException('Não foi possível descriptografar a senha SMTP. Informe-a novamente.');
            }
            return $plain;
        }
        throw new RuntimeException('Formato da senha SMTP desconhecido. Informe a senha novamente.');
    }

    private static function secretKey(bool $create): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/private';
        $file = $dir . '/mail.key';
        if (!is_dir($dir) && $create && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar storage/private para proteger a senha SMTP.');
        }
        if (is_file($file)) {
            $raw = base64_decode(trim((string)file_get_contents($file)), true);
            if ($raw !== false && strlen($raw) === 32) {
                return $raw;
            }
            throw new RuntimeException('A chave privada de e-mail é inválida.');
        }
        if (!$create) {
            return '';
        }
        $key = random_bytes(32);
        if (@file_put_contents($file, base64_encode($key) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar a chave privada de e-mail.');
        }
        @chmod($file, 0600);
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
        }
        return $key;
    }

    private static function logAttempt(PDO $pdo, string $to, string $subject, string $transport, bool $ok, ?string $error, ?string $messageId): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO email_envios (usuario_id,transport,destinatario,assunto,status,erro,message_id,created_at)
                 VALUES (:usuario,:transport,:destinatario,:assunto,:status,:erro,:message_id,NOW())'
            );
            $stmt->execute([
                'usuario' => class_exists('Auth') ? Auth::id() : null,
                'transport' => $transport,
                'destinatario' => self::truncate($to, 190),
                'assunto' => self::truncate($subject, 255),
                'status' => $ok ? 'enviado' : 'falhou',
                'erro' => $error !== null ? self::truncate($error, 2000) : null,
                'message_id' => $messageId !== null ? self::truncate($messageId, 255) : null,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    private static function messageId(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1) ?: 'localhost';
        return bin2hex(random_bytes(12)) . '.' . time() . '@' . preg_replace('/[^a-z0-9.-]/i', '', $domain);
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function wrapHtml(string $html, string $siteName): string
    {
        if (stripos($html, '<html') !== false) {
            return $html;
        }
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')
            . '</title></head><body style="font-family:Arial,sans-serif;line-height:1.55;color:#222;margin:0;padding:24px">'
            . $html . '</body></html>';
    }

    private static function friendlyMailerError(string $error): string
    {
        $clean = self::safeError($error);
        $lower = strtolower($clean);

        if (str_contains($lower, 'could not instantiate mail function')) {
            return 'O PHP mail() não está configurado/funcionando neste servidor. Use SMTP via PHPMailer ou configure o serviço de e-mail local. Detalhe: ' . $clean;
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, 'actively refused') || str_contains($lower, 'recusou ativamente')) {
            return 'O servidor recusou a conexão SMTP. Verifique principalmente host, porta e firewall. Para SSL/TLS direto normalmente use 465; para STARTTLS, 587. Detalhe: ' . $clean;
        }
        if (str_contains($lower, 'could not connect') || str_contains($lower, 'connect() failed') || str_contains($lower, 'failed to connect')) {
            return 'Não foi possível conectar ao SMTP. Verifique DNS, servidor, porta, criptografia e bloqueio de saída da hospedagem. Detalhe: ' . $clean;
        }
        if (str_contains($lower, 'authenticate') || str_contains($lower, 'authentication')) {
            return 'O SMTP respondeu, mas a autenticação falhou. Confira usuário e senha SMTP. Detalhe: ' . $clean;
        }
        if (str_contains($lower, 'certificate') || str_contains($lower, 'tls')) {
            return 'Falha na negociação TLS/certificado. Confira a criptografia e se o certificado corresponde ao servidor SMTP. Detalhe: ' . $clean;
        }

        return $clean;
    }

    /** @param string[] $secrets */
    private static function redactDebug(string $line, array $secrets): string
    {
        $clean = trim(preg_replace('/[\r\n]+/', ' ', $line) ?? $line);
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $clean = str_ireplace($secret, '[oculto]', $clean);
            $encoded = base64_encode($secret);
            if ($encoded !== '') {
                $clean = str_replace($encoded, '[oculto-base64]', $clean);
            }
        }
        return self::truncate($clean, 1000);
    }

    private static function safeError(string $error): string
    {
        $clean = trim(preg_replace('/[\r\n]+/', ' ', $error) ?? $error);
        return self::truncate($clean, 1200);
    }

    private static function singleLine(string $text): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $text));
    }

    private static function truncate(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
