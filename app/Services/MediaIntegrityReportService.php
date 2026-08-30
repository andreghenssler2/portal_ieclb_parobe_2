<?php

declare(strict_types=1);

/**
 * Histórico e automação do diagnóstico de integridade da Biblioteca de Mídia.
 */
final class MediaIntegrityReportService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS midia_integridade_relatorios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                origem VARCHAR(30) NOT NULL DEFAULT 'scheduler',
                status VARCHAR(20) NOT NULL DEFAULT 'ok',
                mensagem VARCHAR(1000) NULL,
                arquivos_analisados INT UNSIGNED NOT NULL DEFAULT 0,
                scan_parcial TINYINT(1) NOT NULL DEFAULT 0,
                originais_ausentes INT UNSIGNED NOT NULL DEFAULT 0,
                tamanhos_divergentes INT UNSIGNED NOT NULL DEFAULT 0,
                variantes_ausentes INT UNSIGNED NOT NULL DEFAULT 0,
                arquivos_orfaos INT UNSIGNED NOT NULL DEFAULT 0,
                derivados_orfaos INT UNSIGNED NOT NULL DEFAULT 0,
                registros_variantes_removidos INT UNSIGNED NOT NULL DEFAULT 0,
                derivados_removidos INT UNSIGNED NOT NULL DEFAULT 0,
                bytes_liberados BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_midia_integridade_created (created_at),
                KEY idx_midia_integridade_status (status,created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    /**
     * Executa diagnóstico e opcionalmente a limpeza segura da v0.73.
     *
     * @return array<string,mixed>
     */
    public static function run(
        PDO $pdo,
        string $root,
        string $origin = 'scheduler',
        bool $safeCleanup = false
    ): array {
        self::ensureSchema($pdo);

        if (!class_exists('MediaIntegrityService')) {
            throw new RuntimeException(
                'MediaIntegrityService não está disponível.'
            );
        }

        $origin =
            self::normalizeOrigin(
                $origin
            );

        $service =
            new MediaIntegrityService(
                $pdo,
                $root
            );

        $cleanedVariantRecords = 0;
        $cleanedGenerated = [
            'removed' => 0,
            'bytes' => 0,
            'failed' => 0,
        ];

        if ($safeCleanup) {
            /*
             * As duas operações abaixo são propositalmente conservadoras:
             * só removem registros de variantes sem arquivo e derivados
             * conhecidos (.thumb.webp/.optimized.webp) órfãos.
             */
            $cleanedVariantRecords =
                $service
                    ->removeMissingVariantRecords();

            $cleanedGenerated =
                $service
                    ->removeOrphanGeneratedFiles();
        }

        /*
         * O relatório é sempre gerado DEPOIS da limpeza, portanto representa
         * o estado atual da biblioteca.
         */
        $scan =
            $service->scan(
                10000
            );

        $totals =
            (array)(
                $scan['totals']
                ?? []
            );

        $missingOriginals =
            (int)(
                $totals['missing_originals']
                ?? 0
            );

        $sizeMismatches =
            (int)(
                $totals['size_mismatches']
                ?? 0
            );

        $missingVariants =
            (int)(
                $totals['missing_variants']
                ?? 0
            );

        $orphanFiles =
            (int)(
                $totals['orphan_files']
                ?? 0
            );

        $orphanGenerated =
            (int)(
                $totals['orphan_generated']
                ?? 0
            );

        $reviewCount =
            $missingOriginals
            + $sizeMismatches
            + $orphanFiles;

        $technicalCount =
            $missingVariants
            + $orphanGenerated;

        $status =
            $missingOriginals > 0
                ? 'erro'
                : (
                    (
                        $sizeMismatches > 0
                        || $orphanFiles > 0
                        || $technicalCount > 0
                        || !empty($scan['scan_truncated'])
                    )
                        ? 'warning'
                        : 'ok'
                );

        $messageParts = [];

        if ($missingOriginals > 0) {
            $messageParts[] =
                $missingOriginals
                . ' original(is) ausente(s)';
        }

        if ($sizeMismatches > 0) {
            $messageParts[] =
                $sizeMismatches
                . ' tamanho(s) divergente(s)';
        }

        if ($orphanFiles > 0) {
            $messageParts[] =
                $orphanFiles
                . ' arquivo(s) físico(s) sem registro';
        }

        if ($missingVariants > 0) {
            $messageParts[] =
                $missingVariants
                . ' variante(s) ausente(s)';
        }

        if ($orphanGenerated > 0) {
            $messageParts[] =
                $orphanGenerated
                . ' derivado(s) órfão(s)';
        }

        if (!empty($scan['scan_truncated'])) {
            $messageParts[] =
                'scan parcial';
        }

        if (!$messageParts) {
            $messageParts[] =
                'biblioteca íntegra';
        }

        if ($safeCleanup) {
            $messageParts[] =
                'limpeza: '
                . $cleanedVariantRecords
                . ' registro(s) + '
                . (int)$cleanedGenerated['removed']
                . ' derivado(s)';
        }

        $message =
            implode(
                '; ',
                $messageParts
            );

        $stmt =
            $pdo->prepare(
                "INSERT INTO midia_integridade_relatorios
                    (
                        origem,
                        status,
                        mensagem,
                        arquivos_analisados,
                        scan_parcial,
                        originais_ausentes,
                        tamanhos_divergentes,
                        variantes_ausentes,
                        arquivos_orfaos,
                        derivados_orfaos,
                        registros_variantes_removidos,
                        derivados_removidos,
                        bytes_liberados,
                        created_at
                    )
                 VALUES
                    (
                        :origem,
                        :status,
                        :mensagem,
                        :arquivos_analisados,
                        :scan_parcial,
                        :originais_ausentes,
                        :tamanhos_divergentes,
                        :variantes_ausentes,
                        :arquivos_orfaos,
                        :derivados_orfaos,
                        :registros_variantes_removidos,
                        :derivados_removidos,
                        :bytes_liberados,
                        NOW()
                    )"
            );

        $stmt->execute([
            'origem' => $origin,
            'status' => $status,
            'mensagem' =>
                mb_substr(
                    $message,
                    0,
                    1000
                ),
            'arquivos_analisados' =>
                (int)(
                    $scan['scanned_files']
                    ?? 0
                ),
            'scan_parcial' =>
                !empty(
                    $scan['scan_truncated']
                )
                    ? 1
                    : 0,
            'originais_ausentes' =>
                $missingOriginals,
            'tamanhos_divergentes' =>
                $sizeMismatches,
            'variantes_ausentes' =>
                $missingVariants,
            'arquivos_orfaos' =>
                $orphanFiles,
            'derivados_orfaos' =>
                $orphanGenerated,
            'registros_variantes_removidos' =>
                $cleanedVariantRecords,
            'derivados_removidos' =>
                (int)$cleanedGenerated['removed'],
            'bytes_liberados' =>
                (int)$cleanedGenerated['bytes'],
        ]);

        $reportId =
            (int)$pdo->lastInsertId();

        /*
         * Mantém histórico suficiente para auditoria sem crescer para sempre.
         */
        self::prune(
            $pdo,
            120
        );

        return [
            'id' => $reportId,
            'status' => $status,
            'message' => $message,
            'review_count' => $reviewCount,
            'technical_count' => $technicalCount,
            'scan' => $scan,
            'cleaned_variant_records' =>
                $cleanedVariantRecords,
            'cleaned_generated' =>
                $cleanedGenerated,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function latest(
        PDO $pdo
    ): ?array {
        self::ensureSchema($pdo);

        $row =
            $pdo
                ->query(
                    "SELECT *
                     FROM midia_integridade_relatorios
                     ORDER BY id DESC
                     LIMIT 1"
                )
                ->fetch(PDO::FETCH_ASSOC);

        return
            $row
                ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function history(
        PDO $pdo,
        int $limit = 30
    ): array {
        self::ensureSchema($pdo);

        $limit =
            max(
                1,
                min(
                    120,
                    $limit
                )
            );

        return
            $pdo
                ->query(
                    "SELECT *
                     FROM midia_integridade_relatorios
                     ORDER BY id DESC
                     LIMIT {$limit}"
                )
                ->fetchAll(PDO::FETCH_ASSOC)
            ?: [];
    }

    public static function reviewCount(
        PDO $pdo
    ): int {
        $latest =
            self::latest($pdo);

        if (!$latest) {
            return 0;
        }

        return
            max(
                0,
                (int)$latest['originais_ausentes']
                + (int)$latest['tamanhos_divergentes']
                + (int)$latest['arquivos_orfaos']
            );
    }

    private static function prune(
        PDO $pdo,
        int $keep
    ): void {
        $keep =
            max(
                20,
                min(
                    500,
                    $keep
                )
            );

        $pdo->exec(
            "DELETE FROM midia_integridade_relatorios
             WHERE id NOT IN (
                SELECT id
                FROM (
                    SELECT id
                    FROM midia_integridade_relatorios
                    ORDER BY id DESC
                    LIMIT {$keep}
                ) AS keep_rows
             )"
        );
    }

    private static function normalizeOrigin(
        string $origin
    ): string {
        $origin =
            strtolower(
                trim($origin)
            );

        $origin =
            preg_replace(
                '/[^a-z0-9_-]+/',
                '-',
                $origin
            );

        if (
            !is_string($origin)
            || $origin === ''
        ) {
            $origin = 'scheduler';
        }

        return
            mb_substr(
                $origin,
                0,
                30
            );
    }
}
