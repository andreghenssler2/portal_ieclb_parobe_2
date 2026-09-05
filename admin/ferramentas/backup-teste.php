<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('backups.gerenciar');

$pdo =
    Database::connection();

$root =
    dirname(
        __DIR__,
        2
    );

if (!class_exists('BackupRestoreTestService')) {
    throw new RuntimeException(
        'BackupRestoreTestService indisponível.'
    );
}

$service =
    new BackupRestoreTestService(
        $pdo,
        $root
    );

$quick =
    $service->quickCheck();

$result = null;
$error = '';

$testDatabase = true;
$testFull =
    !empty(
        $quick['zip_supported']
    );
$includeUploads = false;
$includeThemes = true;

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
                (string)(
                    $_POST['acao']
                    ?? ''
                );

            if ($action !== 'executar') {
                throw new RuntimeException(
                    'Ação inválida.'
                );
            }

            $testDatabase =
                isset(
                    $_POST['testar_banco']
                );

            $testFull =
                isset(
                    $_POST['testar_completo']
                );

            $includeUploads =
                isset(
                    $_POST['incluir_uploads']
                );

            $includeThemes =
                isset(
                    $_POST['incluir_temas']
                );

            if (
                !$testDatabase
                && !$testFull
            ) {
                throw new RuntimeException(
                    'Selecione pelo menos um tipo de backup para testar.'
                );
            }

            if (
                $testFull
                && empty(
                    $quick['zip_supported']
                )
            ) {
                throw new RuntimeException(
                    'ZipArchive não está disponível para testar o backup completo.'
                );
            }

            $result =
                $service->run(
                    $testDatabase,
                    $testFull,
                    $includeUploads,
                    $includeThemes
                );

            try {
                logAction(
                    $pdo,
                    'backup.restaurabilidade.testar',
                    'backup',
                    null,
                    'Banco='
                    . ($testDatabase ? 'sim' : 'não')
                    . '; Completo='
                    . ($testFull ? 'sim' : 'não')
                    . '; Uploads='
                    . ($includeUploads ? 'sim' : 'não')
                    . '; Temas='
                    . ($includeThemes ? 'sim' : 'não')
                    . '; Resultado='
                    . (!empty($result['ok']) ? 'ok' : 'erro')
                );
            } catch (Throwable $ignored) {
            }
        } catch (Throwable $e) {
            $error =
                $e->getMessage();
        }
    }
}

$pageTitle =
    'Teste de Restaurabilidade';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Teste de Restaurabilidade
        </h1>

        <p class="text-secondary mb-0">
            Cria backups reais e verifica a integridade necessária para uma futura restauração, sem restaurar o Portal atual.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/ferramentas/backups.php')) ?>"
    >
        <i class="bi bi-arrow-left me-1"></i>
        Voltar para Backups
    </a>
</div>

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="bi bi-shield-check fs-5 mt-1"></i>

    <div>
        <strong>Teste seguro.</strong>
        Esta ferramenta <strong>não executa restauração</strong>, não substitui o banco atual e não sobrescreve uploads ou temas.
        Ela cria arquivos de teste em <code>storage/backups</code> e valida estrutura, manifesto e SHA-256.
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Pasta de backups</div>
                <div class="fw-semibold text-break">
                    <?= e((string)$quick['backup_directory']) ?>
                </div>
                <div class="small mt-2">
                    <?= !empty($quick['ok'])
                        ? '<span class="badge text-bg-success">Pronta</span>'
                        : '<span class="badge text-bg-danger">Revisar</span>' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Backups do banco</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$quick['database_backups'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Backups completos</div>
                <div class="display-6 fw-semibold">
                    <?= (int)$quick['full_backups'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">ZipArchive</div>
                <div class="mt-2">
                    <?php if (!empty($quick['zip_supported'])): ?>
                        <span class="badge text-bg-success">Disponível</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Indisponível</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($quick['issues'])): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">
            Pré-requisitos pendentes
        </div>

        <ul class="mb-0">
            <?php foreach ((array)$quick['issues'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($quick['warnings'])): ?>
    <div class="alert alert-warning">
        <ul class="mb-0">
            <?php foreach ((array)$quick['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Executar teste
    </div>

    <div class="card-body p-4">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="acao" value="executar">

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="form-check form-switch mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="testarBanco"
                            name="testar_banco"
                            <?= $testDatabase ? 'checked' : '' ?>
                        >

                        <label class="form-check-label fw-semibold" for="testarBanco">
                            Testar backup do banco
                        </label>
                    </div>

                    <div class="small text-secondary">
                        Cria um dump real, confere SHA-256, tabelas, comandos de criação e ciclo de chaves estrangeiras.
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="form-check form-switch mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="testarCompleto"
                            name="testar_completo"
                            <?= $testFull ? 'checked' : '' ?>
                            <?= empty($quick['zip_supported']) ? 'disabled' : '' ?>
                        >

                        <label class="form-check-label fw-semibold" for="testarCompleto">
                            Testar backup completo
                        </label>
                    </div>

                    <div class="small text-secondary">
                        Cria um ZIP, reabre o manifesto e valida tamanho e SHA-256 de todos os arquivos listados.
                    </div>

                    <div class="mt-3 ps-3 border-start">
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="incluirTemas"
                                name="incluir_temas"
                                <?= $includeThemes ? 'checked' : '' ?>
                                <?= empty($quick['zip_supported']) ? 'disabled' : '' ?>
                            >

                            <label class="form-check-label" for="incluirTemas">
                                Incluir temas no backup de teste
                            </label>
                        </div>

                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="incluirUploads"
                                name="incluir_uploads"
                                <?= $includeUploads ? 'checked' : '' ?>
                                <?= empty($quick['zip_supported']) ? 'disabled' : '' ?>
                            >

                            <label class="form-check-label" for="incluirUploads">
                                Incluir uploads
                            </label>
                        </div>

                        <div class="form-text">
                            Uploads ficam desmarcados por padrão para evitar gerar um arquivo muito grande durante a revisão.
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <button
                class="btn btn-primary"
                <?= empty($quick['ok']) ? 'disabled' : '' ?>
            >
                <i class="bi bi-check2-square me-1"></i>
                Executar teste de restaurabilidade
            </button>
        </form>
    </div>
</div>

<?php if (is_array($result)): ?>
    <div class="alert <?= !empty($result['ok']) ? 'alert-success' : 'alert-danger' ?>">
        <strong>
            <?= !empty($result['ok'])
                ? 'Teste concluído com sucesso.'
                : 'O teste encontrou problema(s).' ?>
        </strong>

        Nenhuma restauração foi executada.
        Tempo: <?= (int)$result['duration_ms'] ?> ms.
    </div>

    <?php if (!empty($result['errors'])): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ((array)$result['errors'] as $message): ?>
                    <li><?= e((string)$message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($result['warnings'])): ?>
        <div class="alert alert-warning">
            <ul class="mb-0">
                <?php foreach ((array)$result['warnings'] as $message): ?>
                    <li><?= e((string)$message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (is_array($result['database'])): ?>
        <?php $db = $result['database']; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Resultado — Banco
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="small text-secondary">Arquivo</div>
                        <code><?= e((string)$db['name']) ?></code>
                    </div>

                    <div class="col-lg-3">
                        <div class="small text-secondary">Tamanho</div>
                        <strong><?= e(formatBytes((int)$db['size'])) ?></strong>
                    </div>

                    <div class="col-lg-3">
                        <div class="small text-secondary">Tabelas</div>
                        <strong>
                            <?= (int)$db['dump_tables'] ?>
                            /
                            <?= (int)$db['current_tables'] ?>
                        </strong>
                    </div>

                    <div class="col-12">
                        <div class="small text-secondary">SHA-256</div>
                        <code class="text-break"><?= e((string)$db['sha256']) ?></code>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">CREATE TABLE</div>
                        <strong><?= (int)$db['create_count'] ?></strong>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">DROP TABLE</div>
                        <strong><?= (int)$db['drop_count'] ?></strong>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">Blocos INSERT</div>
                        <strong><?= (int)$db['insert_count'] ?></strong>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (is_array($result['full'])): ?>
        <?php $full = $result['full']; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Resultado — Backup completo
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="small text-secondary">Arquivo</div>
                        <code><?= e((string)$full['name']) ?></code>
                    </div>

                    <div class="col-lg-3">
                        <div class="small text-secondary">Tamanho</div>
                        <strong><?= e(formatBytes((int)$full['size'])) ?></strong>
                    </div>

                    <div class="col-lg-3">
                        <div class="small text-secondary">Arquivos verificados</div>
                        <strong>
                            <?= (int)$full['files_verified'] ?>
                            /
                            <?= (int)$full['files_manifest'] ?>
                        </strong>
                    </div>

                    <div class="col-12">
                        <div class="small text-secondary">SHA-256 do ZIP</div>
                        <code class="text-break"><?= e((string)$full['sha256']) ?></code>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">Banco presente</div>
                        <span class="badge text-bg-success">Sim</span>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">Temas</div>
                        <span class="badge <?= !empty($full['include_themes']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= !empty($full['include_themes']) ? 'Incluídos' : 'Não incluídos' ?>
                        </span>
                    </div>

                    <div class="col-md-4">
                        <div class="small text-secondary">Uploads</div>
                        <span class="badge <?= !empty($full['include_uploads']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= !empty($full['include_uploads']) ? 'Incluídos' : 'Não incluídos' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="alert alert-light border">
        Os arquivos de teste permanecem em <code>storage/backups</code>.
        Se desejar, eles podem ser removidos normalmente em
        <a href="<?= e(url('admin/ferramentas/backups.php')) ?>">Ferramentas → Backups</a>.
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../_footer.php'; ?>
