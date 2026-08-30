<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission(
    'midias.gerenciar'
);

$pdo =
    Database::connection();

MediaIntegrityReportService::ensureSchema(
    $pdo
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                        $_POST['action']
                        ?? 'verify'
                    )
                );

            $cleanup =
                $action === 'verify_cleanup';

            $result =
                MediaIntegrityReportService::run(
                    $pdo,
                    dirname(__DIR__, 2),
                    'manual',
                    $cleanup
                );

            logAction(
                $pdo,
                $cleanup
                    ? 'midia.monitoramento.verificar_limpar'
                    : 'midia.monitoramento.verificar',
                'midia_integridade_relatorios',
                (int)$result['id'],
                (string)$result['message']
            );

            Session::flash(
                $result['status'] === 'erro'
                    ? 'error'
                    : 'success',
                'Verificação concluída: '
                . (string)$result['message']
            );

            header(
                'Location: '
                . url(
                    'admin/midias/monitoramento.php'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$latest =
    MediaIntegrityReportService::latest(
        $pdo
    );

$history =
    MediaIntegrityReportService::history(
        $pdo,
        30
    );

$statusClass =
    static function (
        string $status
    ): string {
        return match ($status) {
            'ok' => 'success',
            'warning' => 'warning',
            'erro' => 'danger',
            default => 'secondary',
        };
    };

$statusLabel =
    static function (
        string $status
    ): string {
        return match ($status) {
            'ok' => 'OK',
            'warning' => 'Atenção',
            'erro' => 'Erro',
            default => $status,
        };
    };

$pageTitle =
    'Monitoramento da mídia';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Mídia
        </div>

        <h1 class="h3 mb-1">
            Monitoramento automático
        </h1>

        <p class="text-secondary mb-0">
            Histórico das verificações automáticas da integridade da Biblioteca de Mídia.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/midias/integridade.php')) ?>"
        >
            Diagnóstico detalhado
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/ferramentas/tarefas-agendadas.php')) ?>"
        >
            Tarefas agendadas
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$latest): ?>
    <div class="alert alert-info">
        Nenhuma verificação automática foi registrada ainda.
        Você pode executar a primeira agora.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Último status
                </div>

                <?php if ($latest): ?>
                    <div class="mt-2">
                        <span class="badge fs-6 text-bg-<?= e($statusClass((string)$latest['status'])) ?>">
                            <?= e($statusLabel((string)$latest['status'])) ?>
                        </span>
                    </div>

                    <div class="small text-secondary mt-2">
                        <?= e(formatDateBr((string)$latest['created_at'])) ?>
                    </div>
                <?php else: ?>
                    <div class="display-6 fw-semibold">
                        —
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Originais ausentes
                </div>

                <div class="display-6 fw-semibold <?= $latest && (int)$latest['originais_ausentes'] > 0 ? 'text-danger' : '' ?>">
                    <?= $latest
                        ? (int)$latest['originais_ausentes']
                        : '—' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Divergências
                </div>

                <div class="display-6 fw-semibold <?= $latest && (int)$latest['tamanhos_divergentes'] > 0 ? 'text-warning' : '' ?>">
                    <?= $latest
                        ? (int)$latest['tamanhos_divergentes']
                        : '—' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Arquivos sem registro
                </div>

                <div class="display-6 fw-semibold <?= $latest && (int)$latest['arquivos_orfaos'] > 0 ? 'text-warning' : '' ?>">
                    <?= $latest
                        ? (int)$latest['arquivos_orfaos']
                        : '—' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Executar agora
                </div>

                <div class="small text-secondary">
                    As rotinas automáticas também aparecem em Ferramentas → Tarefas Agendadas.
                </div>
            </div>

            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row gap-3">
                    <form method="post">
                        <?= Csrf::field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="verify"
                        >

                        <button class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>
                            Verificar integridade
                        </button>
                    </form>

                    <form
                        method="post"
                        onsubmit="return confirm('Executar a limpeza segura dos registros/derivados órfãos e depois verificar a integridade?');"
                    >
                        <?= Csrf::field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="verify_cleanup"
                        >

                        <button class="btn btn-outline-warning">
                            <i class="bi bi-broom me-1"></i>
                            Limpeza segura + verificar
                        </button>
                    </form>
                </div>

                <div class="small text-secondary mt-3">
                    A limpeza automática continua conservadora: não apaga JPEG, PNG,
                    PDF ou outros arquivos originais órfãos.
                </div>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Rotinas automáticas
                </div>
            </div>

            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-7">
                        Verificar integridade
                    </dt>

                    <dd class="col-5 text-end">
                        diária
                    </dd>

                    <dt class="col-7">
                        Limpeza segura
                    </dt>

                    <dd class="col-5 text-end">
                        semanal
                    </dd>

                    <dt class="col-7">
                        Histórico
                    </dt>

                    <dd class="col-5 text-end">
                        120 execuções
                    </dd>
                </dl>
            </div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="fw-semibold">
            Histórico recente
        </div>

        <div class="small text-secondary">
            Últimas 30 verificações.
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Origem</th>
                    <th>Status</th>
                    <th>Analisados</th>
                    <th>Problemas</th>
                    <th>Limpeza</th>
                    <th>Mensagem</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$history): ?>
                    <tr>
                        <td
                            colspan="7"
                            class="text-secondary py-4"
                        >
                            Nenhuma execução registrada.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($history as $report): ?>
                    <?php
                    $problems =
                        (int)$report['originais_ausentes']
                        + (int)$report['tamanhos_divergentes']
                        + (int)$report['variantes_ausentes']
                        + (int)$report['arquivos_orfaos']
                        + (int)$report['derivados_orfaos'];

                    $cleaned =
                        (int)$report['registros_variantes_removidos']
                        + (int)$report['derivados_removidos'];
                    ?>

                    <tr>
                        <td class="text-nowrap">
                            <?= e(formatDateBr((string)$report['created_at'])) ?>
                        </td>

                        <td>
                            <span class="badge text-bg-light border">
                                <?= e((string)$report['origem']) ?>
                            </span>
                        </td>

                        <td>
                            <span class="badge text-bg-<?= e($statusClass((string)$report['status'])) ?>">
                                <?= e($statusLabel((string)$report['status'])) ?>
                            </span>
                        </td>

                        <td>
                            <?= (int)$report['arquivos_analisados'] ?>

                            <?php if (!empty($report['scan_parcial'])): ?>
                                <span class="badge text-bg-warning ms-1">
                                    parcial
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $problems ?>
                        </td>

                        <td class="text-nowrap">
                            <?= $cleaned ?>

                            <?php if ((int)$report['bytes_liberados'] > 0): ?>
                                ·
                                <?= e(formatBytes((int)$report['bytes_liberados'])) ?>
                            <?php endif; ?>
                        </td>

                        <td
                            class="small"
                            style="min-width:280px"
                        >
                            <?= e((string)$report['mensagem']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
