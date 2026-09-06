<?php

declare(strict_types=1);

/**
 * Sincroniza respostas recebidas por e-mail com as respostas de formulários.
 *
 * Correlação:
 * - todo e-mail enviado pelo FormReplyService recebe o token [IECLB-R<ID>];
 * - uma resposta recebida só é vinculada se o remetente for o mesmo e-mail
 *   informado no formulário original.
 *
 * Credenciais:
 * - usa o mesmo usuário/senha SMTP já protegidos pelo MailService;
 * - permite host/porta/pasta IMAP próprios.
 */
final class InboundMailService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS formulario_resposta_entradas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resposta_id BIGINT UNSIGNED NOT NULL,
                formulario_id INT UNSIGNED NOT NULL,
                mailbox_key CHAR(64) NOT NULL,
                message_uid BIGINT UNSIGNED NOT NULL,
                message_id VARCHAR(500) NULL,
                remetente VARCHAR(190) NOT NULL,
                assunto VARCHAR(500) NULL,
                mensagem MEDIUMTEXT NOT NULL,
                received_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_form_resp_in_mailbox_uid (mailbox_key,message_uid),
                KEY idx_form_resp_in_resposta (resposta_id,received_at),
                KEY idx_form_resp_in_formulario (formulario_id,received_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(PDO $pdo): array
    {
        return [
            'inbound_mail_enabled' => '0',
            'inbound_mail_address' =>
                siteConfig(
                    $pdo,
                    'mail_reply_to',
                    siteConfig(
                        $pdo,
                        'mail_from_email',
                        ''
                    )
                ),
            'inbound_imap_host' =>
                siteConfig(
                    $pdo,
                    'mail_smtp_host',
                    ''
                ),
            'inbound_imap_port' => '993',
            'inbound_imap_encryption' => 'ssl',
            'inbound_imap_validate_cert' => '1',
            'inbound_imap_folder' => 'INBOX',
            'inbound_sync_limit' => '50',
            'inbound_mail_last_uid' => '0',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function settings(PDO $pdo): array
    {
        $settings =
            array_merge(
                self::defaults($pdo),
                siteConfigAll($pdo)
            );

        $settings['inbound_mail_enabled'] =
            ($settings['inbound_mail_enabled'] ?? '0') === '1'
                ? '1'
                : '0';

        $settings['inbound_imap_validate_cert'] =
            ($settings['inbound_imap_validate_cert'] ?? '1') === '1'
                ? '1'
                : '0';

        $encryption =
            strtolower(
                trim(
                    (string)(
                        $settings['inbound_imap_encryption']
                        ?? 'ssl'
                    )
                )
            );

        if (
            !in_array(
                $encryption,
                ['ssl', 'tls', 'none'],
                true
            )
        ) {
            $encryption = 'ssl';
        }

        $settings['inbound_imap_encryption'] =
            $encryption;

        $settings['inbound_imap_port'] =
            (string)max(
                1,
                min(
                    65535,
                    (int)(
                        $settings['inbound_imap_port']
                        ?? 993
                    )
                )
            );

        $settings['inbound_sync_limit'] =
            (string)max(
                10,
                min(
                    200,
                    (int)(
                        $settings['inbound_sync_limit']
                        ?? 50
                    )
                )
            );

        $settings['inbound_imap_folder'] =
            self::cleanFolder(
                (string)(
                    $settings['inbound_imap_folder']
                    ?? 'INBOX'
                )
            );

        if (
            $settings['inbound_imap_folder']
            === ''
        ) {
            $settings['inbound_imap_folder'] =
                'INBOX';
        }

        return $settings;
    }

    public static function extensionAvailable(): bool
    {
        return
            function_exists('imap_open')
            && function_exists('imap_search')
            && function_exists('imap_fetch_overview');
    }

    /**
     * @return array{
     *   ok:bool,
     *   message:string,
     *   mailbox:string,
     *   username:string,
     *   messages:int
     * }
     */
    public static function diagnose(PDO $pdo): array
    {
        if (!self::extensionAvailable()) {
            return [
                'ok' => false,
                'message' =>
                    'A extensão PHP IMAP não está habilitada neste servidor.',
                'mailbox' => '',
                'username' => '',
                'messages' => 0,
            ];
        }

        $settings =
            self::settings(
                $pdo
            );

        try {
            [$imap, $mailbox, $username] =
                self::connect(
                    $pdo,
                    $settings
                );

            $count =
                (int)imap_num_msg(
                    $imap
                );

            @imap_close(
                $imap
            );

            return [
                'ok' => true,
                'message' =>
                    'Conexão IMAP concluída com sucesso.',
                'mailbox' =>
                    $mailbox,
                'username' =>
                    $username,
                'messages' =>
                    $count,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' =>
                    $e->getMessage(),
                'mailbox' =>
                    self::mailboxString(
                        $settings
                    ),
                'username' =>
                    trim(
                        siteConfig(
                            $pdo,
                            'mail_smtp_username',
                            ''
                        )
                    ),
                'messages' => 0,
            ];
        }
    }

    /**
     * Sincroniza mensagens novas.
     *
     * @return array{
     *   status:string,
     *   message:string,
     *   checked:int,
     *   imported:int,
     *   ignored:int,
     *   last_uid:int
     * }
     */
    public static function sync(
        PDO $pdo,
        bool $force = false
    ): array {
        self::ensureSchema(
            $pdo
        );

        $settings =
            self::settings(
                $pdo
            );

        if (
            !$force
            && $settings['inbound_mail_enabled']
                !== '1'
        ) {
            return [
                'status' => 'ignorado',
                'message' =>
                    'Recebimento de respostas por e-mail está desativado.',
                'checked' => 0,
                'imported' => 0,
                'ignored' => 0,
                'last_uid' =>
                    (int)(
                        $settings['inbound_mail_last_uid']
                        ?? 0
                    ),
            ];
        }

        if (!self::extensionAvailable()) {
            return [
                'status' => 'ignorado',
                /* PORTAL_IMAP_OPTIONAL_V101 */
                'message' =>
                    'Extensão PHP IMAP não está disponível neste ambiente. '
                    . 'Tarefa ignorada sem erro; habilite IMAP somente se o recebimento de respostas por e-mail for utilizado.',
                'checked' => 0,
                'imported' => 0,
                'ignored' => 0,
                'last_uid' =>
                    (int)(
                        $settings['inbound_mail_last_uid']
                        ?? 0
                    ),
            ];
        }

        [$imap, $mailbox, $username] =
            self::connect(
                $pdo,
                $settings
            );

        try {
            $uids =
                imap_search(
                    $imap,
                    'ALL',
                    SE_UID
                );

            $uids =
                is_array($uids)
                    ? array_values(
                        array_unique(
                            array_map(
                                'intval',
                                $uids
                            )
                        )
                    )
                    : [];

            sort(
                $uids,
                SORT_NUMERIC
            );

            $lastUid =
                max(
                    0,
                    (int)(
                        $settings['inbound_mail_last_uid']
                        ?? 0
                    )
                );

            /*
             * Primeira sincronização: evita percorrer toda uma caixa antiga.
             * Analisa no máximo os últimos N e depois grava o maior UID.
             */
            if ($lastUid > 0) {
                $uids =
                    array_values(
                        array_filter(
                            $uids,
                            static fn(int $uid): bool =>
                                $uid > $lastUid
                        )
                    );
            }

            $limit =
                (int)$settings['inbound_sync_limit'];

            if (
                count($uids) > $limit
            ) {
                $uids =
                    array_slice(
                        $uids,
                        -$limit
                    );
            }

            $checked = 0;
            $imported = 0;
            $ignored = 0;
            $maxUid =
                $lastUid;

            $mailboxKey =
                hash(
                    'sha256',
                    strtolower(
                        $mailbox
                        . "\n"
                        . $username
                    )
                );

            foreach ($uids as $uid) {
                $uid =
                    (int)$uid;

                if ($uid <= 0) {
                    continue;
                }

                $maxUid =
                    max(
                        $maxUid,
                        $uid
                    );

                $checked++;

                if (
                    self::alreadyImported(
                        $pdo,
                        $mailboxKey,
                        $uid
                    )
                ) {
                    continue;
                }

                $overview =
                    imap_fetch_overview(
                        $imap,
                        (string)$uid,
                        FT_UID
                    );

                $overview =
                    is_array($overview)
                    && isset($overview[0])
                        ? $overview[0]
                        : null;

                if (!$overview) {
                    $ignored++;
                    continue;
                }

                $subject =
                    self::decodeMime(
                        (string)(
                            $overview->subject
                            ?? ''
                        )
                    );

                $responseId =
                    self::responseIdFromSubject(
                        $subject
                    );

                if ($responseId <= 0) {
                    $ignored++;
                    continue;
                }

                $from =
                    self::senderEmail(
                        $imap,
                        $uid
                    );

                if ($from === '') {
                    $ignored++;
                    continue;
                }

                if (!class_exists('FormReplyService')) {
                    $ignored++;
                    continue;
                }

                $context =
                    FormReplyService::context(
                        $pdo,
                        $responseId
                    );

                if (!$context) {
                    $ignored++;
                    continue;
                }

                $expected =
                    strtolower(
                        trim(
                            (string)(
                                $context['recipient']
                                ?? ''
                            )
                        )
                    );

                if (
                    $expected === ''
                    || !hash_equals(
                        $expected,
                        strtolower($from)
                    )
                ) {
                    /*
                     * Segurança: não vinculamos a conversa se o remetente
                     * não for o mesmo endereço informado no formulário.
                     */
                    $ignored++;
                    continue;
                }

                $message =
                    self::messageText(
                        $imap,
                        $uid
                    );

                if ($message === '') {
                    $message =
                        '(Resposta recebida sem conteúdo de texto.)';
                }

                $messageId =
                    self::cleanText(
                        (string)(
                            $overview->message_id
                            ?? ''
                        ),
                        500
                    );

                $receivedAt =
                    self::receivedAt(
                        $overview
                    );

                try {
                    $stmt =
                        $pdo->prepare(
                            "INSERT INTO formulario_resposta_entradas
                                (
                                    resposta_id,
                                    formulario_id,
                                    mailbox_key,
                                    message_uid,
                                    message_id,
                                    remetente,
                                    assunto,
                                    mensagem,
                                    received_at,
                                    created_at
                                )
                             VALUES
                                (
                                    :resposta,
                                    :formulario,
                                    :mailbox_key,
                                    :uid,
                                    :message_id,
                                    :remetente,
                                    :assunto,
                                    :mensagem,
                                    :received_at,
                                    NOW()
                                )"
                        );

                    $stmt->execute([
                        'resposta' =>
                            $responseId,
                        'formulario' =>
                            (int)(
                                $context['form']['id']
                                ?? 0
                            ),
                        'mailbox_key' =>
                            $mailboxKey,
                        'uid' =>
                            $uid,
                        'message_id' =>
                            $messageId !== ''
                                ? $messageId
                                : null,
                        'remetente' =>
                            self::cut(
                                strtolower($from),
                                190
                            ),
                        'assunto' =>
                            self::cut(
                                $subject,
                                500
                            ),
                        'mensagem' =>
                            self::cut(
                                $message,
                                20000
                            ),
                        'received_at' =>
                            $receivedAt,
                    ]);

                    $imported++;

                    try {
                        $stmt =
                            $pdo->prepare(
                                "UPDATE formulario_respostas
                                 SET status='nova'
                                 WHERE id=:id"
                            );

                        $stmt->execute([
                            'id' =>
                                $responseId,
                        ]);
                    } catch (Throwable $ignoredException) {
                    }
                } catch (PDOException $e) {
                    if (
                        (string)$e->getCode()
                        !== '23000'
                    ) {
                        throw $e;
                    }
                }
            }

            if ($maxUid > $lastUid) {
                saveSiteConfig(
                    $pdo,
                    'inbound_mail_last_uid',
                    (string)$maxUid,
                    'numero'
                );
            }

            return [
                'status' => 'ok',
                'message' =>
                    $imported
                    . ' resposta(s) por e-mail importada(s); '
                    . $checked
                    . ' mensagem(ns) analisada(s).',
                'checked' =>
                    $checked,
                'imported' =>
                    $imported,
                'ignored' =>
                    $ignored,
                'last_uid' =>
                    $maxUid,
            ];
        } finally {
            @imap_close(
                $imap
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function history(
        PDO $pdo,
        int $responseId,
        int $limit = 50
    ): array {
        self::ensureSchema(
            $pdo
        );

        $limit =
            max(
                1,
                min(
                    200,
                    $limit
                )
            );

        $stmt =
            $pdo->prepare(
                "SELECT *
                 FROM formulario_resposta_entradas
                 WHERE resposta_id=:resposta
                 ORDER BY
                    COALESCE(received_at,created_at) ASC,
                    id ASC
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

    private static function alreadyImported(
        PDO $pdo,
        string $mailboxKey,
        int $uid
    ): bool {
        $stmt =
            $pdo->prepare(
                "SELECT 1
                 FROM formulario_resposta_entradas
                 WHERE mailbox_key=:mailbox_key
                   AND message_uid=:uid
                 LIMIT 1"
            );

        $stmt->execute([
            'mailbox_key' =>
                $mailboxKey,
            'uid' =>
                $uid,
        ]);

        return
            (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string,string> $settings
     * @return array{0:mixed,1:string,2:string}
     */
    private static function connect(
        PDO $pdo,
        array $settings
    ): array {
        if (!self::extensionAvailable()) {
            throw new RuntimeException(
                'A extensão PHP IMAP não está habilitada.'
            );
        }

        $username =
            trim(
                siteConfig(
                    $pdo,
                    'mail_smtp_username',
                    ''
                )
            );

        if ($username === '') {
            throw new RuntimeException(
                'O usuário SMTP não está configurado. '
                . 'O recebimento IMAP usa a mesma conta de autenticação do SMTP.'
            );
        }

        if (
            !class_exists('MailService')
            || !method_exists(
                'MailService',
                'smtpPasswordForInbound'
            )
        ) {
            throw new RuntimeException(
                'MailService ainda não disponibiliza a credencial protegida para IMAP.'
            );
        }

        $password =
            MailService::smtpPasswordForInbound(
                $pdo
            );

        if ($password === '') {
            throw new RuntimeException(
                'A senha SMTP não está configurada.'
            );
        }

        $mailbox =
            self::mailboxString(
                $settings
            );

        $imap =
            @imap_open(
                $mailbox,
                $username,
                $password,
                0,
                1,
                [
                    'DISABLE_AUTHENTICATOR' =>
                        'GSSAPI'
                ]
            );

        if ($imap === false) {
            $error =
                function_exists(
                    'imap_last_error'
                )
                    ? trim(
                        (string)imap_last_error()
                    )
                    : '';

            throw new RuntimeException(
                $error !== ''
                    ? 'Falha IMAP: '
                        . $error
                    : 'Não foi possível conectar ao servidor IMAP.'
            );
        }

        return [
            $imap,
            $mailbox,
            $username,
        ];
    }

    /**
     * @param array<string,string> $settings
     */
    private static function mailboxString(
        array $settings
    ): string {
        $host =
            trim(
                (string)(
                    $settings['inbound_imap_host']
                    ?? ''
                )
            );

        if ($host === '') {
            return '';
        }

        $host =
            preg_replace(
                '/[^a-zA-Z0-9._:-]+/',
                '',
                $host
            )
            ?? '';

        $port =
            max(
                1,
                min(
                    65535,
                    (int)(
                        $settings['inbound_imap_port']
                        ?? 993
                    )
                )
            );

        $encryption =
            (string)(
                $settings['inbound_imap_encryption']
                ?? 'ssl'
            );

        $flags =
            '/imap';

        if ($encryption === 'ssl') {
            $flags .=
                '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .=
                '/tls';
        } else {
            $flags .=
                '/notls';
        }

        if (
            ($settings['inbound_imap_validate_cert'] ?? '1')
            !== '1'
        ) {
            $flags .=
                '/novalidate-cert';
        }

        $folder =
            self::cleanFolder(
                (string)(
                    $settings['inbound_imap_folder']
                    ?? 'INBOX'
                )
            );

        if ($folder === '') {
            $folder =
                'INBOX';
        }

        return
            '{'
            . $host
            . ':'
            . $port
            . $flags
            . '}'
            . $folder;
    }

    private static function responseIdFromSubject(
        string $subject
    ): int {
        if (
            preg_match(
                '/\[IECLB-R(\d+)\]/i',
                $subject,
                $match
            ) !== 1
        ) {
            return 0;
        }

        return
            max(
                0,
                (int)$match[1]
            );
    }

    private static function senderEmail(
        mixed $imap,
        int $uid
    ): string {
        $header =
            @imap_headerinfo(
                $imap,
                imap_msgno(
                    $imap,
                    $uid
                )
            );

        if (
            !$header
            || empty(
                $header->from
            )
            || !is_array(
                $header->from
            )
        ) {
            return '';
        }

        foreach ($header->from as $from) {
            $mailbox =
                trim(
                    (string)(
                        $from->mailbox
                        ?? ''
                    )
                );

            $host =
                trim(
                    (string)(
                        $from->host
                        ?? ''
                    )
                );

            $email =
                strtolower(
                    $mailbox
                    . '@'
                    . $host
                );

            if (
                filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                return $email;
            }
        }

        return '';
    }

    private static function messageText(
        mixed $imap,
        int $uid
    ): string {
        $msgNo =
            imap_msgno(
                $imap,
                $uid
            );

        if ($msgNo <= 0) {
            return '';
        }

        $structure =
            @imap_fetchstructure(
                $imap,
                $msgNo
            );

        if (!$structure) {
            return
                self::cleanMessage(
                    self::decodeBody(
                        (string)@imap_body(
                            $imap,
                            $msgNo,
                            FT_PEEK
                        ),
                        0,
                        ''
                    )
                );
        }

        $plain =
            self::findTextPart(
                $imap,
                $msgNo,
                $structure,
                '',
                'plain'
            );

        if ($plain !== '') {
            return
                self::cleanMessage(
                    $plain
                );
        }

        $html =
            self::findTextPart(
                $imap,
                $msgNo,
                $structure,
                '',
                'html'
            );

        if ($html !== '') {
            $html =
                preg_replace(
                    '/<\s*br\s*\/?\s*>/i',
                    "\n",
                    $html
                )
                ?? $html;

            $html =
                preg_replace(
                    '/<\/(p|div|li|blockquote|h[1-6])>/i',
                    "\n",
                    $html
                )
                ?? $html;

            $text =
                html_entity_decode(
                    strip_tags(
                        $html
                    ),
                    ENT_QUOTES
                    | ENT_HTML5,
                    'UTF-8'
                );

            return
                self::cleanMessage(
                    $text
                );
        }

        return '';
    }

    private static function findTextPart(
        mixed $imap,
        int $msgNo,
        object $structure,
        string $prefix,
        string $wantedSubtype
    ): string {
        $type =
            (int)(
                $structure->type
                ?? -1
            );

        $subtype =
            strtolower(
                (string)(
                    $structure->subtype
                    ?? ''
                )
            );

        if (
            $type === 0
            && $subtype === $wantedSubtype
        ) {
            $partNo =
                $prefix !== ''
                    ? $prefix
                    : '1';

            $raw =
                (string)@imap_fetchbody(
                    $imap,
                    $msgNo,
                    $partNo,
                    FT_PEEK
                );

            $charset =
                self::partCharset(
                    $structure
                );

            return
                self::decodeBody(
                    $raw,
                    (int)(
                        $structure->encoding
                        ?? 0
                    ),
                    $charset
                );
        }

        if (
            !empty(
                $structure->parts
            )
            && is_array(
                $structure->parts
            )
        ) {
            foreach (
                $structure->parts
                as $index => $part
            ) {
                if (!is_object($part)) {
                    continue;
                }

                $partNo =
                    $prefix === ''
                        ? (string)(
                            $index + 1
                        )
                        : $prefix
                            . '.'
                            . ($index + 1);

                $result =
                    self::findTextPart(
                        $imap,
                        $msgNo,
                        $part,
                        $partNo,
                        $wantedSubtype
                    );

                if ($result !== '') {
                    return $result;
                }
            }
        }

        return '';
    }

    private static function partCharset(
        object $part
    ): string {
        $params = [];

        if (
            !empty(
                $part->parameters
            )
            && is_array(
                $part->parameters
            )
        ) {
            $params =
                array_merge(
                    $params,
                    $part->parameters
                );
        }

        if (
            !empty(
                $part->dparameters
            )
            && is_array(
                $part->dparameters
            )
        ) {
            $params =
                array_merge(
                    $params,
                    $part->dparameters
                );
        }

        foreach ($params as $param) {
            if (
                strtolower(
                    (string)(
                        $param->attribute
                        ?? ''
                    )
                )
                === 'charset'
            ) {
                return
                    strtoupper(
                        trim(
                            (string)(
                                $param->value
                                ?? ''
                            )
                        )
                    );
            }
        }

        return '';
    }

    private static function decodeBody(
        string $raw,
        int $encoding,
        string $charset
    ): string {
        if ($encoding === 3) {
            $decoded =
                base64_decode(
                    $raw,
                    true
                );

            if ($decoded !== false) {
                $raw =
                    $decoded;
            }
        } elseif ($encoding === 4) {
            $raw =
                quoted_printable_decode(
                    $raw
                );
        }

        if (
            $charset !== ''
            && strtoupper($charset)
                !== 'UTF-8'
            && function_exists(
                'mb_convert_encoding'
            )
        ) {
            try {
                $raw =
                    mb_convert_encoding(
                        $raw,
                        'UTF-8',
                        $charset
                    );
            } catch (Throwable $ignored) {
            }
        }

        return $raw;
    }

    private static function cleanMessage(
        string $value
    ): string {
        $value =
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $value
            );

        $lines =
            explode(
                "\n",
                $value
            );

        $clean = [];

        foreach ($lines as $line) {
            $trim =
                trim(
                    $line
                );

            if (
                preg_match(
                    '/^On .+ wrote:$/i',
                    $trim
                )
                || preg_match(
                    '/^Em .+ escreveu:$/iu',
                    $trim
                )
                || preg_match(
                    '/^De:\s.+/iu',
                    $trim
                )
            ) {
                break;
            }

            if (
                str_starts_with(
                    ltrim($line),
                    '>'
                )
            ) {
                continue;
            }

            $clean[] =
                rtrim(
                    $line
                );
        }

        $value =
            trim(
                implode(
                    "\n",
                    $clean
                )
            );

        $value =
            preg_replace(
                "/\n{3,}/",
                "\n\n",
                $value
            )
            ?? $value;

        return
            self::cut(
                $value,
                20000
            );
    }

    private static function receivedAt(
        object $overview
    ): ?string {
        $timestamp =
            isset(
                $overview->udate
            )
                ? (int)$overview->udate
                : 0;

        if ($timestamp <= 0) {
            $date =
                trim(
                    (string)(
                        $overview->date
                        ?? ''
                    )
                );

            if ($date !== '') {
                $parsed =
                    @strtotime(
                        $date
                    );

                if ($parsed !== false) {
                    $timestamp =
                        (int)$parsed;
                }
            }
        }

        return
            $timestamp > 0
                ? date(
                    'Y-m-d H:i:s',
                    $timestamp
                )
                : null;
    }

    private static function decodeMime(
        string $value
    ): string {
        if (
            $value === ''
            || !function_exists(
                'imap_mime_header_decode'
            )
        ) {
            return
                self::cleanText(
                    $value,
                    500
                );
        }

        $parts =
            @imap_mime_header_decode(
                $value
            );

        if (!is_array($parts)) {
            return
                self::cleanText(
                    $value,
                    500
                );
        }

        $result = '';

        foreach ($parts as $part) {
            $text =
                (string)(
                    $part->text
                    ?? ''
                );

            $charset =
                strtoupper(
                    trim(
                        (string)(
                            $part->charset
                            ?? ''
                        )
                    )
                );

            if (
                $charset !== ''
                && $charset !== 'DEFAULT'
                && $charset !== 'UTF-8'
                && function_exists(
                    'mb_convert_encoding'
                )
            ) {
                try {
                    $text =
                        mb_convert_encoding(
                            $text,
                            'UTF-8',
                            $charset
                        );
                } catch (Throwable $ignored) {
                }
            }

            $result .= $text;
        }

        return
            self::cleanText(
                $result,
                500
            );
    }

    private static function cleanFolder(
        string $value
    ): string {
        $value =
            trim(
                $value
            );

        $value =
            preg_replace(
                '/[{}\r\n\0]+/',
                '',
                $value
            )
            ?? '';

        return
            self::cut(
                $value,
                190
            );
    }

    private static function cleanText(
        string $value,
        int $length
    ): string {
        $value =
            trim(
                preg_replace(
                    '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u',
                    '',
                    $value
                )
                ?? ''
            );

        return
            self::cut(
                $value,
                $length
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
}
