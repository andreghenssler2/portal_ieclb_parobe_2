<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$root =
    dirname(
        __DIR__,
        2
    );

if (!class_exists('AccessibilityAuditService')) {
    throw new RuntimeException(
        'AccessibilityAuditService indisponível.'
    );
}

$report =
    AccessibilityAuditService::report(
        $root
    );

$pageTitle =
    'Acessibilidade';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Acessibilidade
        </h1>

        <p class="text-secondary mb-0">
            Verificação estrutural de navegação por teclado, foco, atalhos para conteúdo e elementos de mídia.
        </p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Verificações</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$report['summary']['checks'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Aprovadas</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$report['summary']['passed'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Arquivos públicos analisados</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$report['summary']['scanned_files'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Erros estruturais</div>
                <div class="display-6 fw-semibold">
                    <?= count($report['errors']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($report['errors']): ?>
    <div class="alert alert-danger">
        <strong>Erros:</strong>
        <ul class="mb-0">
            <?php foreach ($report['errors'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($report['warnings']): ?>
    <div class="alert alert-warning">
        <strong>Itens para revisão manual:</strong>
        <ul class="mb-0">
            <?php foreach ($report['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Verificações estruturais
    </div>

    <div class="list-group list-group-flush">
        <?php foreach ($report['checks'] as $item): ?>
            <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">
                        <?= e((string)$item['label']) ?>
                    </div>

                    <div class="small text-secondary">
                        <?= e((string)$item['detail']) ?>
                    </div>
                </div>

                <span class="badge text-bg-<?= !empty($item['ok']) ? 'success' : 'danger' ?>">
                    <?= !empty($item['ok']) ? 'OK' : 'Revisar' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Checklist manual recomendado
    </div>

    <div class="card-body">
        <ul class="mb-0">
            <li>Navegar pelo Portal usando apenas Tab, Shift+Tab, Enter e Espaço.</li>
            <li>Confirmar que o primeiro Tab exibe “Ir para o conteúdo”.</li>
            <li>Verificar foco visível em links, botões, formulários e menu administrativo.</li>
            <li>Testar zoom de 200% sem perda de conteúdo essencial.</li>
            <li>Conferir textos alternativos das imagens editoriais.</li>
            <li>Conferir títulos em vídeos/iframes incorporados.</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
