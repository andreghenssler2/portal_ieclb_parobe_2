<?php

declare(strict_types=1);

final class MailOperationsService
{
    /** @return array<string,mixed> */
    public static function health(PDO $pdo): array
    {
        $dns = MailDnsHealthService::report($pdo);
        $queue = MailRetryQueueService::stats($pdo);

        $failed24 = 0;
        $failed7d = 0;
        $sent24 = 0;
        $recentFailures = [];

        try {
            $failed24 = (int)$pdo->query(
                "SELECT COUNT(*)
                 FROM email_envios
                 WHERE status <> 'enviado'
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)"
            )->fetchColumn();

            $failed7d = (int)$pdo->query(
                "SELECT COUNT(*)
                 FROM email_envios
                 WHERE status <> 'enviado'
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"
            )->fetchColumn();

            $sent24 = (int)$pdo->query(
                "SELECT COUNT(*)
                 FROM email_envios
                 WHERE status='enviado'
                   AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)"
            )->fetchColumn();

            $recentFailures = $pdo->query(
                "SELECT *
                 FROM email_envios
                 WHERE status <> 'enviado'
                 ORDER BY id DESC
                 LIMIT 30"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ignored) {
        }

        return [
            'transport' => MailService::transportLabel($pdo),
            'issue' => MailService::configurationIssue($pdo),
            'warnings' => MailService::configurationWarnings($pdo),
            'dns' => $dns,
            'queue' => $queue,
            'failed_24h' => $failed24,
            'failed_7d' => $failed7d,
            'sent_24h' => $sent24,
            'recent_failures' => $recentFailures,
        ];
    }

    /** @return array<string,mixed> */
    public static function advancedTest(
        PDO $pdo,
        string $to
    ): array {
        $to = strtolower(trim($to));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um destinatário válido.');
        }

        $started = microtime(true);
        $issue = MailService::configurationIssue($pdo);
        $warnings = MailService::configurationWarnings($pdo);
        $dns = MailDnsHealthService::report($pdo);
        $smtp = null;

        if (MailService::transport($pdo) === 'smtp') {
            $smtp = MailService::diagnoseSmtp($pdo);
        }

        $token = strtoupper(bin2hex(random_bytes(3)));

        $html =
            '<h2>Teste avançado de e-mail</h2>'
            . '<p>O Portal executou revisão de configuração, SMTP e DNS antes deste envio.</p>'
            . '<p><strong>Código do teste:</strong> ' . e($token) . '</p>'
            . '<p><strong>Data:</strong> ' . e(date('d/m/Y H:i:s')) . '</p>';

        $sent = false;
        $sendError = '';

        if ($issue === null) {
            $sent = MailService::sendHtml(
                $pdo,
                $to,
                'Teste avançado de e-mail [' . $token . ']',
                $html,
                [
                    'queue_on_failure' => false,
                ]
            );

            if (!$sent) {
                $sendError = MailService::lastError();
            }
        }

        return [
            'ok' => $issue === null
                && ($smtp === null || !empty($smtp['ok']))
                && $sent,
            'token' => $token,
            'recipient' => $to,
            'configuration_issue' => $issue,
            'configuration_warnings' => $warnings,
            'smtp' => $smtp,
            'dns' => $dns,
            'sent' => $sent,
            'send_error' => $sendError,
            'duration_ms' => max(
                0,
                (int)round((microtime(true) - $started) * 1000)
            ),
        ];
    }

    /** @return array<int,string> */
    public static function dnsActions(array $report): array
    {
        $actions = [];

        $spf = (array)($report['spf'] ?? []);
        $dkim = (array)($report['dkim'] ?? []);
        $dmarc = (array)($report['dmarc'] ?? []);

        if (($spf['status'] ?? '') !== 'ok') {
            $actions[] =
                'SPF: revise o TXT do domínio remetente. Deve existir apenas um registro v=spf1 e ele precisa autorizar exatamente os servidores do seu provedor.';
        }

        if (($dkim['status'] ?? '') !== 'ok') {
            $host = trim((string)($dkim['host'] ?? ''));
            $actions[] =
                'DKIM: confirme o seletor e publique a chave/CNAME fornecida pelo provedor'
                . ($host !== '' ? ' em ' . $host : '')
                . '.';
        }

        if (($dmarc['status'] ?? '') !== 'ok') {
            $actions[] =
                'DMARC: publique um único TXT em _dmarc.<domínio> começando por v=DMARC1; e defina a política conforme sua operação.';
        } elseif (($dmarc['policy'] ?? '') === 'none') {
            $actions[] =
                'DMARC: p=none está em modo de monitoramento. Só avance para quarantine/reject depois de validar todos os remetentes legítimos.';
        }

        if (!$actions) {
            $actions[] =
                'SPF, DKIM e DMARC foram localizados. Compare os registros com a documentação do seu provedor sempre que trocar servidor SMTP.';
        }

        return $actions;
    }
}
