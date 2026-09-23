<?php

declare(strict_types=1);

/**
 * Encerra automaticamente o modo manutenção quando a previsão de retorno
 * configurada já tiver sido atingida.
 */
final class MaintenanceExpiryService
{
    public static function expireIfDue(PDO $pdo): bool
    {
        $enabled =
            (string)siteConfig(
                $pdo,
                'maintenance_enabled',
                '0'
            ) === '1';

        $expectedEnd =
            trim(
                (string)siteConfig(
                    $pdo,
                    'maintenance_expected_end',
                    ''
                )
            );

        if (
            !self::isExpired(
                $enabled,
                $expectedEnd
            )
        ) {
            return false;
        }

        /*
         * PORTAL_MAINTENANCE_AUTO_EXPIRE_V113_R2
         *
         * Persiste a desativação para que a área administrativa e todas as
         * requisições seguintes enxerguem o estado correto.
         */
        saveSiteConfig(
            $pdo,
            'maintenance_enabled',
            '0',
            'booleano'
        );

        saveSiteConfig(
            $pdo,
            'maintenance_enabled_at',
            '',
            'texto'
        );

        saveSiteConfig(
            $pdo,
            'maintenance_expected_end',
            '',
            'texto'
        );

        saveSiteConfig(
            $pdo,
            'maintenance_last_auto_disabled_at',
            date('Y-m-d H:i:s'),
            'texto'
        );

        if (function_exists('logAction')) {
            try {
                logAction(
                    $pdo,
                    'manutencao.expirar',
                    'configuracoes',
                    null,
                    'Modo manutenção encerrado automaticamente ao atingir a previsão de retorno.',
                    'info'
                );
            } catch (Throwable $ignored) {
            }
        }

        return true;
    }

    public static function isExpired(
        bool $enabled,
        string $expectedEnd,
        ?DateTimeImmutable $now = null
    ): bool {
        if (!$enabled) {
            return false;
        }

        $expectedEnd =
            trim(
                $expectedEnd
            );

        if ($expectedEnd === '') {
            return false;
        }

        try {
            $timezone =
                new DateTimeZone(
                    date_default_timezone_get()
                );

            $deadline =
                new DateTimeImmutable(
                    $expectedEnd,
                    $timezone
                );

            $now ??=
                new DateTimeImmutable(
                    'now',
                    $timezone
                );

            return $now >= $deadline;
        } catch (Throwable $ignored) {
            /*
             * Valor inválido não deve derrubar o Portal. A tela administrativa
             * continua disponível para correção manual.
             */
            return false;
        }
    }
}
