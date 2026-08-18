<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('galerias.gerenciar');
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$galeria = [
    'titulo' => '',
    'slug' => '',
    'descricao' => '',
    'seo_titulo' => '',
    'seo_descricao' => '',
    'seo_noindex' => 0,
    'imagem_capa_id' => '',
    'status' => 'rascunho',
    'publicado_em' => '',
];
$error = '';

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM galerias WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Galeria não encontrada.');
    }
    $galeria = $found;
}

$images = $pdo->query(
    "SELECT id, caminho, titulo, alt_text, nome_original
     FROM midias
     WHERE mime_type LIKE 'image/%'
     ORDER BY id DESC"
)->fetchAll();

$selected = [];
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT midia_id, legenda, ordem FROM galeria_midias WHERE galeria_id = :id ORDER BY ordem, id');
    $stmt->execute(['id' => $id]);
    foreach ($stmt->fetchAll() as $item) {
        $selected[(int)$item['midia_id']] = [
            'legenda' => (string)($item['legenda'] ?? ''),
            'ordem' => (int)$item['ordem'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $galeria = array_merge($galeria, $_POST);
    $galeria['seo_noindex'] = isset($_POST['seo_noindex']) ? 1 : 0;
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            if ($titulo === '') {
                throw new RuntimeException('Informe o título da galeria.');
            }

            $status = (string)($_POST['status'] ?? 'rascunho');
            if (!in_array($status, ['rascunho', 'publicado', 'arquivado'], true)) {
                throw new RuntimeException('Status inválido.');
            }

            $slugSource = trim((string)($_POST['slug'] ?? '')) ?: $titulo;
            $slug = uniqueSlug($pdo, 'galerias', $slugSource, $id > 0 ? $id : null);
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $capaId = (int)($_POST['imagem_capa_id'] ?? 0);
            $publicadoEmInput = trim((string)($_POST['publicado_em'] ?? ''));
            $publicadoEm = null;
            if ($publicadoEmInput !== '') {
                $timestamp = strtotime($publicadoEmInput);
                if ($timestamp === false) {
                    throw new RuntimeException('Data de publicação inválida.');
                }
                $publicadoEm = date('Y-m-d H:i:s', $timestamp);
            }
            if ($status === 'publicado' && !$publicadoEm) {
                $publicadoEm = date('Y-m-d H:i:s');
            }

            $validImageIds = array_fill_keys(array_map(static fn($img) => (int)$img['id'], $images), true);
            if ($capaId > 0 && !isset($validImageIds[$capaId])) {
                throw new RuntimeException('A imagem de capa selecionada não é válida.');
            }

            $midias = array_values(array_unique(array_map('intval', $_POST['midias'] ?? [])));
            foreach ($midias as $midiaId) {
                if ($midiaId <= 0 || !isset($validImageIds[$midiaId])) {
                    throw new RuntimeException('Uma das imagens selecionadas não é válida.');
                }
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE galerias SET titulo=:titulo, slug=:slug, descricao=:descricao, imagem_capa_id=:capa,
                     seo_titulo=:seo_titulo, seo_descricao=:seo_descricao, seo_noindex=:seo_noindex,
                     status=:status, publicado_em=:publicado_em WHERE id=:id'
                );
                $stmt->execute([
                    'titulo' => $titulo, 'slug' => $slug, 'descricao' => $descricao ?: null,
                    'capa' => $capaId ?: null, 'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null, 'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status, 'publicado_em' => $publicadoEm, 'id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO galerias (autor_id,titulo,slug,descricao,imagem_capa_id,seo_titulo,seo_descricao,seo_noindex,status,publicado_em)
                     VALUES (:autor,:titulo,:slug,:descricao,:capa,:seo_titulo,:seo_descricao,:seo_noindex,:status,:publicado_em)'
                );
                $stmt->execute([
                    'autor' => (int)Auth::id(), 'titulo' => $titulo, 'slug' => $slug,
                    'descricao' => $descricao ?: null, 'capa' => $capaId ?: null,
                    'seo_titulo' => trim((string)($_POST['seo_titulo'] ?? '')) ?: null,
                    'seo_descricao' => trim((string)($_POST['seo_descricao'] ?? '')) ?: null,
                    'seo_noindex' => isset($_POST['seo_noindex']) ? 1 : 0,
                    'status' => $status, 'publicado_em' => $publicadoEm,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM galeria_midias WHERE galeria_id = :id')->execute(['id' => $id]);
            $insert = $pdo->prepare(
                'INSERT INTO galeria_midias (galeria_id,midia_id,legenda,ordem)
                 VALUES (:galeria,:midia,:legenda,:ordem)'
            );
            $legendas = $_POST['legenda'] ?? [];
            $ordens = $_POST['ordem'] ?? [];
            $autoOrder = 10;
            foreach ($midias as $midiaId) {
                $ordem = isset($ordens[$midiaId]) && is_numeric($ordens[$midiaId]) ? (int)$ordens[$midiaId] : $autoOrder;
                $legenda = trim((string)($legendas[$midiaId] ?? ''));
                $insert->execute([
                    'galeria' => $id,
                    'midia' => $midiaId,
                    'legenda' => $legenda !== '' ? mb_substr($legenda, 0, 255) : null,
                    'ordem' => $ordem,
                ]);
                $autoOrder += 10;
            }
            $pdo->commit();

            logAction($pdo, 'galeria.salvar', 'galerias', $id, $titulo . ' · ' . count($midias) . ' foto(s)');
            Session::flash('success', 'Galeria salva com sucesso.');
            header('Location: ' . url('admin/galerias/index.php'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }

    $selected = [];
    foreach (array_map('intval', $_POST['midias'] ?? []) as $midiaId) {
        $selected[$midiaId] = [
            'legenda' => (string)(($_POST['legenda'] ?? [])[$midiaId] ?? ''),
            'ordem' => (int)(($_POST['ordem'] ?? [])[$midiaId] ?? 0),
        ];
    }
}

$pageTitle = $id > 0 ? 'Editar galeria' : 'Nova galeria';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><?= $id > 0 ? 'Editar galeria' : 'Nova galeria' ?></h1>
        <p class="text-secondary mb-0">Monte um álbum reutilizando imagens da Biblioteca de Mídia.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/galerias/index.php')) ?>">Voltar</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input class="form-control" name="titulo" value="<?= e((string)$galeria['titulo']) ?>" required maxlength="220">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input class="form-control" name="slug" value="<?= e((string)$galeria['slug']) ?>" placeholder="gerada automaticamente">
                        <div class="form-text">URL pública: /galeria/slug-da-galeria</div>
                    </div>
                    <div>
                        <label class="form-label">Descrição</label>
                        <textarea class="form-control" name="descricao" rows="5"><?= e((string)$galeria['descricao']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Fotos da galeria</div>
                <div class="card-body p-4">
                    <?php if (!$images): ?>
                        <div class="alert alert-light border mb-0">Nenhuma imagem disponível. Envie imagens na Biblioteca de Mídia primeiro.</div>
                    <?php else: ?>
                        <div class="row g-3 gallery-picker-grid">
                            <?php foreach ($images as $image): $imageId = (int)$image['id']; $isSelected = isset($selected[$imageId]); ?>
                                <div class="col-sm-6 col-lg-4">
                                    <div class="gallery-picker-item border rounded-3 p-2 h-100 <?= $isSelected ? 'is-selected' : '' ?>">
                                        <label class="d-block cursor-pointer">
                                            <img src="<?= e(mediaUrl($image['caminho'])) ?>" class="w-100 rounded gallery-picker-thumb" alt="<?= e($image['alt_text'] ?: $image['titulo'] ?: $image['nome_original']) ?>">
                                            <div class="form-check mt-2">
                                                <input class="form-check-input gallery-image-check" type="checkbox" name="midias[]" value="<?= $imageId ?>" <?= $isSelected ? 'checked' : '' ?>>
                                                <span class="form-check-label small fw-semibold text-truncate d-block"><?= e($image['titulo'] ?: $image['nome_original']) ?></span>
                                            </div>
                                        </label>
                                        <div class="gallery-image-fields mt-2 <?= $isSelected ? '' : 'd-none' ?>">
                                            <input class="form-control form-control-sm mb-2" name="legenda[<?= $imageId ?>]" value="<?= e($selected[$imageId]['legenda'] ?? '') ?>" placeholder="Legenda opcional">
                                            <input class="form-control form-control-sm" type="number" name="ordem[<?= $imageId ?>]" value="<?= e((string)($selected[$imageId]['ordem'] ?? '')) ?>" placeholder="Ordem">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Publicação</div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach (['rascunho' => 'Rascunho', 'publicado' => 'Publicado', 'arquivado' => 'Arquivado'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= (string)$galeria['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Data de publicação</label>
                        <input class="form-control" type="datetime-local" name="publicado_em" value="<?= e(!empty($galeria['publicado_em']) ? date('Y-m-d\TH:i', strtotime((string)$galeria['publicado_em'])) : '') ?>">
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">SEO do conteúdo</div><div class="card-body p-4"><div class="mb-3"><label class="form-label">Título SEO</label><input class="form-control" name="seo_titulo" maxlength="180" value="<?= e((string)($galeria['seo_titulo'] ?? '')) ?>"></div><div class="mb-3"><label class="form-label">Meta description</label><textarea class="form-control" name="seo_descricao" maxlength="320" rows="3"><?= e((string)($galeria['seo_descricao'] ?? '')) ?></textarea></div><div class="form-check"><input class="form-check-input" type="checkbox" name="seo_noindex" id="seoNoindex" <?= !empty($galeria['seo_noindex']) ? 'checked' : '' ?>><label class="form-check-label" for="seoNoindex">Não indexar esta galeria</label></div></div></div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Imagem de capa</div>
                <div class="card-body p-4">
                    <select class="form-select" name="imagem_capa_id">
                        <option value="">Sem capa</option>
                        <?php foreach ($images as $image): ?>
                            <option value="<?= (int)$image['id'] ?>" <?= (string)$galeria['imagem_capa_id'] === (string)$image['id'] ? 'selected' : '' ?>><?= e($image['titulo'] ?: $image['nome_original']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">A capa não precisa obrigatoriamente fazer parte do álbum.</div>
                </div>
            </div>

            <button class="btn btn-primary w-100 py-2">Salvar galeria</button>
        </div>
    </div>
</form>
<script>
document.querySelectorAll('.gallery-image-check').forEach(function (check) {
    check.addEventListener('change', function () {
        const item = check.closest('.gallery-picker-item');
        const fields = item.querySelector('.gallery-image-fields');
        item.classList.toggle('is-selected', check.checked);
        fields.classList.toggle('d-none', !check.checked);
    });
});
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
