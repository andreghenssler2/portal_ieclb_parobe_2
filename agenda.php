<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$tipo = trim((string)($_GET['tipo'] ?? ''));
$comunidadeId = isset($_GET['comunidade']) && $_GET['comunidade'] !== '' ? (int)$_GET['comunidade'] : null;

$where = ["e.status = 'publicado'", 'e.data_inicio >= NOW()'];
$params = [];
if (in_array($tipo, ['culto', 'evento'], true)) {
    $where[] = 'e.tipo = :tipo';
    $params['tipo'] = $tipo;
}
if ($comunidadeId !== null && $comunidadeId > 0) {
    $where[] = 'e.comunidade_id = :comunidade_id';
    $params['comunidade_id'] = $comunidadeId;
}

$sql = "SELECT e.*, c.nome AS comunidade_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt
        FROM eventos e
        LEFT JOIN comunidades c ON c.id = e.comunidade_id
        LEFT JOIN midias m ON m.id = e.imagem_capa_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.data_inicio ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();
$comunidades = $pdo->query('SELECT id, nome FROM comunidades WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();

$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');
$metaTitle = 'Agenda - ' . $siteLabel;
$metaDescription = 'Próximos cultos e eventos da Paróquia Evangélica de Confissão Luterana de Parobé.';
require __DIR__ . '/theme/ieclb/header.php';
?>
<section class="container py-5">
    <div class="mb-4">
        <h1 class="display-6 fw-bold mb-2">Agenda</h1>
        <p class="lead text-secondary mb-0">Próximos cultos e eventos da Paróquia de Parobé.</p>
    </div>

    <form method="get" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="tipo">
                        <option value="">Cultos e eventos</option>
                        <option value="culto" <?= $tipo === 'culto' ? 'selected' : '' ?>>Somente cultos</option>
                        <option value="evento" <?= $tipo === 'evento' ? 'selected' : '' ?>>Somente eventos</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Comunidade</label>
                    <select class="form-select" name="comunidade">
                        <option value="">Todas as comunidades</option>
                        <?php foreach ($comunidades as $comunidade): ?>
                            <option value="<?= (int)$comunidade['id'] ?>" <?= $comunidadeId === (int)$comunidade['id'] ? 'selected' : '' ?>><?= e($comunidade['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary">Filtrar</button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('agenda.php')) ?>">Limpar</a>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-4">
        <?php if (!$eventos): ?>
            <div class="col-12"><div class="alert alert-light border">Nenhum culto ou evento futuro encontrado com esses filtros.</div></div>
        <?php endif; ?>
        <?php foreach ($eventos as $evento): ?>
            <div class="col-md-6 col-xl-4">
                <article class="card agenda-card h-100 border-0 shadow-sm overflow-hidden">
                    <?php if ($evento['imagem_capa_midia']): ?>
                        <img src="<?= e(mediaUrl($evento['imagem_capa_midia'])) ?>" class="agenda-card-image" alt="<?= e($evento['imagem_capa_alt'] ?: $evento['titulo']) ?>">
                    <?php endif; ?>
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge <?= $evento['tipo'] === 'culto' ? 'text-bg-primary' : 'text-bg-info' ?>"><?= e(eventTypeLabel($evento['tipo'])) ?></span>
                            <?php if ((int)$evento['santa_ceia'] === 1): ?><span class="badge text-bg-light border">Santa Ceia</span><?php endif; ?>
                        </div>
                        <div class="agenda-date mb-2"><?= e(formatDateOnlyBr($evento['data_inicio'])) ?> às <?= e(formatTimeBr($evento['data_inicio'])) ?></div>
                        <h2 class="h5"><a class="stretched-link text-decoration-none text-dark" href="<?= e(contentUrl('evento', (string)$evento['slug'])) ?>"><?= e($evento['titulo']) ?></a></h2>
                        <div class="small text-secondary mb-2"><?= e($evento['comunidade_nome'] ?: 'Paroquial') ?></div>
                        <?php if ($evento['local']): ?><div class="small text-secondary"><?= e($evento['local']) ?></div><?php endif; ?>
                        <?php if ($evento['resumo']): ?><p class="text-secondary mt-3 mb-0"><?= e($evento['resumo']) ?></p><?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/theme/ieclb/footer.php'; ?>
