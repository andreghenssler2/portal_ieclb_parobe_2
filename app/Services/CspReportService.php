<?php

declare(strict_types=1);

/**
 * Coleta e resume violações de Content Security Policy.
 *
 * Privacidade:
 * - não salva IP;
 * - remove query string e fragmento das URLs;
 * - não salva script-sample;
 * - deduplica eventos por fingerprint.
 */
final class CspReportService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS security_csp_reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                fingerprint CHAR(64) NOT NULL,
                document_uri VARCHAR(500) NULL,
                effective_directive VARCHAR(120) NULL,
                violated_directive VARCHAR(255) NULL,
                blocked_uri VARCHAR(500) NULL,
                source_file VARCHAR(500) NULL,
                line_number INT UNSIGNED NULL,
                column_number INT UNSIGNED NULL,
                disposition VARCHAR(30) NULL,
                status_code INT NULL,
                occurrences INT UNSIGNED NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_security_csp_fingerprint (fingerprint),
                KEY idx_security_csp_last_seen (last_seen_at),
                KEY idx_security_csp_directive (effective_directive,last_seen_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    public static function enabled(PDO $pdo): bool
    {
        return
            siteConfig(
                $pdo,
                'security_csp_report_enabled',
                '1'
            ) === '1';
    }

    public static function retentionDays(PDO $pdo): int
    {
        return
            max(
                1,
                min(
                    365,
                    (int)siteConfig(
                        $pdo,
                        'security_csp_report_retention_days',
                        '30'
                    )
                )
            );
    }

    public static function collectRaw(
        PDO $pdo,
        string $raw,
        string $contentType = ''
    ): bool {
        self::ensureSchema($pdo);

        if (!self::enabled($pdo)) {
            return false;
        }

        if (
            $raw === ''
            || strlen($raw) > 131072
        ) {
            return false;
        }

        try {
            $decoded =
                json_decode(
                    $raw,
                    true,
                    8,
                    JSON_THROW_ON_ERROR
                );
        } catch (Throwable $e) {
            return false;
        }

        $report =
            self::extractReport(
                $decoded
            );

        if (!$report) {
            return false;
        }

        return
            self::store(
                $pdo,
                $report
            );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function extractReport(
        mixed $decoded
    ): ?array {
        if (!is_array($decoded)) {
            return null;
        }

        /*
         * Formato clássico application/csp-report:
         * {"csp-report": {...}}
         */
        if (
            isset($decoded['csp-report'])
            && is_array(
                $decoded['csp-report']
            )
        ) {
            return
                $decoded['csp-report'];
        }

        /*
         * Reporting API pode enviar array:
         * [{"type":"csp-violation","body":{...}}]
         */
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (
                    is_array($item)
                    && isset($item['body'])
                    && is_array($item['body'])
                ) {
                    return
                        $item['body'];
                }
            }

            return null;
        }

        if (
            isset($decoded['body'])
            && is_array(
                $decoded['body']
            )
        ) {
            return
                $decoded['body'];
        }

        /*
         * Alguns navegadores/ferramentas enviam o objeto diretamente.
         */
        return $decoded;
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function store(
        PDO $pdo,
        array $report
    ): bool {
        $documentUri =
            self::sanitizeUrlLike(
                (string)(
                    $report['document-uri']
                    ?? $report['documentURL']
                    ?? ''
                )
            );

        $effectiveDirective =
            self::cleanText(
                (string)(
                    $report['effective-directive']
                    ?? $report['effectiveDirective']
                    ?? ''
                ),
                120
            );

        $violatedDirective =
            self::cleanText(
                (string)(
                    $report['violated-directive']
                    ?? $report['violatedDirective']
                    ?? ''
                ),
                255
            );

        $blockedUri =
            self::sanitizeUrlLike(
                (string)(
                    $report['blocked-uri']
                    ?? $report['blockedURL']
                    ?? ''
                )
            );

        $sourceFile =
            self::sanitizeUrlLike(
                (string)(
                    $report['source-file']
                    ?? $report['sourceFile']
                    ?? ''
                )
            );

        $disposition =
            self::cleanText(
                (string)(
                    $report['disposition']
                    ?? ''
                ),
                30
            );

        $lineNumber =
            max(
                0,
                (int)(
                    $report['line-number']
                    ?? $report['lineNumber']
                    ?? 0
                )
            );

        $columnNumber =
            max(
                0,
                (int)(
                    $report['column-number']
                    ?? $report['columnNumber']
                    ?? 0
                )
            );

        $statusCode =
            max(
                0,
                min(
                    999,
                    (int)(
                        $report['status-code']
                        ?? $report['statusCode']
                        ?? 0
                    )
                )
            );

        if (
            $effectiveDirective === ''
            && $violatedDirective === ''
            && $blockedUri === ''
        ) {
            return false;
        }

        $fingerprint =
            hash(
                'sha256',
                implode(
                    "\n",
                    [
                        $documentUri,
                        $effectiveDirective,
                        $violatedDirective,
                        $blockedUri,
                        $sourceFile,
                        (string)$lineNumber,
                        (string)$columnNumber,
                        $disposition,
                    ]
                )
            );

        /*
         * Atualiza ocorrências sem criar nova linha.
         */
        $update =
            $pdo->prepare(
                "UPDATE security_csp_reports
                 SET
                    occurrences=occurrences+1,
                    last_seen_at=NOW(),
                    status_code=:status_code
                 WHERE fingerprint=:fingerprint"
            );

        $update->execute([
            'status_code' =>
                $statusCode > 0
                    ? $statusCode
                    : null,
            'fingerprint' =>
                $fingerprint,
        ]);

        if (
            $update->rowCount() > 0
        ) {
            self::maybeCleanup($pdo);

            return true;
        }

        /*
         * Limite conservador contra abuso do endpoint público.
         * Eventos repetidos continuam atualizando a linha existente.
         */
        $total =
            (int)$pdo
                ->query(
                    "SELECT COUNT(*)
                     FROM security_csp_reports"
                )
                ->fetchColumn();

        if ($total >= 5000) {
            self::maybeCleanup(
                $pdo,
                true
            );

            $total =
                (int)$pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM security_csp_reports"
                    )
                    ->fetchColumn();

            if ($total >= 5000) {
                return false;
            }
        }

        $insert =
            $pdo->prepare(
                "INSERT INTO security_csp_reports
                    (
                        fingerprint,
                        document_uri,
                        effective_directive,
                        violated_directive,
                        blocked_uri,
                        source_file,
                        line_number,
                        column_number,
                        disposition,
                        status_code,
                        occurrences,
                        first_seen_at,
                        last_seen_at
                    )
                 VALUES
                    (
                        :fingerprint,
                        :document_uri,
                        :effective_directive,
                        :violated_directive,
                        :blocked_uri,
                        :source_file,
                        :line_number,
                        :column_number,
                        :disposition,
                        :status_code,
                        1,
                        NOW(),
                        NOW()
                    )"
            );

        try {
            $insert->execute([
                'fingerprint' =>
                    $fingerprint,
                'document_uri' =>
                    $documentUri !== ''
                        ? $documentUri
                        : null,
                'effective_directive' =>
                    $effectiveDirective !== ''
                        ? $effectiveDirective
                        : null,
                'violated_directive' =>
                    $violatedDirective !== ''
                        ? $violatedDirective
                        : null,
                'blocked_uri' =>
                    $blockedUri !== ''
                        ? $blockedUri
                        : null,
                'source_file' =>
                    $sourceFile !== ''
                        ? $sourceFile
                        : null,
                'line_number' =>
                    $lineNumber > 0
                        ? $lineNumber
                        : null,
                'column_number' =>
                    $columnNumber > 0
                        ? $columnNumber
                        : null,
                'disposition' =>
                    $disposition !== ''
                        ? $disposition
                        : null,
                'status_code' =>
                    $statusCode > 0
                        ? $statusCode
                        : null,
            ]);
        } catch (PDOException $e) {
            /*
             * Corrida entre dois reports idênticos.
             */
            if (
                (string)$e->getCode()
                !== '23000'
            ) {
                throw $e;
            }

            $update->execute([
                'status_code' =>
                    $statusCode > 0
                        ? $statusCode
                        : null,
                'fingerprint' =>
                    $fingerprint,
            ]);
        }

        self::maybeCleanup($pdo);

        return true;
    }

    /**
     * @return array{
     *   unique_reports:int,
     *   total_occurrences:int,
     *   last_24h:int,
     *   last_seen_at:?string
     * }
     */
    public static function summary(
        PDO $pdo
    ): array {
        self::ensureSchema($pdo);

        $row =
            $pdo
                ->query(
                    "SELECT
                        COUNT(*) AS unique_reports,
                        COALESCE(SUM(occurrences),0) AS total_occurrences,
                        COALESCE(SUM(
                            CASE
                                WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                THEN occurrences
                                ELSE 0
                            END
                        ),0) AS last_24h,
                        MAX(last_seen_at) AS last_seen_at
                     FROM security_csp_reports"
                )
                ->fetch(PDO::FETCH_ASSOC)
            ?: [];

        return [
            'unique_reports' =>
                (int)(
                    $row['unique_reports']
                    ?? 0
                ),
            'total_occurrences' =>
                (int)(
                    $row['total_occurrences']
                    ?? 0
                ),
            'last_24h' =>
                (int)(
                    $row['last_24h']
                    ?? 0
                ),
            'last_seen_at' =>
                !empty(
                    $row['last_seen_at']
                )
                    ? (string)$row['last_seen_at']
                    : null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(
        PDO $pdo,
        int $limit = 100
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    500,
                    $limit
                )
            );

        return
            $pdo
                ->query(
                    "SELECT *
                     FROM security_csp_reports
                     ORDER BY last_seen_at DESC,id DESC
                     LIMIT {$limit}"
                )
                ->fetchAll(PDO::FETCH_ASSOC)
            ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function topDirectives(
        PDO $pdo,
        int $limit = 10
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    30,
                    $limit
                )
            );

        return
            $pdo
                ->query(
                    "SELECT
                        COALESCE(
                            NULLIF(effective_directive,''),
                            NULLIF(violated_directive,''),
                            '(sem diretiva)'
                        ) AS directive_name,
                        COUNT(*) AS unique_count,
                        SUM(occurrences) AS occurrence_count,
                        MAX(last_seen_at) AS last_seen_at
                     FROM security_csp_reports
                     GROUP BY directive_name
                     ORDER BY occurrence_count DESC
                     LIMIT {$limit}"
                )
                ->fetchAll(PDO::FETCH_ASSOC)
            ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function topBlocked(
        PDO $pdo,
        int $limit = 10
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    30,
                    $limit
                )
            );

        return
            $pdo
                ->query(
                    "SELECT
                        COALESCE(NULLIF(blocked_uri,''),'(vazio)') AS blocked,
                        COUNT(*) AS unique_count,
                        SUM(occurrences) AS occurrence_count,
                        MAX(last_seen_at) AS last_seen_at
                     FROM security_csp_reports
                     GROUP BY blocked
                     ORDER BY occurrence_count DESC
                     LIMIT {$limit}"
                )
                ->fetchAll(PDO::FETCH_ASSOC)
            ?: [];
    }

    public static function clear(
        PDO $pdo
    ): int {
        self::ensureSchema($pdo);

        $count =
            (int)$pdo
                ->query(
                    "SELECT COUNT(*)
                     FROM security_csp_reports"
                )
                ->fetchColumn();

        $pdo->exec(
            "DELETE FROM security_csp_reports"
        );

        return $count;
    }

    public static function cleanup(
        PDO $pdo
    ): int {
        self::ensureSchema($pdo);

        $days =
            self::retentionDays(
                $pdo
            );

        $stmt =
            $pdo->prepare(
                "DELETE FROM security_csp_reports
                 WHERE last_seen_at < DATE_SUB(
                    NOW(),
                    INTERVAL :days DAY
                 )"
            );

        /*
         * MySQL/MariaDB não aceita placeholder em INTERVAL em todas as versões.
         * Valor é inteiro validado acima.
         */
        $sql =
            "DELETE FROM security_csp_reports
             WHERE last_seen_at < DATE_SUB(
                NOW(),
                INTERVAL {$days} DAY
             )";

        return
            (int)$pdo->exec(
                $sql
            );
    }

    private static function maybeCleanup(
        PDO $pdo,
        bool $force = false
    ): void {
        try {
            if (
                $force
                || random_int(
                    1,
                    100
                ) === 1
            ) {
                self::cleanup(
                    $pdo
                );
            }
        } catch (Throwable $ignored) {
        }
    }

    private static function cleanText(
        string $value,
        int $max
    ): string {
        $value =
            trim(
                preg_replace(
                    '/[\x00-\x1F\x7F]+/u',
                    ' ',
                    $value
                )
                ?? ''
            );

        return
            mb_substr(
                $value,
                0,
                $max
            );
    }

    private static function sanitizeUrlLike(
        string $value
    ): string {
        $value =
            self::cleanText(
                $value,
                1000
            );

        if ($value === '') {
            return '';
        }

        if (
            in_array(
                strtolower($value),
                [
                    'inline',
                    'eval',
                    'self',
                    'data',
                    'blob',
                    'about',
                ],
                true
            )
        ) {
            return
                strtolower(
                    $value
                );
        }

        $parts =
            @parse_url(
                $value
            );

        if (
            is_array($parts)
            && (
                isset($parts['scheme'])
                || isset($parts['host'])
            )
        ) {
            $scheme =
                isset($parts['scheme'])
                    ? strtolower(
                        (string)$parts['scheme']
                    )
                    : '';

            $host =
                isset($parts['host'])
                    ? strtolower(
                        (string)$parts['host']
                    )
                    : '';

            $port =
                isset($parts['port'])
                    ? ':'
                        . (int)$parts['port']
                    : '';

            $path =
                (string)(
                    $parts['path']
                    ?? ''
                );

            $safe =
                ($scheme !== ''
                    ? $scheme . '://'
                    : '')
                . $host
                . $port
                . $path;

            return
                mb_substr(
                    $safe,
                    0,
                    500
                );
        }

        /*
         * Caminho relativo ou pseudo-URI: remove query/fragmento.
         */
        $value =
            preg_split(
                '/[?#]/',
                $value,
                2
            )[0]
            ?? '';

        return
            mb_substr(
                $value,
                0,
                500
            );
    }
}
