<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('midias.gerenciar');

$pdo =
    Database::connection();

$id =
    max(
        0,
        (int)(
            $_GET['id']
            ?? 0
        )
    );

$media =
    MediaService::find(
        $pdo,
        $id
    );

if (!$media) {
    http_response_code(404);
    exit('Mídia não encontrada.');
}

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
        if (
            (string)($_POST['action'] ?? '') === 'regenerate_variants'
        ) {
            try {
                $result =
                    ImageOptimizationService::process(
                        $pdo,
                        $id,
                        true
                    );

                logAction(
                    $pdo,
                    'midia.otimizar',
                    'midias',
                    $id,
                    (string)($media['nome_original'] ?? '')
                );

                Session::flash(
                    'success',
                    ($result['processed'] ?? false)
                        ? 'Imagem e variantes reprocessadas.'
                        : 'A imagem não precisou ou não pôde ser processada.'
                );

                header(
                    'Location: '
                    . url(
                        'admin/midias/editar.php?id='
                        . $id
                    )
                );

                exit;
            } catch (Throwable $e) {
                $error =
                    'Não foi possível otimizar: '
                    . $e->getMessage();
            }
        } else {
            $titulo =
                trim(
                (string)(
                    $_POST['titulo']
                    ?? ''
                )
            )
            ?: null;

        $alt =
            trim(
                (string)(
                    $_POST['alt_text']
                    ?? ''
                )
            )
            ?: null;

        try {
            $stmt =
                $pdo->prepare(
                    "UPDATE midias
                     SET
                        titulo=:titulo,
                        alt_text=:alt_text
                     WHERE id=:id"
                );

            $stmt->execute([
                'titulo' => $titulo,
                'alt_text' => $alt,
                'id' => $id,
            ]);

            logAction(
                $pdo,
                'midia.editar',
                'midias',
                $id,
                (string)(
                    $media['nome_original']
                    ?? ''
                )
            );

            Session::flash(
                'success',
                'Dados da mídia atualizados.'
            );

            header(
                'Location: '
                . url(
                    'admin/midias/editar.php?id='
                    . $id
                )
            );

            exit;
        } catch (Throwable $e) {
            $error =
                'Não foi possível salvar: '
                . $e->getMessage();
        }
        }
    }
}

$media =
    MediaService::find(
        $pdo,
        $id
    );

$usage = [
    'total' => 0,
    'groups' => [],
];

try {
    $usage =
        MediaUsageService::usage(
            $pdo,
            $id,
            20
        );
} catch (Throwable $ignored) {
}

$variants = [];

try {
    $variants =
        ImageOptimizationService::variants(
            $pdo,
            $id
        );
} catch (Throwable $ignored) {
}

$isImage =
    MediaService::isImage(
        $media
    );

$fileUrl =
    mediaUrl(
        (string)$media['caminho']
    );

$absolutePath =
    dirname(
        __DIR__,
        2
    )
    . '/'
    . ltrim(
        (string)$media['caminho'],
        '/'
    );

$fileExists =
    is_file(
        $absolutePath
    );

$physicalSize =
    $fileExists
        ? @filesize($absolutePath)
        : false;

$storedSize =
    (int)(
        $media['tamanho']
        ?? 0
    );

$sizeMatches =
    $fileExists
    && is_int($physicalSize)
        ? $physicalSize === $storedSize
        : null;

$pageTitle =
    'Detalhes da mídia';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Mídia
        </div>

        <h1 class="h3 mb-1">
            Detalhes da mídia
        </h1>

        <p class="text-secondary mb-0">
            Metadados, integridade do arquivo e conteúdos que utilizam esta mídia.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/midias/index.php')) ?>"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Biblioteca
        </a>

        <a
            class="btn btn-outline-primary"
            href="<?= e($fileUrl) ?>"
            target="_blank"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            Abrir arquivo
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$fileExists): ?>
    <div class="alert alert-danger d-flex align-items-start gap-3">
        <i class="bi bi-exclamation-octagon-fill fs-4"></i>

        <div>
            <strong>Arquivo físico não encontrado.</strong>
            O registro existe no banco, mas o arquivo
            <code><?= e((string)$media['caminho']) ?></code>
            não foi localizado no servidor.
        </div>
    </div>
<?php elseif ($sizeMatches === false): ?>
    <div class="alert alert-warning d-flex align-items-start gap-3">
        <i class="bi bi-exclamation-triangle-fill fs-4"></i>

        <div>
            <strong>O tamanho físico é diferente do registrado no banco.</strong>
            Banco:
            <?= e(formatBytes($storedSize)) ?>.
            Arquivo:
            <?= e(formatBytes((int)$physicalSize)) ?>.
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-5">
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body text-center p-4">
                <?php if ($isImage): ?>
                    <img
                        class="img-fluid rounded media-preview"
                        src="<?= e($fileUrl) ?>"
                        alt="<?= e(
                            (string)(
                                $media['alt_text']
                                ?: $media['titulo']
                                ?: ''
                            )
                        ) ?>"
                    >
                <?php else: ?>
                    <div class="media-file-placeholder rounded">
                        <strong>
                            .<?= e(
                                strtoupper(
                                    (string)$media['extensao']
                                )
                            ) ?>
                        </strong>
                    </div>
                <?php endif; ?>

                <div class="text-start mt-4">
                    <dl class="row small mb-0">
                        <dt class="col-sm-4 text-secondary">
                            Arquivo
                        </dt>

                        <dd class="col-sm-8 text-break">
                            <?= e((string)$media['nome_original']) ?>
                        </dd>

                        <dt class="col-sm-4 text-secondary">
                            Tipo
                        </dt>

                        <dd class="col-sm-8">
                            <?= e((string)$media['mime_type']) ?>
                        </dd>

                        <dt class="col-sm-4 text-secondary">
                            Extensão
                        </dt>

                        <dd class="col-sm-8">
                            <?= e(
                                strtoupper(
                                    (string)$media['extensao']
                                )
                            ) ?>
                        </dd>

                        <dt class="col-sm-4 text-secondary">
                            Tamanho
                        </dt>

                        <dd class="col-sm-8">
                            <?= e(
                                formatBytes(
                                    $storedSize
                                )
                            ) ?>
                        </dd>

                        <?php if (
                            !empty($media['largura'])
                            && !empty($media['altura'])
                        ): ?>
                            <dt class="col-sm-4 text-secondary">
                                Dimensões
                            </dt>

                            <dd class="col-sm-8">
                                <?= (int)$media['largura'] ?>
                                ×
                                <?= (int)$media['altura'] ?>
                                px
                            </dd>
                        <?php endif; ?>

                        <dt class="col-sm-4 text-secondary">
                            Arquivo físico
                        </dt>

                        <dd class="col-sm-8">
                            <?php if ($fileExists): ?>
                                <span class="badge text-bg-success">
                                    encontrado
                                </span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">
                                    ausente
                                </span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4 text-secondary">
                            Em uso
                        </dt>

                        <dd class="col-sm-8">
                            <?php if ((int)$usage['total'] > 0): ?>
                                <span class="badge text-bg-warning">
                                    <?= (int)$usage['total'] ?>
                                    referência(s)
                                </span>
                            <?php else: ?>
                                <span class="badge text-bg-success">
                                    não
                                </span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    URL do arquivo
                </div>
            </div>

            <div class="card-body">
                <div class="input-group">
                    <input
                        id="mediaUrl"
                        class="form-control"
                        readonly
                        value="<?= e($fileUrl) ?>"
                    >

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        id="copyMediaUrl"
                    >
                        Copiar
                    </button>
                </div>
            </div>
        </section>
    </div>

    <div class="col-xl-7">
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Informações da mídia
                </div>

                <div class="small text-secondary">
                    Título e texto alternativo podem ser atualizados sem alterar o arquivo.
                </div>
            </div>

            <div class="card-body p-4">
                <form method="post">
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label">
                            Título
                        </label>

                        <input
                            class="form-control"
                            name="titulo"
                            maxlength="180"
                            value="<?= e((string)($media['titulo'] ?? '')) ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Texto alternativo
                        </label>

                        <input
                            class="form-control"
                            name="alt_text"
                            maxlength="255"
                            value="<?= e((string)($media['alt_text'] ?? '')) ?>"
                        >

                        <div class="form-text">
                            Para imagens, descreva brevemente o conteúdo visual para acessibilidade e SEO.
                        </div>
                    </div>

                    <button class="btn btn-primary">
                        Salvar alterações
                    </button>
                </form>
            </div>
        </section>

        <?php if ($isImage): ?>
            <section class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="fw-semibold">
                            Otimização e variantes
                        </div>

                        <div class="small text-secondary">
                            WebP e miniaturas geradas para esta imagem.
                        </div>
                    </div>

                    <form method="post">
                        <?= Csrf::field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="regenerate_variants"
                        >

                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-magic me-1"></i>
                            Reprocessar
                        </button>
                    </form>
                </div>

                <div class="card-body">
                    <?php if (!$variants): ?>
                        <div class="text-secondary">
                            Nenhuma variante gerada ainda.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Dimensões</th>
                                        <th>Tamanho</th>
                                        <th>Arquivo</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($variants as $variant): ?>
                                        <tr>
                                            <td>
                                                <span class="badge text-bg-secondary">
                                                    <?= e((string)$variant['tipo']) ?>
                                                </span>
                                            </td>

                                            <td class="text-nowrap">
                                                <?= (int)$variant['largura'] ?>
                                                ×
                                                <?= (int)$variant['altura'] ?>
                                            </td>

                                            <td class="text-nowrap">
                                                <?= e(
                                                    formatBytes(
                                                        (int)$variant['tamanho']
                                                    )
                                                ) ?>
                                            </td>

                                            <td>
                                                <?php if (!empty($variant['exists'])): ?>
                                                    <a
                                                        href="<?= e((string)$variant['url']) ?>"
                                                        target="_blank"
                                                    >
                                                        Abrir
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-danger">
                                                        ausente
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section
            class="card border-0 shadow-sm mb-4"
            id="onde-usada"
        >
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">
                        Onde esta mídia é usada
                    </div>

                    <div class="small text-secondary">
                        Referências estruturadas encontradas no banco.
                    </div>
                </div>

                <?php if ((int)$usage['total'] > 0): ?>
                    <span class="badge text-bg-warning">
                        <?= (int)$usage['total'] ?>
                    </span>
                <?php else: ?>
                    <span class="badge text-bg-success">
                        Livre
                    </span>
                <?php endif; ?>
            </div>

            <?php if ((int)$usage['total'] <= 0): ?>
                <div class="card-body">
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        Nenhuma referência estruturada foi encontrada.
                        Esta mídia pode ser excluída com segurança pelo gerenciador.
                    </div>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($usage['groups'] as $group): ?>
                        <div class="list-group-item py-3">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                <div class="fw-semibold">
                                    <?= e((string)$group['label']) ?>
                                </div>

                                <span class="badge text-bg-secondary">
                                    <?= (int)$group['count'] ?>
                                </span>
                            </div>

                            <?php if (!empty($group['items'])): ?>
                                <div class="d-grid gap-2">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <a
                                            class="border rounded px-3 py-2 text-decoration-none text-reset d-flex justify-content-between align-items-center gap-3"
                                            href="<?= e((string)$item['url']) ?>"
                                        >
                                            <span class="min-w-0">
                                                <span class="d-block fw-semibold text-truncate">
                                                    <?= e((string)$item['title']) ?>
                                                </span>

                                                <?php if (!empty($item['status'])): ?>
                                                    <span class="small text-secondary">
                                                        Status:
                                                        <?= e((string)$item['status']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>

                                            <i class="bi bi-chevron-right text-secondary"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-secondary">
                                    Referência protegida identificada no banco.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card border-danger-subtle shadow-sm">
            <div class="card-header bg-danger-subtle py-3">
                <div class="fw-semibold text-danger-emphasis">
                    Excluir mídia
                </div>
            </div>

            <div class="card-body">
                <?php if ((int)$usage['total'] > 0): ?>
                    <p class="mb-2">
                        Esta mídia <strong>não pode ser excluída agora</strong>,
                        pois ainda está vinculada a conteúdo do Portal.
                    </p>

                    <p class="small text-secondary mb-0">
                        Abra os itens listados acima, remova ou substitua a mídia e depois volte aqui.
                    </p>
                <?php else: ?>
                    <p class="text-secondary">
                        A exclusão remove o registro e, conforme a configuração da Biblioteca,
                        também pode apagar o arquivo físico do servidor.
                    </p>

                    <form
                        method="post"
                        action="<?= e(url('admin/midias/index.php')) ?>"
                        onsubmit="return confirm('Excluir permanentemente esta mídia?');"
                    >
                        <?= Csrf::field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="delete"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int)$id ?>"
                        >

                        <button class="btn btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>
                            Excluir permanentemente
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const button =
        document.getElementById('copyMediaUrl');

    const input =
        document.getElementById('mediaUrl');

    if (!button || !input) {
        return;
    }

    button.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(
                input.value
            );

            const original =
                button.textContent;

            button.textContent =
                'Copiado';

            window.setTimeout(
                function () {
                    button.textContent =
                        original;
                },
                1200
            );
        } catch (error) {
            input.select();
            document.execCommand('copy');
        }
    });
});
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
