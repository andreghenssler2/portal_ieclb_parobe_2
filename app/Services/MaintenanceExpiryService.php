<?php

declare(strict_types=1);

/**
 * Controle do período programado do modo manutenção.
 *
 * PORTAL_MAINTENANCE_SCHEDULE_V113_R3
 */
final class MaintenanceExpiryService
{
    /**
     * @return array{active:bool,state:string}
     */
    public static function windowState(
        bool $configuredEnabled,
        string $startAt,
        string $endAt,
        ?DateTimeImmutable $now = null
    ): array {
        if (!$configuredEnabled) {
            return [
                'active' => false,
                'state' => 'disabled',
            ];
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $now ??= new DateTimeImmutable('now', $timezone);

        $start = self::parseDate($startAt, $timezone);
        $end = self::parseDate($endAt, $timezone);

        if ($start !== null && $now < $start) {
            return [
                'active' => false,
                'state' => 'scheduled',
            ];
        }

        if ($end !== null && $now >= $end) {
            return [
                'active' => false,
                'state' => 'expired',
            ];
        }

        return [
            'active' => true,
            'state' => 'active',
        ];
    }

    public static function expireIfDue(PDO $pdo): bool
    {
        $configuredEnabled =
            (string)siteConfig(
                $pdo,
                'maintenance_enabled',
                '0'
            ) === '1';

        if (!$configuredEnabled) {
            return false;
        }

        $startAt =
            trim(
                (string)siteConfig(
                    $pdo,
                    'maintenance_start_at',
                    ''
                )
            );

        $endAt =
            trim(
                (string)siteConfig(
                    $pdo,
                    'maintenance_end_at',
                    siteConfig(
                        $pdo,
                        'maintenance_expected_end',
                        ''
                    )
                )
            );

        $state =
            self::windowState(
                true,
                $startAt,
                $endAt
            );

        if ($state['state'] !== 'expired') {
            return false;
        }

        saveSiteConfig(
            $pdo,
            'maintenance_enabled',
            '0',
            'booleano'
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
                    'Modo manutenção encerrado automaticamente na data final programada.',
                    'info'
                );
            } catch (Throwable $ignored) {
            }
        }

        return true;
    }

    private static function parseDate(
        string $value,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(
                $value,
                $timezone
            );
        } catch (Throwable $ignored) {
            return null;
        }
    }
}
