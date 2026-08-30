<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'configuracoes.gerenciar'
);

$pdo =
    Database::connection();

$error = '';

$serverMb =
    max(
        1,
        (int)floor(
            (
                defined('UPLOAD_MAX_SIZE')
                    ? (int)UPLOAD_MAX_SIZE
                    : 10485760
            )
            / 1048576
        )
    );

$defaults = [
    'media_upload_max_mb' =>
        (string)$serverMb,

    'media_organize_year_month' => '1',
    'media_allow_documents' => '1',
    'media_delete_file_on_delete' => '1',

    'media_optimize_images' => '1',
    'media_image_max_width' => '1920',
    'media_generate_webp' => '1',
    'media_webp_quality' => '82',
    'media_generate_thumbnail' => '1',
    'media_thumbnail_width' => '480',
];

$s =
    array_merge(
        $defaults,
        siteConfigAll($pdo)
    );

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
            $s['media_upload_max_mb'] =
                (string)max(
                    1,
                    min(
                        $serverMb,
                        (int)(
                            $_POST['media_upload_max_mb']
                            ?? $serverMb
                        )
                    )
                );

            $s['media_image_max_width'] =
                (string)max(
                    0,
                    min(
                        6000,
                        (int)(
                            $_POST['media_image_max_width']
                            ?? 1920
                        )
                    )
                );

            $s['media_webp_quality'] =
                (string)max(
                    45,
                    min(
                        95,
                        (int)(
                            $_POST['media_webp_quality']
                            ?? 82
                        )
                    )
                );

            $s['media_thumbnail_width'] =
                (string)max(
                    160,
                    min(
                        1200,
                        (int)(
                            $_POST['media_thumbnail_width']
                            ?? 480
                        )
                    )
                );

            foreach (
                [
                    'media_organize_year_month',
                    'media_allow_documents',
                    'media_delete_file_on_delete',
                    'media_optimize_images',
                    'media_generate_webp',
                    'media_generate_thumbnail',
                ]
                as $key
            ) {
                $s[$key] =
                    isset($_POST[$key])
                        ? '1'
                        : '0';
            }

            $types = [
                'media_upload_max_mb' => 'numero',
                'media_organize_year_month' => 'booleano',
                'media_allow_documents' => 'booleano',
                'media_delete_file_on_delete' => 'booleano',
                'media_optimize_images' => 'booleano',
                'media_image_max_width' => 'numero',
                'media_generate_webp' => 'booleano',
                'media_webp_quality' => 'numero',
                'media_generate_thumbnail' => 'booleano',
                'media_thumbnail_width' => 'numero',
            ];

            foreach (
                $types
                as $key => $type
            ) {
                saveSiteConfig(
                    $pdo,
                    $key,
                    $s[$key],
                    $type
                );
            }

            logAction(
                $pdo,
                'configuracoes.midia',
                'configuracoes',
                null,
                'Upload e otimização de imagens'
            );

            Session::flash(
                'success',
                'Configurações de mídia atualizadas.'
            );

            header(
                'Location: '
                . url(
                    'admin/configuracoes/midia.php'
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$summary = [
    'total_images' => 0,
    'webp' => 0,
    'thumbs' => 0,
];

try {
    $summary =
        ImageOptimizationService::summary(
            $pdo
        );
} catch (Throwable $ignored) {
}

$gdAvailable =
    ImageOptimizationService::gdAvailable();

$pageTitle =
    'Configurações de mídia';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Configurações
        </div>

        <h1 class="h3 mb-1">
            Mídia
        </h1>

        <p class="text-secondary mb-0">
            Upload, armazenamento, otimização e miniaturas da Biblioteca de Mídia.
        </p>
    </div>

    <a
        class="btn btn-outline-primary"
        href="<?= e(url('admin/midias/otimizar.php')) ?>"
    >
        <i class="bi bi-magic me-1"></i>
        Otimizar biblioteca existente
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$gdAvailable): ?>
    <div class="alert alert-warning">
        <strong>Extensão GD não disponível.</strong>
        Uploads continuam funcionando, mas redimensionamento, WebP e miniaturas
        não poderão ser gerados até o GD ser habilitado no PHP.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">
                    Imagens na biblioteca
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
                    Variantes WebP
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

<form
    method="post"
    class="card border-0 shadow-sm"
>
    <div class="card-body p-4">
        <?= Csrf::field() ?>

        <h2 class="h5 mb-3">
            Upload e armazenamento
        </h2>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">
                    Tamanho máximo por arquivo
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="1"
                        max="<?= (int)$serverMb ?>"
                        name="media_upload_max_mb"
                        value="<?= e($s['media_upload_max_mb']) ?>"
                    >

                    <span class="input-group-text">
                        MB
                    </span>
                </div>

                <div class="form-text">
                    Limite máximo do servidor/config.php:
                    <?= (int)$serverMb ?>
                    MB.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_organize_year_month"
                        id="mediaOrg"
                        <?= $s['media_organize_year_month'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaOrg"
                    >
                        Organizar uploads em pastas por ano/mês
                    </label>
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_allow_documents"
                        id="mediaDocs"
                        <?= $s['media_allow_documents'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaDocs"
                    >
                        Permitir PDF, Word, Excel, PowerPoint e TXT
                    </label>
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_delete_file_on_delete"
                        id="mediaDelete"
                        <?= $s['media_delete_file_on_delete'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaDelete"
                    >
                        Apagar arquivo físico quando a mídia for excluída
                    </label>
                </div>
            </div>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-start gap-3 mt-4 mb-3">
            <div>
                <h2 class="h5 mb-1">
                    Otimização automática
                </h2>

                <div class="text-secondary small">
                    Aplicada a JPEG, PNG e WebP. GIF é preservado para não perder animações.
                </div>
            </div>

            <span class="badge <?= $gdAvailable ? 'text-bg-success' : 'text-bg-warning' ?>">
                GD
                <?= $gdAvailable ? 'disponível' : 'indisponível' ?>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_optimize_images"
                        id="mediaOptimize"
                        <?= $s['media_optimize_images'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaOptimize"
                    >
                        Otimizar imagens automaticamente após o upload
                    </label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Largura máxima do arquivo original
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="0"
                        max="6000"
                        step="10"
                        name="media_image_max_width"
                        value="<?= e($s['media_image_max_width']) ?>"
                    >

                    <span class="input-group-text">
                        px
                    </span>
                </div>

                <div class="form-text">
                    Use 0 para não redimensionar o original.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Qualidade WebP
                </label>

                <input
                    class="form-range"
                    type="range"
                    min="45"
                    max="95"
                    name="media_webp_quality"
                    value="<?= e($s['media_webp_quality']) ?>"
                    oninput="document.getElementById('webpQualityValue').textContent=this.value"
                >

                <div class="form-text">
                    Qualidade:
                    <strong id="webpQualityValue">
                        <?= e($s['media_webp_quality']) ?>
                    </strong>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="form-check form-switch mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_generate_webp"
                        id="mediaWebp"
                        <?= $s['media_generate_webp'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaWebp"
                    >
                        Gerar variante WebP otimizada
                    </label>
                </div>

                <div class="form-text">
                    JPEG/PNG recebem uma cópia WebP sem substituir o formato original.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="form-check form-switch mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="media_generate_thumbnail"
                        id="mediaThumb"
                        <?= $s['media_generate_thumbnail'] === '1' ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="mediaThumb"
                    >
                        Gerar miniatura WebP para o Admin
                    </label>
                </div>

                <div class="input-group mt-2">
                    <input
                        class="form-control"
                        type="number"
                        min="160"
                        max="1200"
                        name="media_thumbnail_width"
                        value="<?= e($s['media_thumbnail_width']) ?>"
                    >

                    <span class="input-group-text">
                        px
                    </span>
                </div>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    Salvar configurações de mídia
                </button>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_footer.php'; ?>
