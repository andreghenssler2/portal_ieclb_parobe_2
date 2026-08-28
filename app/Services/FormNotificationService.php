<?php

declare(strict_types=1);

final class FormNotificationService
{
    /**
     * Envia as notificações configuradas para uma resposta já persistida.
     * Falhas de e-mail não devem invalidar a resposta do formulário.
     *
     * @param array<string,mixed> $form
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,string> $values Valores indexados por campo_id.
     * @return array{admin_sent:int,admin_failed:int,auto_sent:int,auto_failed:int}
     */
    public static function notifyResponse(
        PDO $pdo,
        array $form,
        array $fields,
        array $values,
        int $responseId
    ): array {
        $result = [
            'admin_sent' => 0,
            'admin_failed' => 0,
            'auto_sent' => 0,
            'auto_failed' => 0,
        ];

        if (!class_exists('MailService')) {
            return $result;
        }

        $formId = (int)($form['id'] ?? 0);
        if ($formId <= 0 || $responseId <= 0) {
            return $result;
        }

        $visitorEmail = self::visitorEmail($form, $fields, $values);
        $tokens = self::tokens($form, $fields, $values, $responseId);
        $fieldsHtml = self::fieldsTableHtml($fields, $values);

        if (!empty($form['notificar_email'])) {
            $recipients = self::parseRecipients((string)($form['emails_notificacao'] ?? ''));
            $subjectTemplate = trim((string)($form['assunto_notificacao'] ?? ''));
            if ($subjectTemplate === '') {
                $subjectTemplate = 'Nova resposta: {{formulario}}';
            }
            $subject = self::renderPlain($subjectTemplate, $tokens);

            $body = '<h2>Nova resposta recebida</h2>'
                . '<p>O formulário <strong>' . self::h((string)($form['titulo'] ?? 'Formulário')) . '</strong> recebeu uma nova resposta.</p>'
                . '<p><strong>Resposta:</strong> #' . $responseId . '<br>'
                . '<strong>Data:</strong> ' . self::h(date('d/m/Y H:i:s')) . '</p>'
                . $fieldsHtml
                . '<p style="margin-top:24px"><a href="' . self::h(url('admin/formularios/resposta.php?id=' . $responseId)) . '">Abrir resposta no painel</a></p>';

            if (!$recipients) {
                self::log(
                    $pdo,
                    $formId,
                    $responseId,
                    'administrador',
                    '',
                    'ignorado',
                    'Notificação administrativa ativa, mas nenhum destinatário válido foi configurado.'
                );
            }

            foreach ($recipients as $recipient) {
                $options = [];
                if ($visitorEmail !== '') {
                    $options['reply_to'] = $visitorEmail;
                }

                $ok = false;
                try {
                    $ok = MailService::sendHtml($pdo, $recipient, $subject, $body, $options);
                } catch (Throwable $e) {
                    $ok = false;
                }

                if ($ok) {
                    $result['admin_sent']++;
                    self::log($pdo, $formId, $responseId, 'administrador', $recipient, 'enviado', null);
                } else {
                    $result['admin_failed']++;
                    self::log(
                        $pdo,
                        $formId,
                        $responseId,
                        'administrador',
                        $recipient,
                        'erro',
                        MailService::lastError() ?: 'Falha ao enviar a notificação.'
                    );
                }
            }
        }

        if (!empty($form['resposta_automatica'])) {
            if ($visitorEmail === '') {
                self::log(
                    $pdo,
                    $formId,
                    $responseId,
                    'resposta_automatica',
                    '',
                    'ignorado',
                    'Não foi encontrado um e-mail válido na resposta.'
                );
                return $result;
            }

            $subjectTemplate = trim((string)($form['assunto_resposta_automatica'] ?? ''));
            if ($subjectTemplate === '') {
                $subjectTemplate = 'Recebemos sua mensagem - {{formulario}}';
            }

            $messageTemplate = trim((string)($form['mensagem_resposta_automatica'] ?? ''));
            if ($messageTemplate === '') {
                $messageTemplate = "Olá!\n\nRecebemos sua mensagem enviada pelo formulário {{formulario}}.\n\nEm breve entraremos em contato.\n\n{{site_nome}}";
            }

            $subject = self::renderPlain($subjectTemplate, $tokens);
            $message = self::renderPlain($messageTemplate, $tokens);
            $body = '<div style="font-size:16px;line-height:1.65">'
                . nl2br(self::h($message))
                . '</div>';

            $ok = false;
            try {
                $ok = MailService::sendHtml($pdo, $visitorEmail, $subject, $body);
            } catch (Throwable $e) {
                $ok = false;
            }

            if ($ok) {
                $result['auto_sent']++;
                self::log($pdo, $formId, $responseId, 'resposta_automatica', $visitorEmail, 'enviado', null);
            } else {
                $result['auto_failed']++;
                self::log(
                    $pdo,
                    $formId,
                    $responseId,
                    'resposta_automatica',
                    $visitorEmail,
                    'erro',
                    MailService::lastError() ?: 'Falha ao enviar a resposta automática.'
                );
            }
        }

        return $result;
    }

    /** @return string[] */
    public static function parseRecipients(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $valid = [];

        foreach ($parts as $part) {
            $email = strtolower(trim((string)$part));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[$email] = $email;
            }
        }

        return array_values($valid);
    }

    /**
     * Retorna o e-mail do visitante conforme o campo configurado.
     * Se nenhum campo estiver escolhido, usa o primeiro campo ativo do tipo email.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,string> $values
     */
    public static function visitorEmail(array $form, array $fields, array $values): string
    {
        $preferred = (int)($form['campo_email_resposta_id'] ?? 0);

        if ($preferred > 0) {
            foreach ($fields as $field) {
                if ((int)($field['id'] ?? 0) !== $preferred || (string)($field['tipo'] ?? '') !== 'email') {
                    continue;
                }
                $email = strtolower(trim((string)($values[$preferred] ?? '')));
                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
            }
        }

        foreach ($fields as $field) {
            if ((string)($field['tipo'] ?? '') !== 'email') {
                continue;
            }
            $id = (int)($field['id'] ?? 0);
            $email = strtolower(trim((string)($values[$id] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentLogs(PDO $pdo, int $formId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));

        $stmt = $pdo->prepare(
            "SELECT *
             FROM formulario_notificacoes
             WHERE formulario_id=:formulario
             ORDER BY id DESC
             LIMIT :limite"
        );
        $stmt->bindValue(':formulario', $formId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,string> $values
     * @return array<string,string>
     */
    private static function tokens(array $form, array $fields, array $values, int $responseId): array
    {
        $tokens = [
            'formulario' => (string)($form['titulo'] ?? 'Formulário'),
            'resposta_id' => (string)$responseId,
            'data' => date('d/m/Y H:i:s'),
            'site_nome' => defined('APP_NAME') ? (string)APP_NAME : 'Portal IECLB Parobé',
        ];

        foreach ($fields as $field) {
            $id = (int)($field['id'] ?? 0);
            $name = trim((string)($field['nome'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $tokens[$name] = trim((string)($values[$id] ?? ''));
        }

        return $tokens;
    }

    /** @param array<string,string> $tokens */
    private static function renderPlain(string $template, array $tokens): string
    {
        $result = $template;
        foreach ($tokens as $name => $value) {
            $result = str_replace('{{' . $name . '}}', $value, $result);
        }

        // Remove placeholders desconhecidos para não expor marcação interna.
        $result = preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $result) ?? $result;
        return trim($result);
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,string> $values
     */
    private static function fieldsTableHtml(array $fields, array $values): string
    {
        $rows = '';

        foreach ($fields as $field) {
            $id = (int)($field['id'] ?? 0);
            $label = trim((string)($field['rotulo'] ?? 'Campo'));
            $value = trim((string)($values[$id] ?? ''));
            if ($value === '') {
                $value = '—';
            }

            $rows .= '<tr>'
                . '<th style="text-align:left;vertical-align:top;padding:8px;border-bottom:1px solid #ddd;width:35%">'
                . self::h($label)
                . '</th>'
                . '<td style="vertical-align:top;padding:8px;border-bottom:1px solid #ddd;white-space:pre-wrap">'
                . nl2br(self::h($value))
                . '</td>'
                . '</tr>';
        }

        return '<table role="presentation" style="width:100%;border-collapse:collapse;margin-top:20px">'
            . $rows
            . '</table>';
    }

    private static function log(
        PDO $pdo,
        int $formId,
        int $responseId,
        string $type,
        string $recipient,
        string $status,
        ?string $error
    ): void {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO formulario_notificacoes
                    (formulario_id,resposta_id,tipo,destinatario,status,erro,created_at)
                 VALUES
                    (:formulario,:resposta,:tipo,:destinatario,:status,:erro,NOW())"
            );
            $stmt->execute([
                'formulario' => $formId,
                'resposta' => $responseId,
                'tipo' => $type,
                'destinatario' => $recipient !== '' ? self::cut($recipient, 190) : null,
                'status' => $status,
                'erro' => $error !== null && $error !== '' ? self::cut($error, 2000) : null,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
