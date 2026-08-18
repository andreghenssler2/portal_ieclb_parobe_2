<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? 'upload');
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && MediaService::delete($pdo, $id)) {
                logAction($pdo, 'midia.excluir', 'midias', $id);
                Session::flash('success', 'Mídia excluída.');
            }
            header('Location: ' . url('admin/midias/index.php'));
            exit;
        }

        $files = $_FILES['arquivos'] ?? null;
        if (!$files || !is_array($files['name'] ?? null)) {
            $error = 'Selecione pelo menos um arquivo.';
        } else {
            $success = 0;
            $errors = [];
            foreach ($files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $file = [
                    'name' => $name,
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$i] ?? 0,
                ];
                try {
                    $media = MediaService::upload($pdo, $file, (int)Auth::id());
                    logAction($pdo, 'midia.upload', 'midias', (int)$media['id'], (string)$media['nome_original']);
                    $success++;
                } catch (Throwable $e) {
                    $errors[] = $name . ': ' . $e->getMessage();
                }
            }
            if ($success > 0) Session::flash('success', $success . ' arquivo(s) enviado(s) com sucesso.');
            if (!$errors) {
                header('Location: ' . url('admin/midias/index.php'));
                exit;
            }
            $error = implode(' ', $errors);
        }
    }
}

$filter = (string)($_GET['tipo'] ?? 'todos');
$where = '';
if ($filter === 'imagens') $where = "WHERE m.mime_type LIKE 'image/%'";
elseif ($filter === 'documentos') $where = "WHERE m.mime_type NOT LIKE 'image/%'";
$midias = $pdo->query("SELECT m.*, u.nome AS usuario_nome FROM midias m LEFT JOIN usuarios u ON u.id=m.usuario_id $where ORDER BY m.id DESC")->fetchAll();
$pageTitle = 'Biblioteca de Mídia';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div><h1 class="h3 mb-1">Biblioteca de Mídia</h1><p class="text-secondary mb-0">Imagens e documentos reutilizáveis no portal.</p></div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
  <form method="post" enctype="multipart/form-data">
    <?= Csrf::field() ?><input type="hidden" name="action" value="upload">
    <div class="row g-3 align-items-end">
      <div class="col-lg-9"><label class="form-label">Enviar arquivos</label><input class="form-control" type="file" name="arquivos[]" multiple required accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><div class="form-text">Imagens, PDF e documentos. Máximo <?= e(formatBytes(UPLOAD_MAX_SIZE)) ?> por arquivo.</div></div>
      <div class="col-lg-3"><button class="btn btn-primary w-100">Enviar para biblioteca</button></div>
    </div>
  </form>
</div></div>
<div class="d-flex gap-2 mb-3">
  <a class="btn btn-sm <?= $filter==='todos'?'btn-dark':'btn-outline-secondary' ?>" href="<?= e(url('admin/midias/index.php')) ?>">Todos</a>
  <a class="btn btn-sm <?= $filter==='imagens'?'btn-dark':'btn-outline-secondary' ?>" href="<?= e(url('admin/midias/index.php?tipo=imagens')) ?>">Imagens</a>
  <a class="btn btn-sm <?= $filter==='documentos'?'btn-dark':'btn-outline-secondary' ?>" href="<?= e(url('admin/midias/index.php?tipo=documentos')) ?>">Documentos</a>
</div>
<div class="row g-3">
<?php if (!$midias): ?><div class="col-12"><div class="alert alert-light border">Nenhuma mídia cadastrada.</div></div><?php endif; ?>
<?php foreach ($midias as $m): $isImage = str_starts_with($m['mime_type'], 'image/'); ?>
  <div class="col-sm-6 col-lg-4 col-xl-3"><div class="card h-100 border-0 shadow-sm media-card">
    <?php if ($isImage): ?><img src="<?= e(mediaUrl($m['caminho'])) ?>" class="card-img-top media-thumb" alt="<?= e($m['alt_text'] ?: $m['titulo'] ?: $m['nome_original']) ?>">
    <?php else: ?><div class="media-file-placeholder"><strong>.<?= e(strtoupper($m['extensao'])) ?></strong></div><?php endif; ?>
    <div class="card-body"><div class="fw-semibold text-truncate" title="<?= e($m['titulo'] ?: $m['nome_original']) ?>"><?= e($m['titulo'] ?: $m['nome_original']) ?></div><div class="small text-secondary mt-1"><?= e(formatBytes((int)$m['tamanho'])) ?><?php if ($m['largura'] && $m['altura']): ?> · <?= (int)$m['largura'] ?>×<?= (int)$m['altura'] ?><?php endif; ?></div></div>
    <div class="card-footer bg-white border-0 d-flex gap-2"><a class="btn btn-sm btn-outline-secondary flex-grow-1" href="<?= e(url('admin/midias/editar.php?id='.(int)$m['id'])) ?>">Detalhes</a><a class="btn btn-sm btn-outline-primary" href="<?= e(mediaUrl($m['caminho'])) ?>" target="_blank">Abrir</a></div>
  </div></div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
