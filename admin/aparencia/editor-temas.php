<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/ThemeEditorService.php';
Auth::requirePermission('tema_editor.gerenciar');

$pdo = Database::connection();
$service = new ThemeEditorService(dirname(__DIR__, 2));
$themes = installedThemes();
$activeTheme = activeThemeSlug($pdo);
$error = '';
$warning = '';

$themeSlug = trim((string)($_GET['tema'] ?? $_POST['tema'] ?? $activeTheme));
if (!isset($themes[$themeSlug])) {
    $themeSlug = $activeTheme;
}

try {
    $files = $service->editableFiles($themeSlug);
} catch (Throwable $e) {
    $files = [];
    $error = $e->getMessage();
}

$file = trim((string)($_GET['arquivo'] ?? $_POST['arquivo'] ?? ($files[0]['path'] ?? '')));
if ($file !== '' && !in_array($file, array_column($files, 'path'), true)) {
    $file = $files[0]['path'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['acao'] ?? 'salvar');
            if ($action === 'salvar') {
                $content = (string)($_POST['conteudo'] ?? '');
                $result = $service->save($themeSlug, $file, $content);
                logAction($pdo, 'tema.arquivo_editar', 'tema', null, $themeSlug . '/' . $file . ' | backup ' . $result['backup']);
                Session::flash('success', 'Arquivo salvo. Backup criado: ' . $result['backup'] . '. ' . $result['lint']);
            } elseif ($action === 'restaurar') {
                $backupId = trim((string)($_POST['backup_id'] ?? ''));
                $service->restore($themeSlug, $file, $backupId);
                logAction($pdo, 'tema.arquivo_restaurar', 'tema', null, $themeSlug . '/' . $file . ' | backup ' . $backupId);
                Session::flash('success', 'Backup restaurado com sucesso. Uma cópia da versão anterior também foi criada.');
            }
            header('Location: ' . url('admin/aparencia/editor-temas.php?tema=' . rawurlencode($themeSlug) . '&arquivo=' . rawurlencode($file)));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$content = '';
$backups = [];
if ($file !== '') {
    try {
        $content = $service->read($themeSlug, $file);
        $backups = $service->backups($themeSlug, $file);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Editor de Temas';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Editor de Temas</h1>
        <p class="text-secondary mb-0">Edite arquivos do tema com backup automático antes de cada alteração.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/aparencia/temas.php')) ?>"><i class="bi bi-palette me-1"></i>Temas</a>
        <a class="btn btn-outline-primary" href="<?= e(url()) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Ver portal</a>
    </div>
</div>

<div class="alert alert-warning d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div><strong>Atenção:</strong> alterações em PHP/CSS/JS podem mudar ou interromper o tema público. O portal cria um backup antes de cada salvamento e restauração.</div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-3">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Tema</div>
            <div class="card-body">
                <form method="get">
                    <label class="form-label">Tema instalado</label>
                    <select class="form-select" name="tema" onchange="this.form.submit()">
                        <?php foreach ($themes as $slug => $theme): ?>
                            <option value="<?= e($slug) ?>" <?= $slug === $themeSlug ? 'selected' : '' ?>><?= e($theme['name']) ?><?= $slug === $activeTheme ? ' (ativo)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Arquivos editáveis</div>
            <div class="list-group list-group-flush theme-editor-file-list">
                <?php foreach ($files as $item): ?>
                    <a class="list-group-item list-group-item-action <?= $item['path'] === $file ? 'active' : '' ?>" href="<?= e(url('admin/aparencia/editor-temas.php?tema=' . rawurlencode($themeSlug) . '&arquivo=' . rawurlencode($item['path']))) ?>">
                        <div class="d-flex align-items-center gap-2"><i class="bi bi-filetype-<?= $item['extension'] === 'js' ? 'js' : ($item['extension'] === 'css' ? 'css' : ($item['extension'] === 'php' ? 'php' : 'json')) ?>"></i><span class="text-truncate"><?= e($item['path']) ?></span></div>
                        <small class="<?= $item['path'] === $file ? 'text-white-50' : 'text-secondary' ?>"><?= e(formatBytes((int)$item['size'])) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$files): ?><div class="p-3 text-secondary small">Nenhum arquivo editável encontrado.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-9">
        <?php if ($file !== ''): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><span class="fw-semibold"><?= e($themes[$themeSlug]['name'] ?? $themeSlug) ?></span><span class="text-secondary"> / <?= e($file) ?></span></div>
                <span class="badge text-bg-light border"><?= e(strtoupper((string)pathinfo($file, PATHINFO_EXTENSION))) ?></span>
            </div>
            <div class="card-body p-0">
                <form method="post" id="themeEditorForm">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="tema" value="<?= e($themeSlug) ?>">
                    <input type="hidden" name="arquivo" value="<?= e($file) ?>">
                    <textarea class="form-control theme-code-editor" id="themeCodeEditor" name="conteudo" spellcheck="false" aria-label="Conteúdo do arquivo <?= e($file) ?>"><?= e($content) ?></textarea>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top bg-body-tertiary">
                        <div class="small text-secondary"><i class="bi bi-shield-check me-1"></i>Backup automático antes de salvar.</div>
                        <button class="btn btn-primary px-4"><i class="bi bi-floppy me-1"></i>Salvar arquivo</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Backups deste arquivo</span>
                <span class="badge text-bg-secondary"><?= count($backups) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if ($backups): ?>
                    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data</th><th>Motivo</th><th>Tamanho</th><th class="text-end">Ação</th></tr></thead><tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i:s', (int)$backup['date'])) ?></td>
                            <td><?= e(ucfirst((string)$backup['label'])) ?></td>
                            <td><?= e(formatBytes((int)$backup['size'])) ?></td>
                            <td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Restaurar este backup? A versão atual será salva antes da restauração.');"><?= Csrf::field() ?><input type="hidden" name="acao" value="restaurar"><input type="hidden" name="tema" value="<?= e($themeSlug) ?>"><input type="hidden" name="arquivo" value="<?= e($file) ?>"><input type="hidden" name="backup_id" value="<?= e($backup['id']) ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?><div class="p-4 text-secondary">Ainda não existem backups para este arquivo.</div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="<?= e(url('public/js/theme-editor.js')) ?>"></script>
<?php require __DIR__ . '/../_footer.php'; ?>
