<?php

declare(strict_types=1);

/**
 * Central administrativa de Segurança v1.1.11.
 *
 * Consolida recursos que já existiam no Portal:
 * tentativas/bloqueio de login, sessões administrativas, 2FA e auditoria.
 *
 * Este serviço é somente leitura. As ações de encerramento de sessão usam
 * SessionSecurityService diretamente na página administrativa.
 */
final class SecurityCenterService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $timeout =
            max(
                5,
                min(
                    1440,
                    (int)siteConfig(
                        $this->pdo,
                        'security_session_timeout_minutes',
                        '60'
                    )
                )
            );

        $maxAttempts =
            max(
                3,
                min(
                    20,
                    (int)siteConfig(
                        $this->pdo,
                        'security_max_login_attempts',
                        '5'
                    )
                )
            );

        $lockMinutes =
            max(
                1,
                min(
                    180,
                    (int)siteConfig(
                        $this->pdo,
                        'security_lockout_minutes',
                        '15'
                    )
                )
            );

        return [
            'timeout_minutes' =>
                $timeout,
            'max_attempts' =>
                $maxAttempts,
            'lock_minutes' =>
                $lockMinutes,
            'sessions' =>
                $this->activeSessions(
                    $timeout
                ),
            'login_history' =>
                $this->loginHistory(),
            'lockouts' =>
                $this->activeLockouts(
                    $maxAttempts,
                    $lockMinutes
                ),
            'two_factor' =>
                $this->twoFactorSummary(),
            'audit' =>
                $this->securityAudit(),
            'stats' =>
                $this->stats(
                    $timeout,
                    $maxAttempts,
                    $lockMinutes
                ),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activeSessions(
        int $timeout
    ): array {
        if (
            !class_exists(
                'SessionSecurityService'
            )
        ) {
            return [];
        }

        try {
            return
                SessionSecurityService::allActiveSessions(
                    $this->pdo,
                    $timeout,
                    80
                );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Histórico editorial/auditoria de logins.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loginHistory(): array
    {
        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        l.id,
                        l.usuario_id,
                        l.acao,
                        COALESCE(l.nivel,'info') AS nivel,
                        l.ip,
                        l.user_agent,
                        l.detalhes,
                        l.created_at,
                        u.nome AS usuario_nome,
                        u.email AS usuario_email
                     FROM logs l
                     LEFT JOIN usuarios u
                        ON u.id=l.usuario_id
                     WHERE
                        l.acao LIKE 'login.%'
                        OR l.acao='logout'
                     ORDER BY l.id DESC
                     LIMIT 30"
                );

            return
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                )
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Candidatos atualmente bloqueados conforme a mesma janela usada no login.
     *
     * @return array<int,array<string,mixed>>
     */
    private function activeLockouts(
        int $maxAttempts,
        int $lockMinutes
    ): array {
        $items = [];

        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        'email' AS tipo,
                        email AS identificador,
                        COUNT(*) AS falhas,
                        MAX(created_at) AS ultima_tentativa
                     FROM login_tentativas
                     WHERE sucesso=0
                       AND created_at >= DATE_SUB(
                            NOW(),
                            INTERVAL {$lockMinutes} MINUTE
                       )
                     GROUP BY email
                     HAVING COUNT(*) >= {$maxAttempts}
                     ORDER BY ultima_tentativa DESC
                     LIMIT 20"
                );

            foreach (
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                )
                ?: []
                as $row
            ) {
                $row['identificador_exibicao'] =
                    self::maskEmail(
                        (string)(
                            $row['identificador']
                            ?? ''
                        )
                    );

                $items[] = $row;
            }
        } catch (Throwable $e) {
        }

        try {
            $ipThreshold =
                $maxAttempts
                * 3;

            $stmt =
                $this->pdo->query(
                    "SELECT
                        'ip' AS tipo,
                        ip AS identificador,
                        COUNT(*) AS falhas,
                        MAX(created_at) AS ultima_tentativa
                     FROM login_tentativas
                     WHERE sucesso=0
                       AND ip IS NOT NULL
                       AND ip <> ''
                       AND created_at >= DATE_SUB(
                            NOW(),
                            INTERVAL {$lockMinutes} MINUTE
                       )
                     GROUP BY ip
                     HAVING COUNT(*) >= {$ipThreshold}
                     ORDER BY ultima_tentativa DESC
                     LIMIT 20"
                );

            foreach (
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                )
                ?: []
                as $row
            ) {
                $row['identificador_exibicao'] =
                    (string)(
                        $row['identificador']
                        ?? ''
                    );

                $items[] = $row;
            }
        } catch (Throwable $e) {
        }

        usort(
            $items,
            static function (
                array $a,
                array $b
            ): int {
                return
                    strcmp(
                        (string)(
                            $b['ultima_tentativa']
                            ?? ''
                        ),
                        (string)(
                            $a['ultima_tentativa']
                            ?? ''
                        )
                    );
            }
        );

        return
            array_slice(
                $items,
                0,
                20
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function twoFactorSummary(): array
    {
        $summary = [
            'active_users' => 0,
            'enabled_users' => 0,
            'disabled_users' => 0,
            'coverage_percent' => 0,
            'without_2fa' => [],
            'current_user_enabled' => false,
        ];

        try {
            $summary['active_users'] =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM usuarios
                         WHERE ativo=1"
                    )
                    ->fetchColumn();

            $summary['enabled_users'] =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM usuarios
                         WHERE ativo=1
                           AND totp_enabled_at IS NOT NULL
                           AND totp_secret IS NOT NULL
                           AND totp_secret <> ''"
                    )
                    ->fetchColumn();

            $summary['disabled_users'] =
                max(
                    0,
                    $summary['active_users']
                    - $summary['enabled_users']
                );

            if ($summary['active_users'] > 0) {
                $summary['coverage_percent'] =
                    (int)round(
                        (
                            $summary['enabled_users']
                            / $summary['active_users']
                        )
                        * 100
                    );
            }

            $stmt =
                $this->pdo->query(
                    "SELECT
                        id,
                        nome,
                        email,
                        ultimo_login
                     FROM usuarios
                     WHERE ativo=1
                       AND (
                            totp_enabled_at IS NULL
                            OR totp_secret IS NULL
                            OR totp_secret=''
                       )
                     ORDER BY
                        ultimo_login DESC,
                        nome
                     LIMIT 10"
                );

            $summary['without_2fa'] =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                )
                ?: [];

            $currentId =
                (int)(
                    Auth::id()
                    ?: 0
                );

            if ($currentId > 0) {
                $stmt =
                    $this->pdo->prepare(
                        "SELECT COUNT(*)
                         FROM usuarios
                         WHERE id=?
                           AND totp_enabled_at IS NOT NULL
                           AND totp_secret IS NOT NULL
                           AND totp_secret <> ''"
                    );

                $stmt->execute([
                    $currentId,
                ]);

                $summary['current_user_enabled'] =
                    (int)$stmt->fetchColumn()
                    > 0;
            }
        } catch (Throwable $e) {
        }

        return $summary;
    }

    /**
     * Últimos eventos de segurança na auditoria.
     *
     * @return array<int,array<string,mixed>>
     */
    private function securityAudit(): array
    {
        try {
            $stmt =
                $this->pdo->query(
                    "SELECT
                        l.id,
                        l.usuario_id,
                        l.acao,
                        COALESCE(l.nivel,'info') AS nivel,
                        l.entidade,
                        l.entidade_id,
                        l.detalhes,
                        l.ip,
                        l.created_at,
                        u.nome AS usuario_nome
                     FROM logs l
                     LEFT JOIN usuarios u
                        ON u.id=l.usuario_id
                     WHERE
                        l.acao LIKE 'login.%'
                        OR l.acao LIKE 'seguranca.%'
                        OR l.acao LIKE 'conta.2fa.%'
                        OR l.acao='acesso.negado'
                        OR l.acao='logout'
                     ORDER BY l.id DESC
                     LIMIT 20"
                );

            return
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                )
                ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,int>
     */
    private function stats(
        int $timeout,
        int $maxAttempts,
        int $lockMinutes
    ): array {
        $sessions =
            $this->activeSessions(
                $timeout
            );

        $twoFactor =
            $this->twoFactorSummary();

        $lockouts =
            $this->activeLockouts(
                $maxAttempts,
                $lockMinutes
            );

        $failed24h = 0;
        $warnings24h = 0;

        try {
            $failed24h =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM login_tentativas
                         WHERE sucesso=0
                           AND created_at >= DATE_SUB(
                                NOW(),
                                INTERVAL 24 HOUR
                           )"
                    )
                    ->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $warnings24h =
                (int)$this->pdo
                    ->query(
                        "SELECT COUNT(*)
                         FROM logs
                         WHERE COALESCE(nivel,'info')
                            IN ('warning','critical')
                           AND created_at >= DATE_SUB(
                                NOW(),
                                INTERVAL 24 HOUR
                           )
                           AND (
                                acao LIKE 'login.%'
                                OR acao LIKE 'seguranca.%'
                                OR acao LIKE 'conta.2fa.%'
                                OR acao='acesso.negado'
                           )"
                    )
                    ->fetchColumn();
        } catch (Throwable $e) {
        }

        return [
            'sessions' =>
                count($sessions),
            'failed_24h' =>
                $failed24h,
            'lockouts' =>
                count($lockouts),
            'two_factor_enabled' =>
                (int)(
                    $twoFactor['enabled_users']
                    ?? 0
                ),
            'two_factor_total' =>
                (int)(
                    $twoFactor['active_users']
                    ?? 0
                ),
            'warnings_24h' =>
                $warnings24h,
        ];
    }

    private static function maskEmail(
        string $email
    ): string {
        $email =
            trim($email);

        $parts =
            explode(
                '@',
                $email,
                2
            );

        if (count($parts) !== 2) {
            return
                mb_substr(
                    $email,
                    0,
                    2
                )
                . '***';
        }

        $local =
            (string)$parts[0];

        return
            mb_substr(
                $local,
                0,
                min(
                    2,
                    mb_strlen($local)
                )
            )
            . '***@'
            . $parts[1];
    }
}
