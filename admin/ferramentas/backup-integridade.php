<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'backups.gerenciar'
);

$pdo =
    Database::connection();

$root =
    dirname(
        __DIR__,
        2
    );

SchedulerService::ensureRegistry(
    $pdo
);

$service =
    new BackupIntegrityService(
        $pdo,
        $root
    );

$error = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $error =
            'Token de segurança inválido.';
    } else {
        try {
            $action =
                trim(
                    (string)(
                        $_POST['acao']
                        ?? ''
                    )
                );

            if ($action === 'integridade') {
                $result =
                    $service->run(
                        'manual'
                    );

                logAction(
                    $pdo,
                    'backup.integridade.executar',
                    'backup',
                    null,
                    !empty($result['ok'])
                        ? 'Verificação manual concluída sem erros.'
                        : (
                            'Falhas: '
                            . implode(
                                ' | ',
                                array_map(
                                    'strval',
                                    (array)(
                                        $result['errors']
                                        ?? []
                                    )
                                )
                            )
                        ),
                    !empty($result['ok'])
                        ? 'info'
                        : 'warning'
                );

                if (!empty($result['ok'])) {
                    Session::flash(
                        'success',
                        'Integridade verificada. Nenhuma restauração foi executada.'
                    );
                } else {
                    Session::flash(
                        'error',
                        'A verificação encontrou problema(s). Consulte o resultado abaixo.'
                    );
                }
            } elseif ($action === 'retencao') {
                $dbRetention =
                    max(
                        1,
                        min(
                            100,
                            (int)(
                                $_POST['backup_retention_count']
                                ?? 10
                            )
                        )
                    );

                $fullRetention =
                    max(
                        1,
                        min(
                            50,
                            (int)(
                                $_POST['backup_full_retention_count']
                                ?? 5
                            )
                        )
                    );

                $staleHours =
                    max(
                        12,
                        min(
                            720,
                            (int)(
                                $_POST['backup_stale_hours']
                                ?? 36
                            )
                        )
                    );

                saveSiteConfig(
                    $pdo,
                    'backup_retention_count',
                    (string)$dbRetention,
                    'numero'
                );

                saveSiteConfig(
                    $pdo,
                    'backup_full_retention_count',
                    (string)$fullRetention,
                    'numero'
                );

                saveSiteConfig(
                    $pdo,
                    'backup_stale_hours',
                    (string)$staleHours,
                    'numero'
                );

                $database =
                    new BackupService(
                        $pdo,
                        $root
                    );

                $full =
                    new FullBackupService(
                        $pdo,
                        $root
                    );

                $removedDb =
                    $database
                        ->pruneDatabaseBackups(
                            $dbRetention
                        );

                $removedFull =
                    $full
                        ->pruneFullBackups(
                            $fullRetention
                        );

                logAction(
                    $pdo,
                    'backup.v1112.retencao.atualizar',
                    'configuracoes',
                    null,
                    'Banco='
                    . $dbRetention
                    . '; completos='
                    . $fullRetention
                    . '; alerta='
                    . $staleHours
                    . 'h; removidos='
                    . (
                        $removedDb
                        + $removedFull
                    )
                );

                Session::flash(
                    'success',
                    'Retenção e alerta de antiguidade atualizados.'
                );
            } elseif ($action === 'agendamento') {
                $tasks = [
                    'backup_banco_automatico' => [
                        'active' =>
                            isset(
                                $_POST['backup_db_active']
                            ),
                        'interval' =>
                            max(
                                60,
                                min(
                                    10080,
                                    (int)(
                                        $_POST['backup_db_interval']
                                        ?? 1440
                                    )
                                )
                            ),
                    ],
                    'backup_completo_automatico' => [
                        'active' =>
                            isset(
                                $_POST['backup_full_active']
                            ),
                        'interval' =>
                            max(
                                60,
                                min(
                                    10080,
                                    (int)(
                                        $_POST['backup_full_interval']
                                        ?? 10080
                                    )
                                )
                            ),
                    ],
                    'backup_integridade_automatico' => [
                        'active' =>
                            isset(
                                $_POST['backup_integrity_active']
                            ),
                        'interval' =>
                            max(
                                60,
                                min(
                                    10080,
                                    (int)(
                                        $_POST['backup_integrity_interval']
                                        ?? 1440
                                    )
                                )
                            ),
                    ],
                ];

                $stmt =
                    $pdo->prepare(
                        "UPDATE tarefas_agendadas
                         SET
                            ativa=:ativa,
                            intervalo_minutos=:intervalo,
                            proxima_execucao_em=CASE
                                WHEN :ativa2=1
                                  AND (
                                    proxima_execucao_em IS NULL
                                    OR proxima_execucao_em<NOW()
                                  )
                                THEN NOW()
                                ELSE proxima_execucao_em
                            END,
                            updated_at=NOW()
                         WHERE slug=:slug"
                    );

                foreach (
                    $tasks
                    as $slug => $task
                ) {
                    $active =
                        !empty(
                            $task['active']
                        )
                            ? 1
                            : 0;

                    $stmt->execute([
                        'ativa' =>
                            $active,
                        'ativa2' =>
                            $active,
                        'intervalo' =>
                            (int)$task['interval'],
                        'slug' =>
                            $slug,
                    ]);
                }

                logAction(
                    $pdo,
                    'backup.v1112.agendamento.atualizar',
                    'tarefas_agendadas',
                    null,
                    'Agendamento de backup e integridade atualizado.'
                );

                Session::flash(
                    'success',
                    'Agendamento dos backups atualizado.'
                );
            } else {
                throw new RuntimeException(
                    'Ação inválida.'
                );
            }

            header(
                'Location: '
                . url(
                    'admin/ferramentas/backup-integridade.php'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$status =
    $service->status();

$db =
    (array)(
        $status['database']
        ?? []
    );

$full =
    (array)(
        $status['full']
        ?? []
    );

$last =
    $status['last_integrity']
    ?? null;

$taskDb =
    (array)(
        $status['task_database']
        ?? []
    );

$taskFull =
    (array)(
        $status['task_full']
        ?? []
    );

$taskIntegrity =
    (array)(
        $status['task_integrity']
        ?? []
    );

$dbRetention =
    max(
        1,
        min(
            100,
            (int)siteConfig(
                $pdo,
                'backup_retention_count',
                '10'
            )
        )
    );

$fullRetention =
    max(
        1,
        min(
            50,
            (int)siteConfig(
                $pdo,
                'backup_full_retention_count',
                '5'
            )
        )
    );

$staleHours =
    (int)(
        $status['stale_hours']
        ?? 36
    );

$pageTitle =
    'Backup e Integridade';

require __DIR__ . '/../_header.php';

$taskBadge =
    static function (
        array $task
    ): string {
        if (empty($task['active'])) {
            return
                '<span class="badge text-bg-secondary">Inativo</span>';
        }

        if (
            (
                $task['last_status']
                ?? null
            ) === 'erro'
        ) {
            return
                '<span class="badge text-bg-danger">Erro</span>';
        }

        return
            '<span class="badge text-bg-success">Ativo</span>';
    };
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Backup v1.1.12
        </div>

        <h1 class="h3 mb-1">
            Backup e Integridade
        </h1>

        <p class="text-secondary mb-0">
            Agendamento, retenção, download, alerta de antiguidade e verificação automática dos backups.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/ferramentas/backups.php')) ?>"
        >
            <i class="bi bi-database me-1"></i>
            Todos os backups
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/ferramentas/backup-teste.php')) ?>"
        >
            <i class="bi bi-shield-check me-1"></i>
            Teste de restauração
        </a>

        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
        >
            <i class="bi bi-clock-history me-1"></i>
            Agendador
        </a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (empty($db['exists'])): ?>
    <div class="alert alert-danger">
        <strong>Nenhum backup do banco encontrado.</strong>
        O backup automático deve ser executado antes da verificação de integridade.
    </div>
<?php elseif (!empty($db['stale'])): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>

        <div>
            <strong>Backup antigo.</strong>
            O backup mais recente do banco tem
            <?= (int)($db['age_hours'] ?? 0) ?>
            hora(s). O limite configurado é
            <?= (int)$staleHours ?>
            hora(s).
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="bi bi-check-circle-fill mt-1"></i>

        <div>
            O backup mais recente do banco está dentro da janela de
            <?= (int)$staleHours ?>
            hora(s).
        </div>
    </div>
<?php endif; ?>

<?php if (is_array($last) && empty($last['ok'])): ?>
    <div class="alert alert-danger">
        <strong>A última verificação de integridade encontrou problema(s).</strong>

        <?php if (!empty($last['errors'])): ?>
            <ul class="mb-0 mt-2">
                <?php foreach ((array)$last['errors'] as $message): ?>
                    <li><?= e((string)$message) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Último banco
                </div>

                <div class="h5 mb-1 text-truncate" title="<?= e((string)($db['name'] ?? '')) ?>">
                    <?= !empty($db['exists'])
                        ? e((string)$db['name'])
                        : 'Não encontrado' ?>
                </div>

                <?php if (!empty($db['exists'])): ?>
                    <div class="small text-secondary">
                        <?= e(formatBytes((int)($db['size'] ?? 0))) ?>
                        ·
                        <?= (int)($db['age_hours'] ?? 0) ?>
                        h
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Último completo
                </div>

                <div class="h5 mb-1 text-truncate" title="<?= e((string)($full['name'] ?? '')) ?>">
                    <?= !empty($full['exists'])
                        ? e((string)$full['name'])
                        : 'Não encontrado' ?>
                </div>

                <?php if (!empty($full['exists'])): ?>
                    <div class="small text-secondary">
                        <?= e(formatBytes((int)($full['size'] ?? 0))) ?>
                        ·
                        <?= (int)($full['age_hours'] ?? 0) ?>
                        h
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Última integridade
                </div>

                <?php if (is_array($last)): ?>
                    <div class="h5 mb-1">
                        <span class="badge <?= !empty($last['ok'])
                            ? 'text-bg-success'
                            : 'text-bg-danger' ?>">
                            <?= !empty($last['ok'])
                                ? 'Aprovada'
                                : 'Falhou' ?>
                        </span>
                    </div>

                    <div class="small text-secondary">
                        <?= !empty($last['finished_at'])
                            ? e(formatDateBr((string)$last['finished_at']))
                            : 'sem data' ?>
                    </div>
                <?php else: ?>
                    <div class="h5 mb-1">
                        Ainda não executada
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Integridade automática
                </div>

                <div class="h5 mb-1">
                    <?= $taskBadge($taskIntegrity) ?>
                </div>

                <div class="small text-secondary">
                    <?php if (!empty($taskIntegrity['interval'])): ?>
                        a cada
                        <?= (int)$taskIntegrity['interval'] ?>
                        min
                    <?php else: ?>
                        tarefa ainda não registrada
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold py-3">
                Agendamento automático
            </div>

            <div class="card-body p-4">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input
                        type="hidden"
                        name="acao"
                        value="agendamento"
                    >

                    <div class="row g-4">
                        <div class="col-12">
                            <div class="border rounded-3 p-3">
                                <div class="form-check form-switch">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="backupDbActive"
                                        name="backup_db_active"
                                        <?= !empty($taskDb['active']) ? 'checked' : '' ?>
                                    >

                                    <label
                                        class="form-check-label fw-semibold"
                                        for="backupDbActive"
                                    >
                                        Backup automático do banco
                                    </label>
                                </div>

                                <div class="row g-2 mt-2">
                                    <div class="col-md-5">
                                        <label class="form-label small">
                                            Intervalo
                                        </label>

                                        <div class="input-group">
                                            <input
                                                class="form-control"
                                                type="number"
                                                min="60"
                                                max="10080"
                                                name="backup_db_interval"
                                                value="<?= (int)($taskDb['interval'] ?? 1440) ?>"
                                            >

                                            <span class="input-group-text">
                                                min
                                            </span>
                                        </div>
                                    </div>

                                    <div class="col-md-7 d-flex align-items-end">
                                        <div class="small text-secondary pb-2">
                                            Padrão: 1440 min = 1 vez por dia.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded-3 p-3">
                                <div class="form-check form-switch">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="backupFullActive"
                                        name="backup_full_active"
                                        <?= !empty($taskFull['active']) ? 'checked' : '' ?>
                                        <?= empty($status['zip_supported']) ? 'disabled' : '' ?>
                                    >

                                    <label
                                        class="form-check-label fw-semibold"
                                        for="backupFullActive"
                                    >
                                        Backup completo automático
                                    </label>
                                </div>

                                <div class="row g-2 mt-2">
                                    <div class="col-md-5">
                                        <label class="form-label small">
                                            Intervalo
                                        </label>

                                        <div class="input-group">
                                            <input
                                                class="form-control"
                                                type="number"
                                                min="60"
                                                max="10080"
                                                name="backup_full_interval"
                                                value="<?= (int)($taskFull['interval'] ?? 10080) ?>"
                                                <?= empty($status['zip_supported']) ? 'disabled' : '' ?>
                                            >

                                            <span class="input-group-text">
                                                min
                                            </span>
                                        </div>
                                    </div>

                                    <div class="col-md-7 d-flex align-items-end">
                                        <div class="small text-secondary pb-2">
                                            Padrão: 10080 min = 1 vez por semana.
                                            <?php if (empty($status['zip_supported'])): ?>
                                                ZipArchive não está disponível.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded-3 p-3">
                                <div class="form-check form-switch">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="backupIntegrityActive"
                                        name="backup_integrity_active"
                                        <?= !empty($taskIntegrity['active']) ? 'checked' : '' ?>
                                    >

                                    <label
                                        class="form-check-label fw-semibold"
                                        for="backupIntegrityActive"
                                    >
                                        Teste automático de integridade
                                    </label>
                                </div>

                                <div class="row g-2 mt-2">
                                    <div class="col-md-5">
                                        <label class="form-label small">
                                            Intervalo
                                        </label>

                                        <div class="input-group">
                                            <input
                                                class="form-control"
                                                type="number"
                                                min="60"
                                                max="10080"
                                                name="backup_integrity_interval"
                                                value="<?= (int)($taskIntegrity['interval'] ?? 1440) ?>"
                                            >

                                            <span class="input-group-text">
                                                min
                                            </span>
                                        </div>
                                    </div>

                                    <div class="col-md-7 d-flex align-items-end">
                                        <div class="small text-secondary pb-2">
                                            Valida SHA-256, SQL e manifesto/arquivos do ZIP sem restaurar o Portal.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-primary mt-4">
                        Salvar agendamento
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold py-3">
                Retenção e alerta
            </div>

            <div class="card-body p-4">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input
                        type="hidden"
                        name="acao"
                        value="retencao"
                    >

                    <div class="mb-3">
                        <label class="form-label">
                            Manter backups do banco
                        </label>

                        <div class="input-group">
                            <input
                                class="form-control"
                                type="number"
                                min="1"
                                max="100"
                                name="backup_retention_count"
                                value="<?= (int)$dbRetention ?>"
                                required
                            >

                            <span class="input-group-text">
                                arquivos
                            </span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Manter backups completos
                        </label>

                        <div class="input-group">
                            <input
                                class="form-control"
                                type="number"
                                min="1"
                                max="50"
                                name="backup_full_retention_count"
                                value="<?= (int)$fullRetention ?>"
                                required
                            >

                            <span class="input-group-text">
                                arquivos
                            </span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Avisar quando o backup tiver mais de
                        </label>

                        <div class="input-group">
                            <input
                                class="form-control"
                                type="number"
                                min="12"
                                max="720"
                                name="backup_stale_hours"
                                value="<?= (int)$staleHours ?>"
                                required
                            >

                            <span class="input-group-text">
                                horas
                            </span>
                        </div>

                        <div class="form-text">
                            Padrão: 36 horas. O alerta usa o backup mais recente do banco.
                        </div>
                    </div>

                    <button class="btn btn-primary">
                        Salvar retenção
                    </button>
                </form>
            </div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body d-flex flex-wrap justify-content-between align-items-center gap-3 py-3">
        <div>
            <div class="fw-semibold">
                Verificação de integridade
            </div>

            <div class="small text-secondary">
                O teste não restaura o banco e não sobrescreve arquivos do Portal.
            </div>
        </div>

        <form
            method="post"
            onsubmit="return confirm('Executar a verificação de integridade agora?');"
        >
            <?= Csrf::field() ?>
            <input
                type="hidden"
                name="acao"
                value="integridade"
            >

            <button class="btn btn-success">
                <i class="bi bi-shield-check me-1"></i>
                Verificar agora
            </button>
        </form>
    </div>

    <?php if (is_array($last)): ?>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-secondary">
                        Resultado
                    </div>

                    <span class="badge <?= !empty($last['ok'])
                        ? 'text-bg-success'
                        : 'text-bg-danger' ?>">
                        <?= !empty($last['ok'])
                            ? 'Aprovado'
                            : 'Falhou' ?>
                    </span>
                </div>

                <div class="col-md-3">
                    <div class="small text-secondary">
                        Origem
                    </div>

                    <strong>
                        <?= e((string)($last['origin'] ?? '')) ?>
                    </strong>
                </div>

                <div class="col-md-3">
                    <div class="small text-secondary">
                        Finalizada
                    </div>

                    <strong>
                        <?= !empty($last['finished_at'])
                            ? e(formatDateBr((string)$last['finished_at']))
                            : '—' ?>
                    </strong>
                </div>

                <div class="col-md-3">
                    <div class="small text-secondary">
                        Duração
                    </div>

                    <strong>
                        <?= (int)($last['duration_ms'] ?? 0) ?>
                        ms
                    </strong>
                </div>
            </div>

            <?php if (!empty($last['warnings'])): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <ul class="mb-0">
                        <?php foreach ((array)$last['warnings'] as $message): ?>
                            <li><?= e((string)$message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card-body text-secondary">
            Nenhuma verificação registrada ainda.
        </div>
    <?php endif; ?>
</section>

<div class="row g-4">
    <div class="col-xl-6">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-body d-flex justify-content-between align-items-center py-3">
                <strong>Backups do banco</strong>

                <span class="badge text-bg-secondary">
                    <?= count((array)$status['database_backups']) ?>
                    exibido(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Arquivo</th>
                            <th>Tamanho</th>
                            <th class="text-end">Download</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($status['database_backups'])): ?>
                            <tr>
                                <td
                                    colspan="3"
                                    class="text-center text-secondary py-4"
                                >
                                    Nenhum backup do banco.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ((array)$status['database_backups'] as $item): ?>
                            <tr>
                                <td>
                                    <code class="small">
                                        <?= e((string)$item['name']) ?>
                                    </code>

                                    <div class="small text-secondary">
                                        <?= e(date('d/m/Y H:i:s', (int)$item['mtime'])) ?>
                                    </div>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(formatBytes((int)$item['size'])) ?>
                                </td>

                                <td class="text-end">
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(url(
                                            'admin/ferramentas/backup-download.php?tipo=db&arquivo='
                                            . rawurlencode((string)$item['name'])
                                        )) ?>"
                                    >
                                        <i class="bi bi-download me-1"></i>
                                        Baixar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-body d-flex justify-content-between align-items-center py-3">
                <strong>Backups completos</strong>

                <span class="badge text-bg-secondary">
                    <?= count((array)$status['full_backups']) ?>
                    exibido(s)
                </span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Arquivo</th>
                            <th>Tamanho</th>
                            <th class="text-end">Download</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($status['full_backups'])): ?>
                            <tr>
                                <td
                                    colspan="3"
                                    class="text-center text-secondary py-4"
                                >
                                    Nenhum backup completo.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ((array)$status['full_backups'] as $item): ?>
                            <tr>
                                <td>
                                    <code class="small">
                                        <?= e((string)$item['name']) ?>
                                    </code>

                                    <div class="small text-secondary">
                                        <?= e(date('d/m/Y H:i:s', (int)$item['mtime'])) ?>
                                    </div>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(formatBytes((int)$item['size'])) ?>
                                </td>

                                <td class="text-end">
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(url(
                                            'admin/ferramentas/backup-download.php?tipo=full&arquivo='
                                            . rawurlencode((string)$item['name'])
                                        )) ?>"
                                    >
                                        <i class="bi bi-download me-1"></i>
                                        Baixar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
