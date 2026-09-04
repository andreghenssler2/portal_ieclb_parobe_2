<?php

declare(strict_types=1);

/**
 * Resposta manual às mensagens recebidas pelos formulários.
 *
 * O destinatário é sempre descoberto a partir do campo de e-mail configurado
 * no formulário (ou do primeiro campo ativo do tipo e-mail).
 */
final class FormReplyService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS formulario_resposta_replicas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resposta_id BIGINT UNSIGNED NOT NULL,
                formulario_id INT UNSIGNED NOT NULL,
                usuario_id INT NULL,
                usuario_nome VARCHAR(190) NULL,
                destinatario VARCHAR(190) NOT NULL,
                assunto VARCHAR(255) NOT NULL,
                mensagem MEDIUMTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'enviado',
                erro VARCHAR(2000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_form_resp_rep_resposta (resposta_id,created_at),
                KEY idx_form_resp_rep_formulario (formulario_id,created_at),
                KEY idx_form_resp_rep_status (status,created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    /**
     * @return array{
     *   response:array<string,mixed>,
     *   form:array<string,mixed>,
     *   fields:array<int,array<string,mixed>>,
     *   values:array<int,string>,
     *   recipient:string
     * }|null
     */
    public static function context(
        PDO $pdo,
        int $responseId
    ): ?array {
        if ($responseId <= 0) {
            return null;
        }

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formulario_respostas
                 WHERE id=:id
                 LIMIT 1"
            );

        $stmt->execute([
            'id' => $responseId,
        ]);

        $response =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$response) {
            return null;
        }

        $formId =
            (int)(
                $response['formulario_id']
                ?? 0
            );

        if ($formId <= 0) {
            return null;
        }

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formularios
                 WHERE id=:id
                 LIMIT 1"
            );

        $stmt->execute([
            'id' => $formId,
        ]);

        $form =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$form) {
            return null;
        }

        $stmt =
            $pdo->prepare(
                "SELECT
                    c.id,
                    c.nome,
                    c.rotulo,
                    c.tipo,
                    c.ordem,
                    c.obrigatorio,
                    v.valor
                 FROM formulario_resposta_valores v
                 INNER JOIN formulario_campos c
                    ON c.id=v.campo_id
                 WHERE v.resposta_id=:id
                 ORDER BY c.ordem,c.id"
            );

        $stmt->execute([
            'id' => $responseId,
        ]);

        $fields =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        $values = [];

        foreach ($fields as $field) {
            $fieldId =
                (int)(
                    $field['id']
                    ?? 0
                );

            if ($fieldId <= 0) {
                continue;
            }

            $values[$fieldId] =
                trim(
                    (string)(
                        $field['valor']
                        ?? ''
                    )
                );
        }

        $recipient = '';

        if (
            class_exists(
                'FormNotificationService'
            )
        ) {
            $recipient =
                FormNotificationService::visitorEmail(
                    $form,
                    $fields,
                    $values
                );
        }

        return [
            'response' => $response,
            'form' => $form,
            'fields' => $fields,
            'values' => $values,
            'recipient' => $recipient,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   recipient:string,
     *   error:string
     * }
     */
    public static function send(
        PDO $pdo,
        int $responseId,
        string $subject,
        string $message,
        int $userId,
        string $userName
    ): array {
        self::ensureSchema($pdo);

        $context =
            self::context(
                $pdo,
                $responseId
            );

        if (!$context) {
            return [
                'ok' => false,
                'recipient' => '',
                'error' =>
                    'A resposta do formulário não foi encontrada.',
            ];
        }

        $recipient =
            strtolower(
                trim(
                    (string)$context['recipient']
                )
            );

        if (
            $recipient === ''
            || !filter_var(
                $recipient,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return [
                'ok' => false,
                'recipient' => '',
                'error' =>
                    'Não foi encontrado um e-mail válido na resposta recebida.',
            ];
        }

        $subject =
            self::subjectForResponse(
                $responseId,
                $subject
            );

        $message =
            trim(
                $message
            );

        if ($subject === '') {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'Informe o assunto da resposta.',
            ];
        }

        if (
            self::length(
                $subject
            ) > 190
        ) {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'O assunto deve ter no máximo 190 caracteres.',
            ];
        }

        if ($message === '') {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'Digite a mensagem que será enviada.',
            ];
        }

        if (
            self::length(
                $message
            ) > 20000
        ) {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'A mensagem deve ter no máximo 20.000 caracteres.',
            ];
        }

        if (!class_exists('MailService')) {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'O serviço de e-mail não está disponível.',
            ];
        }

        try {
            $issue =
                MailService::configurationIssue(
                    $pdo
                );

            if ($issue !== null) {
                return [
                    'ok' => false,
                    'recipient' => $recipient,
                    'error' => $issue,
                ];
            }
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'recipient' => $recipient,
                'error' =>
                    'Não foi possível validar a configuração de e-mail.',
            ];
        }

        $formTitle =
            trim(
                (string)(
                    $context['form']['titulo']
                    ?? 'Formulário'
                )
            );

        $siteName =
            siteConfig(
                $pdo,
                'site_nome',
                defined('APP_NAME')
                    ? (string)APP_NAME
                    : 'Portal IECLB Parobé'
            );

        $html =
            '<div style="font-size:16px;line-height:1.7">'
            . nl2br(
                self::h(
                    $message
                )
            )
            . '</div>'
            . '<div style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb;'
            . 'font-size:13px;line-height:1.5;color:#6b7280">'
            . 'Esta mensagem foi enviada em resposta ao seu contato pelo formulário '
            . '<strong>'
            . self::h(
                $formTitle
            )
            . '</strong> no '
            . self::h(
                $siteName
            )
            . '.'
            . '</div>';

        $ok = false;
        $error = '';

        try {
            $mailOptions = [];

            $replyMailbox =
                strtolower(
                    trim(
                        siteConfig(
                            $pdo,
                            'inbound_mail_address',
                            siteConfig(
                                $pdo,
                                'mail_reply_to',
                                siteConfig(
                                    $pdo,
                                    'mail_from_email',
                                    ''
                                )
                            )
                        )
                    )
                );

            if (
                $replyMailbox !== ''
                && filter_var(
                    $replyMailbox,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $mailOptions['reply_to'] =
                    $replyMailbox;
            }

            $ok =
                MailService::sendHtml(
                    $pdo,
                    $recipient,
                    $subject,
                    $html,
                    $mailOptions
                );

            if (!$ok) {
                $error =
                    MailService::lastError()
                    ?: 'O servidor de e-mail não aceitou o envio.';
            }
        } catch (Throwable $e) {
            $ok = false;
            $error =
                MailService::lastError()
                ?: 'Falha ao enviar a resposta por e-mail.';
        }

        self::log(
            $pdo,
            $responseId,
            (int)$context['form']['id'],
            $userId,
            $userName,
            $recipient,
            $subject,
            $message,
            $ok
                ? 'enviado'
                : 'erro',
            $ok
                ? null
                : $error
        );

        if ($ok) {
            try {
                $stmt =
                    $pdo->prepare(
                        "UPDATE formulario_respostas
                         SET status='lida'
                         WHERE id=:id"
                    );

                $stmt->execute([
                    'id' => $responseId,
                ]);
            } catch (Throwable $ignored) {
            }

            try {
                $stmt =
                    $pdo->prepare(
                        "INSERT INTO formulario_notificacoes
                            (
                                formulario_id,
                                resposta_id,
                                tipo,
                                destinatario,
                                status,
                                erro,
                                created_at
                            )
                         VALUES
                            (
                                :formulario,
                                :resposta,
                                'resposta_manual',
                                :destinatario,
                                'enviado',
                                NULL,
                                NOW()
                            )"
                    );

                $stmt->execute([
                    'formulario' =>
                        (int)$context['form']['id'],
                    'resposta' =>
                        $responseId,
                    'destinatario' =>
                        $recipient,
                ]);
            } catch (Throwable $ignored) {
            }
        }

        return [
            'ok' => $ok,
            'recipient' => $recipient,
            'error' =>
                $ok
                    ? ''
                    : $error,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function history(
        PDO $pdo,
        int $responseId,
        int $limit = 30
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    100,
                    $limit
                )
            );

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formulario_resposta_replicas
                 WHERE resposta_id=:resposta
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute([
            'resposta' =>
                $responseId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: [];
    }

    public static function subjectForResponse(
        int $responseId,
        string $subject
    ): string {
        $subject =
            self::singleLine(
                $subject
            );

        $subject =
            preg_replace(
                '/\s*\[IECLB-R\d+\]\s*/i',
                ' ',
                $subject
            )
            ?? $subject;

        $subject =
            trim(
                preg_replace(
                    '/\s{2,}/',
                    ' ',
                    $subject
                )
                ?? $subject
            );

        if ($subject === '') {
            $subject =
                'Resposta ao seu contato';
        }

        $token =
            '[IECLB-R'
            . max(
                1,
                $responseId
            )
            . ']';

        $maxBase =
            max(
                20,
                190
                - self::length(
                    $token
                )
                - 1
            );

        return
            self::cut(
                $subject,
                $maxBase
            )
            . ' '
            . $token;
    }

    public static function defaultSubject(
        string $formTitle
    ): string {
        $formTitle =
            self::singleLine(
                $formTitle
            );

        if ($formTitle === '') {
            $formTitle =
                'seu contato';
        }

        return
            self::cut(
                'Resposta: '
                . $formTitle,
                190
            );
    }

    private static function log(
        PDO $pdo,
        int $responseId,
        int $formId,
        int $userId,
        string $userName,
        string $recipient,
        string $subject,
        string $message,
        string $status,
        ?string $error
    ): void {
        try {
            $stmt =
                $pdo->prepare(
                    "INSERT INTO formulario_resposta_replicas
                        (
                            resposta_id,
                            formulario_id,
                            usuario_id,
                            usuario_nome,
                            destinatario,
                            assunto,
                            mensagem,
                            status,
                            erro,
                            created_at
                        )
                     VALUES
                        (
                            :resposta,
                            :formulario,
                            :usuario,
                            :usuario_nome,
                            :destinatario,
                            :assunto,
                            :mensagem,
                            :status,
                            :erro,
                            NOW()
                        )"
                );

            $stmt->execute([
                'resposta' =>
                    $responseId,
                'formulario' =>
                    $formId,
                'usuario' =>
                    $userId > 0
                        ? $userId
                        : null,
                'usuario_nome' =>
                    trim($userName) !== ''
                        ? self::cut(
                            trim($userName),
                            190
                        )
                        : null,
                'destinatario' =>
                    self::cut(
                        $recipient,
                        190
                    ),
                'assunto' =>
                    self::cut(
                        $subject,
                        255
                    ),
                'mensagem' =>
                    self::cut(
                        $message,
                        20000
                    ),
                'status' =>
                    self::cut(
                        $status,
                        20
                    ),
                'erro' =>
                    $error !== null
                    && trim($error) !== ''
                        ? self::cut(
                            trim($error),
                            2000
                        )
                        : null,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    private static function singleLine(
        string $value
    ): string {
        $value =
            preg_replace(
                '/[\r\n\t]+/',
                ' ',
                trim($value)
            )
            ?? '';

        $value =
            preg_replace(
                '/\s{2,}/',
                ' ',
                $value
            )
            ?? $value;

        return
            trim($value);
    }

    private static function h(
        string $value
    ): string {
        return
            htmlspecialchars(
                $value,
                ENT_QUOTES
                | ENT_SUBSTITUTE,
                'UTF-8'
            );
    }

    private static function cut(
        string $value,
        int $length
    ): string {
        return
            function_exists(
                'mb_substr'
            )
                ? mb_substr(
                    $value,
                    0,
                    $length
                )
                : substr(
                    $value,
                    0,
                    $length
                );
    }

    private static function length(
        string $value
    ): int {
        return
            function_exists(
                'mb_strlen'
            )
                ? mb_strlen(
                    $value
                )
                : strlen(
                    $value
                );
    }
}
