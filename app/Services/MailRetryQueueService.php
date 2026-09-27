<?php

declare(strict_types=1);

final class MailRetryQueueService
{
    /** @var array<int,bool> */
    private static array $schemaReady = [];

    public static function ensureSchema(PDO $pdo): void
    {
        $key = spl_object_id($pdo);

        if (!empty(self::$schemaReady[$key])) {
            return;
        }

        if ($pdo->inTransaction()) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );
            $stmt->execute(['email_fila_falhas']);

            if ((int)$stmt->fetchColumn() <= 0) {
                throw new RuntimeException(
                    'A fila de e-mails da v1.1.13 não foi inicializada antes da transação.'
                );
            }

            self::$schemaReady[$key] = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_fila_falhas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                destinatario VARCHAR(190) NOT NULL,
                assunto VARCHAR(255) NOT NULL,
                html MEDIUMTEXT NOT NULL,
                options_json TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pendente',
                tentativas INT NOT NULL DEFAULT 0,
                max_tentativas INT NOT NULL DEFAULT 5,
                proxima_tentativa_em DATETIME NULL,
                ultimo_erro TEXT NULL,
                source_message_id VARCHAR(255) NULL,
                locked_at DATETIME NULL,
                enviado_em DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_email_fila_status (status,proxima_tentativa_em),
                KEY idx_email_fila_destinatario (destinatario),
                KEY idx_email_fila_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$schemaReady[$key] = true;
    }

    public static function enqueue(
        PDO $pdo,
        string $to,
        string $subject,
        string $html,
        array $options,
        string $error,
        ?string $messageId = null
    ): ?int {
        if (siteConfig($pdo, 'mail_queue_enabled', '1') !== '1') {
            return null;
        }

        $to = strtolower(trim($to));
        $subject = trim($subject);

        if (
            !filter_var($to, FILTER_VALIDATE_EMAIL)
            || $subject === ''
            || trim($html) === ''
        ) {
            return null;
        }

        self::ensureSchema($pdo);

        $maxAttempts = max(
            1,
            min(
                20,
                (int)siteConfig($pdo, 'mail_queue_max_attempts', '5')
            )
        );

        $safeOptions = [];

        foreach (
            ['from_email', 'from_name', 'reply_to']
            as $key
        ) {
            if (
                array_key_exists($key, $options)
                && is_scalar($options[$key])
            ) {
                $safeOptions[$key] = (string)$options[$key];
            }
        }

        $json = json_encode(
            $safeOptions,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        $stmt = $pdo->prepare(
            "INSERT INTO email_fila_falhas
                (
                    destinatario,
                    assunto,
                    html,
                    options_json,
                    status,
                    tentativas,
                    max_tentativas,
                    proxima_tentativa_em,
                    ultimo_erro,
                    source_message_id,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :destinatario,
                    :assunto,
                    :html,
                    :options_json,
                    'pendente',
                    0,
                    :max_tentativas,
                    DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                    :ultimo_erro,
                    :source_message_id,
                    NOW(),
                    NOW()
                )"
        );

        $stmt->execute([
            'destinatario' => $to,
            'assunto' => $subject,
            'html' => $html,
            'options_json' => $json !== false ? $json : '{}',
            'max_tentativas' => $maxAttempts,
            'ultimo_erro' => self::cut($error, 3000),
            'source_message_id' => $messageId,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return array{processed:int,sent:int,pending:int,failed:int}
     */
    public static function processDue(
        PDO $pdo,
        int $limit = 20
    ): array {
        self::ensureSchema($pdo);

        $limit = max(1, min(100, $limit));

        /*
         * Recupera itens que ficaram presos em "processando" por mais de 30 min.
         */
        $pdo->exec(
            "UPDATE email_fila_falhas
             SET
                status='pendente',
                locked_at=NULL,
                proxima_tentativa_em=NOW(),
                updated_at=NOW()
             WHERE status='processando'
               AND locked_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );

        $stmt = $pdo->query(
            "SELECT *
             FROM email_fila_falhas
             WHERE status='pendente'
               AND tentativas < max_tentativas
               AND (
                    proxima_tentativa_em IS NULL
                    OR proxima_tentativa_em<=NOW()
               )
             ORDER BY id
             LIMIT {$limit}"
        );

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [
            'processed' => 0,
            'sent' => 0,
            'pending' => 0,
            'failed' => 0,
        ];

        foreach ($items as $item) {
            $id = (int)$item['id'];

            $lock = $pdo->prepare(
                "UPDATE email_fila_falhas
                 SET status='processando',locked_at=NOW(),updated_at=NOW()
                 WHERE id=?
                   AND status='pendente'"
            );
            $lock->execute([$id]);

            if ($lock->rowCount() !== 1) {
                continue;
            }

            $result['processed']++;

            $options = [];

            if (!empty($item['options_json'])) {
                try {
                    $decoded = json_decode(
                        (string)$item['options_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    if (is_array($decoded)) {
                        $options = $decoded;
                    }
                } catch (Throwable $ignored) {
                }
            }

            $options['queue_on_failure'] = false;

            $ok = MailService::sendHtml(
                $pdo,
                (string)$item['destinatario'],
                (string)$item['assunto'],
                (string)$item['html'],
                $options
            );

            $attempt = (int)$item['tentativas'] + 1;

            if ($ok) {
                $done = $pdo->prepare(
                    "UPDATE email_fila_falhas
                     SET
                        status='enviado',
                        tentativas=?,
                        ultimo_erro=NULL,
                        locked_at=NULL,
                        enviado_em=NOW(),
                        updated_at=NOW()
                     WHERE id=?"
                );
                $done->execute([$attempt, $id]);
                $result['sent']++;
                continue;
            }

            $max = max(1, (int)$item['max_tentativas']);
            $error = MailService::lastError() ?: 'Falha de envio sem detalhe.';
            $terminal = $attempt >= $max;

            $delayMinutes = min(
                360,
                5 * (2 ** max(0, min(6, $attempt - 1)))
            );

            $retry = $pdo->prepare(
                "UPDATE email_fila_falhas
                 SET
                    status=:status,
                    tentativas=:tentativas,
                    ultimo_erro=:erro,
                    locked_at=NULL,
                    proxima_tentativa_em=CASE
                        WHEN :terminal=1 THEN NULL
                        ELSE DATE_ADD(NOW(), INTERVAL {$delayMinutes} MINUTE)
                    END,
                    updated_at=NOW()
                 WHERE id=:id"
            );

            $retry->execute([
                'status' => $terminal ? 'falhou' : 'pendente',
                'tentativas' => $attempt,
                'erro' => self::cut($error, 3000),
                'terminal' => $terminal ? 1 : 0,
                'id' => $id,
            ]);

            if ($terminal) {
                $result['failed']++;
            } else {
                $result['pending']++;
            }
        }

        return $result;
    }

    public static function retryNow(
        PDO $pdo,
        int $id
    ): bool {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "UPDATE email_fila_falhas
             SET
                status='pendente',
                tentativas=0,
                locked_at=NULL,
                proxima_tentativa_em=NOW(),
                updated_at=NOW()
             WHERE id=?
               AND status IN ('pendente','falhou')"
        );

        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    public static function discard(
        PDO $pdo,
        int $id
    ): bool {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            "UPDATE email_fila_falhas
             SET status='descartado',locked_at=NULL,updated_at=NOW()
             WHERE id=?
               AND status <> 'enviado'"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    public static function purgeSent(
        PDO $pdo,
        int $days = 30
    ): int {
        self::ensureSchema($pdo);
        $days = max(1, min(3650, $days));

        return $pdo->exec(
            "DELETE FROM email_fila_falhas
             WHERE status IN ('enviado','descartado')
               AND updated_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(
        PDO $pdo,
        int $limit = 100
    ): array {
        self::ensureSchema($pdo);
        $limit = max(1, min(300, $limit));

        return $pdo->query(
            "SELECT *
             FROM email_fila_falhas
             ORDER BY
                CASE status
                    WHEN 'falhou' THEN 1
                    WHEN 'pendente' THEN 2
                    WHEN 'processando' THEN 3
                    WHEN 'enviado' THEN 4
                    ELSE 5
                END,
                id DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,int> */
    public static function stats(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $rows = $pdo->query(
            "SELECT status,COUNT(*) AS total
             FROM email_fila_falhas
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [
            'pendente' => 0,
            'processando' => 0,
            'enviado' => 0,
            'falhou' => 0,
            'descartado' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)$row['status'];

            if (array_key_exists($status, $out)) {
                $out[$status] = (int)$row['total'];
            }
        }

        return $out;
    }

    private static function cut(
        string $value,
        int $length
    ): string {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }
}
