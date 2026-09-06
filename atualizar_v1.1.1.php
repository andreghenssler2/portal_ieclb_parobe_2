<?php

declare(strict_types=1);

ob_start();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este atualizador somente pelo terminal.\n");
}

$root = __DIR__;
$payload =
    $root
    . DIRECTORY_SEPARATOR
    . '_update_payload_v1.1.1';

$stamp = date('Ymd-His');

function v111Fail(string $message): never
{
    fwrite(STDERR, "[ERRO] {$message}\n");
    exit(1);
}

function v111Path(string $root, string $relative): string
{
    return $root
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function v111Read(string $file, string $label): string
{
    if (!is_file($file)) {
        v111Fail("Arquivo não encontrado: {$label}");
    }

    $content = file_get_contents($file);

    if ($content === false) {
        v111Fail("Não foi possível ler: {$label}");
    }

    return $content;
}

function v111Lint(string $content, string $label): void
{
    if (!function_exists('exec')) {
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ieclb_v111_');

    if (
        $tmp === false
        || file_put_contents($tmp, $content) === false
    ) {
        if ($tmp) {
            @unlink($tmp);
        }

        v111Fail("Não foi possível validar {$label}.");
    }

    $output = [];
    $status = 0;

    @exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($tmp)
        . ' 2>&1',
        $output,
        $status
    );

    @unlink($tmp);

    if ($status !== 0) {
        v111Fail(
            "Erro de sintaxe em {$label}:\n"
            . implode("\n", $output)
        );
    }
}

function v111WriteAtomic(string $file, string $content): void
{
    $dir = dirname($file);

    if (
        !is_dir($dir)
        && !@mkdir($dir, 0775, true)
        && !is_dir($dir)
    ) {
        v111Fail("Não foi possível criar: {$dir}");
    }

    $tmp = $file . '.tmp-v111';

    if (
        file_put_contents(
            $tmp,
            $content,
            LOCK_EX
        ) === false
    ) {
        v111Fail("Não foi possível gravar: {$file}");
    }

    if (
        DIRECTORY_SEPARATOR === '\\'
        && is_file($file)
        && !@unlink($file)
    ) {
        @unlink($tmp);
        v111Fail("Não foi possível substituir: {$file}");
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        v111Fail("Não foi possível finalizar: {$file}");
    }
}

function v111PatchVersion(
    string $content,
    string $label
): string {
    $pattern =
        '~define\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*\)\s*;~';

    $count = 0;

    $patched =
        preg_replace(
            $pattern,
            "define('APP_VERSION', '1.1.1');",
            $content,
            1,
            $count
        );

    if (
        !is_string($patched)
        || $count !== 1
    ) {
        v111Fail(
            "{$label}: APP_VERSION não encontrado."
        );
    }

    return $patched;
}

function v111PatchScheduler(string $content): string
{
    /*
     * 1. Registry.
     */
    if (
        !str_contains(
            $content,
            'PORTAL_HEALTH_SNAPSHOT_TASK_V111'
        )
    ) {
        $anchor =
            "'backup_completo_automatico' => [";

        $pos =
            strpos(
                $content,
                $anchor
            );

        if ($pos === false) {
            v111Fail(
                'SchedulerService.php: tarefa de backup completo não encontrada para ancoragem.'
            );
        }

        $task = <<<'PHP'
            /* PORTAL_HEALTH_SNAPSHOT_TASK_V111 */
            'registrar_saude_portal' => [
                'name' => 'Registrar saúde do Portal',
                'description' => 'Grava diariamente um snapshot da saúde operacional e alerta administradores quando houver piora.',
                'interval' => 1440,
                'enabled' => true,
                'priority' => 58,
            ],

PHP;

        $content =
            substr(
                $content,
                0,
                $pos
            )
            . $task
            . substr(
                $content,
                $pos
            );
    }

    /*
     * 2. Dispatch.
     */
    if (
        !str_contains(
            $content,
            'PORTAL_HEALTH_SNAPSHOT_HANDLER_V111'
        )
    ) {
        $anchor =
            "'backup_completo_automatico' => self::automaticFullBackup(\$pdo),";

        $pos =
            strpos(
                $content,
                $anchor
            );

        if ($pos === false) {
            v111Fail(
                'SchedulerService.php: handler de backup completo não encontrado.'
            );
        }

        $lineEnd =
            strpos(
                $content,
                "\n",
                $pos
            );

        if ($lineEnd === false) {
            v111Fail(
                'SchedulerService.php: linha do dispatch incompleta.'
            );
        }

        $handler = <<<'PHP'

            /* PORTAL_HEALTH_SNAPSHOT_HANDLER_V111 */
            'registrar_saude_portal' => self::automaticPortalHealthSnapshot($pdo),
PHP;

        $content =
            substr(
                $content,
                0,
                $lineEnd + 1
            )
            . $handler
            . substr(
                $content,
                $lineEnd + 1
            );
    }

    /*
     * 3. Método executor e alerta.
     */
    if (
        !str_contains(
            $content,
            'PORTAL_HEALTH_SNAPSHOT_ALERT_V111'
        )
    ) {
        $anchor =
            'private static function automaticDatabaseBackup(PDO $pdo): array';

        $pos =
            strpos(
                $content,
                $anchor
            );

        if ($pos === false) {
            v111Fail(
                'SchedulerService.php: método automaticDatabaseBackup não encontrado.'
            );
        }

        $method = <<<'PHP'
    /**
     * Snapshot automático da saúde operacional.
     *
     * @return array{status:string,message:string}
     */
    private static function automaticPortalHealthSnapshot(
        PDO $pdo
    ): array {
        /* PORTAL_HEALTH_SNAPSHOT_ALERT_V111 */
        if (
            !class_exists(
                'PortalHealthSnapshotService'
            )
        ) {
            return [
                'status' => 'ignorado',
                'message' =>
                    'PortalHealthSnapshotService não está disponível.',
            ];
        }

        $root =
            dirname(
                __DIR__,
                2
            );

        $previousHistory =
            PortalHealthSnapshotService::history(
                $root,
                1
            );

        $previous =
            $previousHistory[0]
            ?? null;

        $current =
            PortalHealthSnapshotService::save(
                $pdo,
                $root,
                'cron'
            );

        $currentScore =
            (int)($current['score'] ?? 0);

        $currentWarnings =
            array_values(
                array_unique(
                    array_map(
                        'strval',
                        (array)($current['warnings'] ?? [])
                    )
                )
            );

        $currentBlockers =
            array_values(
                array_unique(
                    array_map(
                        'strval',
                        (array)($current['blockers'] ?? [])
                    )
                )
            );

        $alertReasons = [];

        if (is_array($previous)) {
            $previousScore =
                (int)($previous['score'] ?? 0);

            $previousWarnings =
                array_values(
                    array_unique(
                        array_map(
                            'strval',
                            (array)($previous['warnings'] ?? [])
                        )
                    )
                );

            $previousBlockers =
                array_values(
                    array_unique(
                        array_map(
                            'strval',
                            (array)($previous['blockers'] ?? [])
                        )
                    )
                );

            if ($currentScore < $previousScore) {
                $alertReasons[] =
                    'pontuação caiu de '
                    . $previousScore
                    . '% para '
                    . $currentScore
                    . '%';
            }

            $newWarnings =
                array_values(
                    array_diff(
                        $currentWarnings,
                        $previousWarnings
                    )
                );

            if ($newWarnings) {
                $alertReasons[] =
                    count($newWarnings)
                    . ' novo(s) aviso(s)';
            }

            $newBlockers =
                array_values(
                    array_diff(
                        $currentBlockers,
                        $previousBlockers
                    )
                );

            if ($newBlockers) {
                $alertReasons[] =
                    count($newBlockers)
                    . ' novo(s) bloqueador(es)';
            }
        } elseif ($currentBlockers) {
            /*
             * Primeiro snapshot automático: bloqueador já existente também
             * merece alerta.
             */
            $alertReasons[] =
                count($currentBlockers)
                . ' bloqueador(es) detectado(s)';
        }

        $notified = 0;

        if (
            $alertReasons
            && class_exists(
                'AdminNotificationService'
            )
        ) {
            try {
                $adminIds =
                    $pdo->query(
                        "SELECT u.id
                         FROM usuarios u
                         INNER JOIN perfis p
                            ON p.id=u.perfil_id
                         WHERE u.ativo=1
                           AND p.slug='administrador'
                         ORDER BY u.id"
                    )->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                    ?: [];

                $message =
                    'Snapshot automático: '
                    . implode(
                        '; ',
                        $alertReasons
                    )
                    . '. Estado atual: '
                    . strtoupper(
                        (string)($current['state'] ?? 'attention')
                    )
                    . '; avisos: '
                    . count($currentWarnings)
                    . '; bloqueadores: '
                    . count($currentBlockers)
                    . '.';

                foreach ($adminIds as $adminId) {
                    $adminId =
                        (int)$adminId;

                    if ($adminId <= 0) {
                        continue;
                    }

                    AdminNotificationService::notify(
                        $pdo,
                        $adminId,
                        'portal-health:auto',
                        'Saúde do Portal requer atenção',
                        $message,
                        'admin/ferramentas/saude-portal.php',
                        $currentBlockers
                            ? 'danger'
                            : 'warning',
                        'bi-heart-pulse',
                        true
                    );

                    $notified++;
                }
            } catch (Throwable $ignored) {
                /*
                 * Falha ao criar notificação não invalida o snapshot.
                 * A execução permanece registrada no histórico do agendador.
                 */
            }
        }

        $message =
            'Snapshot automático: '
            . $currentScore
            . '% - '
            . strtoupper(
                (string)($current['state'] ?? 'attention')
            )
            . '.';

        if ($alertReasons) {
            $message .=
                ' Alteração relevante: '
                . implode(
                    '; ',
                    $alertReasons
                )
                . '.';

            if ($notified > 0) {
                $message .=
                    ' '
                    . $notified
                    . ' administrador(es) notificado(s).';
            }
        } else {
            $message .=
                ' Sem piora relevante em relação ao snapshot anterior.';
        }

        return [
            'status' => 'ok',
            'message' => $message,
        ];
    }

PHP;

        $content =
            substr(
                $content,
                0,
                $pos
            )
            . $method
            . substr(
                $content,
                $pos
            );
    }

    return $content;
}

function v111PatchChangelog(string $content): string
{
    if (str_contains($content, '## v1.1.1')) {
        return $content;
    }

    $section = <<<'MD'

## v1.1.1 — Snapshots automáticos

- adiciona tarefa diária `registrar_saude_portal`;
- grava snapshots automáticos pelo cron;
- compara o snapshot novo com o anterior;
- alerta administradores quando a pontuação cair, surgir novo aviso ou bloqueador;
- utiliza a Central de Notificações já existente;
- sem nova tabela ou migração de banco.

MD;

    $firstBreak =
        strpos(
            $content,
            "\n"
        );

    return
        $firstBreak === false
            ? $content . $section
            : substr(
                $content,
                0,
                $firstBreak + 1
            )
                . $section
                . substr(
                    $content,
                    $firstBreak + 1
                );
}

echo "Portal IECLB Parobé - Atualização v1.1.1 Snapshots Automáticos\n";
echo str_repeat('=', 82) . "\n";

require_once v111Path(
    $root,
    'bootstrap.php'
);

$currentVersion =
    defined('APP_VERSION')
        ? (string)APP_VERSION
        : '0.0.0';

echo "Versão atual: {$currentVersion}\n\n";

if (
    version_compare(
        $currentVersion,
        '1.1.0',
        '<'
    )
) {
    v111Fail(
        'A v1.1.1 requer a v1.1.0 instalada.'
    );
}

if (
    version_compare(
        $currentVersion,
        '1.1.1',
        '>'
    )
) {
    v111Fail(
        "A instalação está em versão superior: {$currentVersion}."
    );
}

if (
    !class_exists(
        'PortalHealthSnapshotService'
    )
) {
    v111Fail(
        'PortalHealthSnapshotService ausente. Confirme a instalação da v1.1.0.'
    );
}

if (class_exists('ProductionReadinessService')) {
    $pdo =
        Database::connection();

    $report =
        ProductionReadinessService::report(
            $pdo,
            $root
        );

    echo '[INFO] Saúde atual: '
        . (int)$report['passed']
        . '/'
        . (int)$report['checks']
        . ' aprovadas; '
        . count($report['warnings'])
        . ' aviso(s); '
        . count($report['blockers'])
        . " bloqueador(es).\n";

    if ($report['blockers']) {
        foreach ($report['blockers'] as $blocker) {
            echo "[BLOQUEADOR] {$blocker}\n";
        }

        v111Fail(
            'A v1.1.1 não será instalada enquanto houver bloqueadores.'
        );
    }
}

if (
    is_file(
        v111Path(
            $root,
            'tests/run.php'
        )
    )
    && function_exists('exec')
) {
    $output = [];
    $status = 0;

    @exec(
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg(
            v111Path(
                $root,
                'tests/run.php'
            )
        )
        . ' 2>&1',
        $output,
        $status
    );

    echo "\n" . implode("\n", $output) . "\n";

    if ($status !== 0) {
        v111Fail(
            'A suíte geral falhou antes da atualização. Nenhum arquivo foi alterado.'
        );
    }

    echo "\n[OK] Pré-flight de qualidade aprovado.\n";
}

$prepared = [];

/*
 * Arquivos novos.
 */
foreach (
    [
        'tests/portal-health-automation.php',
        'docs/RELEASE_v1.1.1.md',
    ]
    as $relative
) {
    $source =
        v111Path(
            $payload,
            $relative
        );

    $content =
        v111Read(
            $source,
            $relative
        );

    if (str_ends_with($relative, '.php')) {
        v111Lint(
            $content,
            $relative
        );
    }

    $target =
        v111Path(
            $root,
            $relative
        );

    $prepared[$relative] = [
        'file' => $target,
        'original' =>
            is_file($target)
                ? v111Read($target, $relative)
                : null,
        'patched' => $content,
    ];

    echo "[OK] {$relative} preparado.\n";
}

/*
 * Scheduler: patch mínimo sobre o arquivo realmente instalado.
 */
$schedulerRelative =
    'app/Services/SchedulerService.php';

$schedulerFile =
    v111Path(
        $root,
        $schedulerRelative
    );

$schedulerOriginal =
    v111Read(
        $schedulerFile,
        $schedulerRelative
    );

$schedulerPatched =
    v111PatchScheduler(
        $schedulerOriginal
    );

v111Lint(
    $schedulerPatched,
    $schedulerRelative
);

$prepared[$schedulerRelative] = [
    'file' => $schedulerFile,
    'original' => $schedulerOriginal,
    'patched' => $schedulerPatched,
];

echo "[OK] {$schedulerRelative} preparado.\n";

foreach (
    [
        'config/config.php',
        'config/config.example.php',
    ]
    as $relative
) {
    $file =
        v111Path(
            $root,
            $relative
        );

    $original =
        v111Read(
            $file,
            $relative
        );

    $patched =
        v111PatchVersion(
            $original,
            $relative
        );

    v111Lint(
        $patched,
        $relative
    );

    $prepared[$relative] = [
        'file' => $file,
        'original' => $original,
        'patched' => $patched,
    ];

    echo "[OK] {$relative} preparado para 1.1.1.\n";
}

$changelog =
    v111Path(
        $root,
        'CHANGELOG.md'
    );

if (is_file($changelog)) {
    $original =
        v111Read(
            $changelog,
            'CHANGELOG.md'
        );

    $prepared['CHANGELOG.md'] = [
        'file' => $changelog,
        'original' => $original,
        'patched' =>
            v111PatchChangelog(
                $original
            ),
    ];

    echo "[OK] CHANGELOG.md preparado.\n";
}

$markers = [
    'tests/portal-health-automation.php' =>
        'teste automação Saúde do Portal v1.1.1',
    'docs/RELEASE_v1.1.1.md' =>
        'Snapshots automáticos',
    'app/Services/SchedulerService.php' =>
        'PORTAL_HEALTH_SNAPSHOT_ALERT_V111',
];

foreach ($markers as $relative => $marker) {
    if (
        !isset($prepared[$relative])
        || !str_contains(
            $prepared[$relative]['patched'],
            $marker
        )
    ) {
        v111Fail(
            "Validação em memória falhou: {$relative} / {$marker}"
        );
    }
}

foreach (
    [
        'PORTAL_HEALTH_SNAPSHOT_TASK_V111',
        'PORTAL_HEALTH_SNAPSHOT_HANDLER_V111',
        "'registrar_saude_portal'",
        'automaticPortalHealthSnapshot',
    ]
    as $marker
) {
    if (
        !str_contains(
            $schedulerPatched,
            $marker
        )
    ) {
        v111Fail(
            "Validação do Scheduler falhou: {$marker}"
        );
    }
}

echo "[OK] Todas as alterações validadas em memória.\n";

$backupRoot =
    $root
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'update-backups'
    . DIRECTORY_SEPARATOR
    . 'v1.1.1-snapshots-automaticos-'
    . $stamp;

foreach ($prepared as $relative => $info) {
    if ($info['original'] === null) {
        continue;
    }

    $backupFile =
        v111Path(
            $backupRoot,
            $relative
        );

    $dir =
        dirname(
            $backupFile
        );

    if (
        !is_dir($dir)
        && !@mkdir(
            $dir,
            0775,
            true
        )
        && !is_dir($dir)
    ) {
        v111Fail(
            'Não foi possível criar a pasta de backup.'
        );
    }

    if (
        file_put_contents(
            $backupFile,
            $info['original'],
            LOCK_EX
        ) === false
    ) {
        v111Fail(
            "Não foi possível fazer backup de {$relative}."
        );
    }
}

echo "[OK] Backup: storage/update-backups/v1.1.1-snapshots-automaticos-{$stamp}/\n";

foreach ($prepared as $relative => $info) {
    v111WriteAtomic(
        $info['file'],
        $info['patched']
    );

    echo "[OK] {$relative} atualizado.\n";
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "[OK] OPcache invalidado quando disponível.\n";

/*
 * Child processes carregam o Scheduler novo e também registram a nova tarefa
 * na tabela existente via SchedulerService::tasks()/ensureRegistry().
 */
if (function_exists('exec')) {
    foreach (
        [
            'tests/run.php',
            'tests/portal-health.php',
            'tests/portal-health-automation.php',
            'tests/release-readiness.php',
            'tests/release-final.php',
        ]
        as $relative
    ) {
        $file =
            v111Path(
                $root,
                $relative
            );

        if (!is_file($file)) {
            continue;
        }

        $output = [];
        $status = 0;

        @exec(
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($file)
            . ' 2>&1',
            $output,
            $status
        );

        echo "\n" . implode("\n", $output) . "\n";

        if ($status !== 0) {
            echo "\n[AVISO] {$relative} encontrou problema após a atualização.\n";
        } else {
            echo "\n[OK] {$relative} aprovado.\n";
        }
    }
}

echo "\n";
echo str_repeat('=', 82) . "\n";
echo " PORTAL IECLB PAROBÉ v1.1.1 CONCLUÍDO\n";
echo str_repeat('=', 82) . "\n\n";

echo "APP_VERSION agora é 1.1.1.\n";
echo "Nova tarefa: registrar_saude_portal (diária / 1440 min).\n";
echo "Não houve criação de nova tabela.\n\n";
echo "Teste manual da automação:\n";
echo "  php cron.php --task=registrar_saude_portal\n\n";
echo "Validação:\n";
echo "  php diagnosticar_v1.1.1.php\n";
echo "  php tests/portal-health-automation.php\n";
echo "  php tests/release-readiness.php\n";
echo "  php tests/release-final.php\n";
