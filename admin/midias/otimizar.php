<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('midias.gerenciar');

$pdo =
    Database::connection();

ImageOptimizationService::ensureSchema(
    $pdo
);

$cursor =
    max(
        0,
        (int)(
            $_GET['cursor']
            ?? 0
        )
    );

$result = null;
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
            $cursor =
                max(
                    0,
                    (int)(
                        $_POST['cursor']
                        ?? 0
                    )
                );

            $force =
                isset(
                    $_POST['force']
                );

            $result =
                ImageOptimizationService::processBatch(
                    $pdo,
                    $cursor,
                    20,
                    $force
                );

            $cursor =
                (int)$result['last_id'];

            logAction(
                $pdo,
                'midia.otimizar_lote',
                'midias',
                null,
                'Processadas: '
                . (int)$result['processed']
                . '; ignoradas: '
                . (int)$result['skipped']
                . '; erros: '
                . count(
                    (array)$result['errors']
                )
            );
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$summary =
    ImageOptimizationService::summary(
        $pdo
    );

$settings =
    ImageOptimizationService::settings(
        $pdo
    );

$gdAvailable =
    ImageOptimizationService::gdAvailable();

$pageTitle =
    'Otimizar imagens';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Mídia
        </div>

        <h1 class="h3 mb-1">
            Otimizar imagens existentes
        </h1>

        <p class="text-secondary mb-0">
            Processa a biblioteca em lotes pequenos para evitar timeout.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/midias/index.php')) ?>"
        >
            Biblioteca
        </a>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/configuracoes/midia.php')) ?>"
        >
            Configurações
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$gdAvailable): ?>
    <div class="alert alert-warning">
        <strong>GD não está disponível.</strong>
        Habilite a extensão GD no PHP antes de processar a biblioteca.
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="alert <?= empty($result['errors']) ? 'alert-success' : 'alert-warning' ?>">
        <strong>Lote concluído.</strong>
        <?= (int)$result['processed'] ?>
        processada(s),
        <?= (int)$result['skipped'] ?>
        ignorada(s),
        <?= count((array)$result['errors']) ?>
        erro(s).

        <?php if (!empty($result['errors'])): ?>
            <ul class="mb-0 mt-2">
                <?php foreach (
                    array_slice(
                        (array)$result['errors'],
                        0,
                        8
                    )
                    as $itemError
                ): ?>
                    <li>
                        <?= e((string)$itemError) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Imagens
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['total_images'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    WebP
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['webp'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Miniaturas
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['thumbs'] ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Processamento em lotes
                </div>

                <div class="small text-secondary">
                    Cada execução trata no máximo 20 imagens.
                </div>
            </div>

            <div class="card-body p-4">
                <div class="mb-4">
                    <div class="small text-secondary">
                        Cursor atual
                    </div>

                    <code>
                        ID &gt;
                        <?= (int)$cursor ?>
                    </code>
                </div>

                <form method="post">
                    <?= Csrf::field() ?>

                    <input
                        type="hidden"
                        name="cursor"
                        value="<?= (int)$cursor ?>"
                    >

                    <div class="form-check mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="force"
                            id="forceOptimize"
                        >

                        <label
                            class="form-check-label"
                            for="forceOptimize"
                        >
                            Reprocessar usando as configurações atuais
                        </label>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            class="btn btn-primary"
                            <?= !$gdAvailable ? 'disabled' : '' ?>
                        >
                            <i class="bi bi-magic me-1"></i>
                            Processar próximo lote
                        </button>

                        <?php if ($cursor > 0): ?>
                            <a
                                class="btn btn-outline-secondary"
                                href="<?= e(url('admin/midias/otimizar.php')) ?>"
                            >
                                Reiniciar do primeiro arquivo
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (
                    $result
                    && !$result['has_more']
                ): ?>
                    <div class="alert alert-success mt-4 mb-0">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        O cursor chegou ao final da biblioteca.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Configuração atual
                </div>
            </div>

            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-7 text-secondary">
                        Otimização automática
                    </dt>

                    <dd class="col-5 text-end">
                        <?= $settings['enabled'] ? 'Ativa' : 'Desativada' ?>
                    </dd>

                    <dt class="col-7 text-secondary">
                        Máx. original
                    </dt>

                    <dd class="col-5 text-end">
                        <?= (int)$settings['max_width'] > 0
                            ? (int)$settings['max_width'] . ' px'
                            : 'Sem limite' ?>
                    </dd>

                    <dt class="col-7 text-secondary">
                        WebP
                    </dt>

                    <dd class="col-5 text-end">
                        <?= $settings['webp'] ? 'Sim' : 'Não' ?>
                    </dd>

                    <dt class="col-7 text-secondary">
                        Qualidade
                    </dt>

                    <dd class="col-5 text-end">
                        <?= (int)$settings['webp_quality'] ?>
                    </dd>

                    <dt class="col-7 text-secondary">
                        Miniatura
                    </dt>

                    <dd class="col-5 text-end">
                        <?= $settings['thumb']
                            ? (int)$settings['thumb_width'] . ' px'
                            : 'Não' ?>
                    </dd>
                </dl>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
