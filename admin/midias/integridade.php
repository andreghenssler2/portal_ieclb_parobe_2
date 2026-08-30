<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission(
    'midias.gerenciar'
);

$pdo =
    Database::connection();

$service =
    new MediaIntegrityService(
        $pdo,
        dirname(__DIR__, 2)
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
                        ?? ''
                    )
                );

            if (
                $action
                === 'clean_missing_variant_records'
            ) {
                $removed =
                    $service
                        ->removeMissingVariantRecords();

                logAction(
                    $pdo,
                    'midia.integridade.limpar_variantes_ausentes',
                    'midias',
                    null,
                    'Registros removidos: '
                    . $removed
                );

                Session::flash(
                    'success',
                    $removed
                    . ' registro(s) de variante sem arquivo físico removido(s).'
                );
            } elseif (
                $action
                === 'clean_orphan_generated'
            ) {
                $result =
                    $service
                        ->removeOrphanGeneratedFiles();

                logAction(
                    $pdo,
                    'midia.integridade.limpar_derivados_orfaos',
                    'midias',
                    null,
                    'Arquivos: '
                    . (int)$result['removed']
                    . '; bytes: '
                    . (int)$result['bytes']
                    . '; falhas: '
                    . (int)$result['failed']
                );

                Session::flash(
                    'success',
                    (int)$result['removed']
                    . ' arquivo(s) derivado(s) órfão(s) removido(s), liberando '
                    . formatBytes(
                        (int)$result['bytes']
                    )
                    . '.'
                );
            }

            header(
                'Location: '
                . url(
                    'admin/midias/integridade.php?scan=1'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$summary =
    $service->databaseSummary();

$scan = null;

if (
    isset($_GET['scan'])
    && (string)$_GET['scan'] === '1'
) {
    try {
        $scan =
            $service->scan(
                10000
            );
    } catch (Throwable $e) {
        $error =
            $e->getMessage();
    }
}

$pageTitle =
    'Integridade da mídia';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Mídia
        </div>

        <h1 class="h3 mb-1">
            Integridade do armazenamento
        </h1>

        <p class="text-secondary mb-0">
            Compare registros do banco com os arquivos físicos da pasta de uploads.
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
            class="btn btn-outline-primary"
            href="<?= e(url('admin/midias/otimizar.php')) ?>"
        >
            <i class="bi bi-magic me-1"></i>
            Otimizar imagens
        </a>

        <a
            class="btn btn-primary"
            href="<?= e(url('admin/midias/integridade.php?scan=1')) ?>"
        >
            <i class="bi bi-search me-1"></i>
            Escanear agora
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Registros de mídia
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['media'] ?>
                </div>

                <div class="small text-secondary">
                    <?= (int)$summary['images'] ?>
                    imagens ·
                    <?= (int)$summary['documents'] ?>
                    documentos
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Variantes
                </div>

                <div class="display-6 fw-semibold">
                    <?= (int)$summary['variants'] ?>
                </div>

                <div class="small text-secondary">
                    <?= (int)$summary['variant_webp'] ?>
                    WebP ·
                    <?= (int)$summary['variant_thumb'] ?>
                    miniaturas
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Arquivos ausentes
                </div>

                <div class="display-6 fw-semibold <?= $scan && (int)$scan['totals']['missing_originals'] > 0 ? 'text-danger' : '' ?>">
                    <?= $scan
                        ? (int)$scan['totals']['missing_originals']
                        : '—' ?>
                </div>

                <div class="small text-secondary">
                    originais registrados no banco
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Arquivos órfãos
                </div>

                <div class="display-6 fw-semibold <?= $scan && ((int)$scan['totals']['orphan_files'] + (int)$scan['totals']['orphan_generated']) > 0 ? 'text-warning' : '' ?>">
                    <?= $scan
                        ? (
                            (int)$scan['totals']['orphan_files']
                            + (int)$scan['totals']['orphan_generated']
                        )
                        : '—' ?>
                </div>

                <div class="small text-secondary">
                    arquivos físicos sem registro
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$scan): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-5 text-center">
            <i class="bi bi-hdd-stack fs-1 text-secondary d-block mb-3"></i>

            <h2 class="h5">
                Diagnóstico sob demanda
            </h2>

            <p class="text-secondary mx-auto" style="max-width:650px">
                O escaneamento percorre a pasta
                <code>uploads/</code>
                e compara cada arquivo com a Biblioteca de Mídia.
                Ele só é executado quando solicitado para não deixar o painel mais lento.
            </p>

            <a
                class="btn btn-primary"
                href="<?= e(url('admin/midias/integridade.php?scan=1')) ?>"
            >
                Executar diagnóstico
            </a>
        </div>
    </div>
<?php else: ?>

    <?php if ($scan['scan_truncated']): ?>
        <div class="alert alert-warning">
            <strong>Escaneamento parcial.</strong>
            O limite de
            <?= (int)$scan['scan_limit'] ?>
            arquivos foi atingido.
        </div>
    <?php else: ?>
        <div class="alert alert-light border">
            <i class="bi bi-check2-circle me-2"></i>
            <?= (int)$scan['scanned_files'] ?>
            arquivo(s) físico(s) analisado(s) em
            <code>uploads/</code>.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Originais ausentes
                        </div>

                        <div class="small text-secondary">
                            Existe registro no banco, mas o arquivo físico não foi encontrado.
                        </div>
                    </div>

                    <span class="badge <?= (int)$scan['totals']['missing_originals'] > 0 ? 'text-bg-danger' : 'text-bg-success' ?>">
                        <?= (int)$scan['totals']['missing_originals'] ?>
                    </span>
                </div>

                <?php if (!$scan['missing_originals']): ?>
                    <div class="card-body">
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Nenhum original ausente.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach (
                            array_slice(
                                $scan['missing_originals'],
                                0,
                                50
                            )
                            as $item
                        ): ?>
                            <a
                                class="list-group-item list-group-item-action py-3"
                                href="<?= e((string)$item['url']) ?>"
                            >
                                <div class="fw-semibold">
                                    <?= e((string)$item['name']) ?>
                                </div>

                                <div class="small text-danger text-break">
                                    <?= e((string)$item['path']) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Tamanho divergente
                        </div>

                        <div class="small text-secondary">
                            Arquivo existe, mas o tamanho físico difere do banco.
                        </div>
                    </div>

                    <span class="badge <?= (int)$scan['totals']['size_mismatches'] > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                        <?= (int)$scan['totals']['size_mismatches'] ?>
                    </span>
                </div>

                <?php if (!$scan['size_mismatches']): ?>
                    <div class="card-body">
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Nenhuma divergência encontrada.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach (
                            array_slice(
                                $scan['size_mismatches'],
                                0,
                                50
                            )
                            as $item
                        ): ?>
                            <a
                                class="list-group-item list-group-item-action py-3"
                                href="<?= e((string)$item['url']) ?>"
                            >
                                <div class="fw-semibold">
                                    <?= e((string)$item['name']) ?>
                                </div>

                                <div class="small text-secondary">
                                    Banco:
                                    <?= e(formatBytes((int)$item['stored_size'])) ?>
                                    · físico:
                                    <?= e(formatBytes((int)$item['actual_size'])) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Variantes ausentes
                        </div>

                        <div class="small text-secondary">
                            Registro WebP/thumbnail existe, mas o arquivo não.
                        </div>
                    </div>

                    <span class="badge <?= (int)$scan['totals']['missing_variants'] > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                        <?= (int)$scan['totals']['missing_variants'] ?>
                    </span>
                </div>

                <div class="card-body">
                    <?php if (!$scan['missing_variants']): ?>
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Nenhuma variante ausente.
                        </div>
                    <?php else: ?>
                        <p class="text-secondary">
                            É seguro remover somente esses registros.
                            As variantes podem ser geradas novamente em
                            <strong>Otimizar imagens</strong>.
                        </p>

                        <form
                            method="post"
                            onsubmit="return confirm('Remover os registros de variantes cujo arquivo físico não existe?');"
                        >
                            <?= Csrf::field() ?>

                            <input
                                type="hidden"
                                name="action"
                                value="clean_missing_variant_records"
                            >

                            <button class="btn btn-outline-warning">
                                Remover
                                <?= (int)$scan['totals']['missing_variants'] ?>
                                registro(s) inválido(s)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Derivados órfãos
                        </div>

                        <div class="small text-secondary">
                            Miniaturas/WebP gerados sem registro correspondente.
                        </div>
                    </div>

                    <span class="badge <?= (int)$scan['totals']['orphan_generated'] > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                        <?= (int)$scan['totals']['orphan_generated'] ?>
                    </span>
                </div>

                <div class="card-body">
                    <?php if (!$scan['orphan_generated']): ?>
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Nenhum arquivo derivado órfão.
                        </div>
                    <?php else: ?>
                        <p class="text-secondary">
                            Apenas arquivos terminados em
                            <code>.thumb.webp</code>
                            ou
                            <code>.optimized.webp</code>
                            são elegíveis para limpeza automática.
                            Arquivos criados há menos de 1 hora são preservados.
                        </p>

                        <form
                            method="post"
                            onsubmit="return confirm('Remover os arquivos derivados órfãos identificados?');"
                        >
                            <?= Csrf::field() ?>

                            <input
                                type="hidden"
                                name="action"
                                value="clean_orphan_generated"
                            >

                            <button class="btn btn-outline-warning">
                                Limpar derivados órfãos
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold">
                            Arquivos físicos sem registro
                        </div>

                        <div class="small text-secondary">
                            Possíveis uploads antigos/manuais não registrados na tabela de mídias.
                        </div>
                    </div>

                    <span class="badge <?= (int)$scan['totals']['orphan_files'] > 0 ? 'text-bg-secondary' : 'text-bg-success' ?>">
                        <?= (int)$scan['totals']['orphan_files'] ?>
                    </span>
                </div>

                <?php if (!$scan['orphan_files']): ?>
                    <div class="card-body">
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            Nenhum arquivo original órfão detectado.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body border-bottom">
                        <div class="alert alert-warning mb-0">
                            <strong>Esses arquivos não são apagados automaticamente.</strong>
                            Um arquivo pode estar referenciado diretamente em HTML antigo ou ter sido
                            enviado manualmente antes da Biblioteca de Mídia. Revise antes de qualquer
                            remoção manual.
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Caminho</th>
                                    <th>Tamanho</th>
                                    <th>Modificado</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    array_slice(
                                        $scan['orphan_files'],
                                        0,
                                        100
                                    )
                                    as $item
                                ): ?>
                                    <tr>
                                        <td class="text-break">
                                            <code>
                                                <?= e((string)$item['path']) ?>
                                            </code>
                                        </td>

                                        <td class="text-nowrap">
                                            <?= e(formatBytes((int)$item['size'])) ?>
                                        </td>

                                        <td class="text-nowrap">
                                            <?= e(formatDateBr((string)$item['modified_at'])) ?>
                                        </td>

                                        <td class="text-end">
                                            <a
                                                class="btn btn-sm btn-outline-secondary"
                                                href="<?= e((string)$item['url']) ?>"
                                                target="_blank"
                                            >
                                                Abrir
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../_footer.php'; ?>
