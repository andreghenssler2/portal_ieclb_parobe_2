<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();

$tipo = trim((string)($_GET['tipo'] ?? ''));
$comunidadeId = isset($_GET['comunidade']) && $_GET['comunidade'] !== '' ? (int)$_GET['comunidade'] : null;
$categoriaId = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? (int)$_GET['categoria'] : null;
$periodo = trim((string)($_GET['periodo'] ?? 'proximos'));
$busca = trim((string)($_GET['q'] ?? ''));
if (!in_array($periodo, ['proximos', 'hoje', 'mes'], true)) {
    $periodo = 'proximos';
}

$where = ["e.status = 'publicado'"];
$params = [];

if ($periodo === 'hoje') {
    $where[] = 'DATE(e.data_inicio) = CURDATE()';
} elseif ($periodo === 'mes') {
    $where[] = 'e.data_inicio >= NOW()';
    $where[] = "e.data_inicio < DATE_ADD(LAST_DAY(NOW()), INTERVAL 1 DAY)";
} else {
    $where[] = 'e.data_inicio >= NOW()';
}

if (in_array($tipo, ['culto', 'evento'], true)) {
    $where[] = 'e.tipo = :tipo';
    $params['tipo'] = $tipo;
}
if ($comunidadeId !== null && $comunidadeId > 0) {
    $where[] = 'e.comunidade_id = :comunidade_id';
    $params['comunidade_id'] = $comunidadeId;
}
if ($categoriaId !== null && $categoriaId > 0) {
    $where[] = 'e.categoria_evento_id = :categoria_id';
    $params['categoria_id'] = $categoriaId;
}
if ($busca !== '') {
    $where[] = '(e.titulo LIKE :busca OR e.resumo LIKE :busca OR e.local LIKE :busca OR e.descricao LIKE :busca)';
    $params['busca'] = '%' . $busca . '%';
}

$sql = "SELECT e.*, c.nome AS comunidade_nome, ec.nome AS categoria_nome,
               m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt
        FROM eventos e
        LEFT JOIN comunidades c ON c.id = e.comunidade_id
        LEFT JOIN evento_categorias ec ON ec.id = e.categoria_evento_id
        LEFT JOIN midias m ON m.id = e.imagem_capa_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.data_inicio ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventos = $stmt->fetchAll();
$comunidades = $pdo->query('SELECT id, nome FROM comunidades WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();
$categorias = $pdo->query('SELECT id, nome FROM evento_categorias WHERE ativa = 1 ORDER BY ordem, nome')->fetchAll();

$meses = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
$grupos = [];
foreach ($eventos as $evento) {
    $dt = new DateTime((string)$evento['data_inicio']);
    $chave = $dt->format('Y-m');
    if (!isset($grupos[$chave])) {
        $grupos[$chave] = [
            'titulo' => $meses[(int)$dt->format('n')] . ' de ' . $dt->format('Y'),
            'eventos' => [],
        ];
    }
    $grupos[$chave]['eventos'][] = $evento;
}

$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');
$metaTitle = 'Agenda - ' . $siteLabel;
$metaDescription = 'Próximos cultos e eventos da Paróquia Evangélica de Confissão Luterana de Parobé.';
require themeFile($pdo, 'header.php');
?>
<section class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold mb-2">Agenda</h1>
            <p class="lead text-secondary mb-0">Cultos, encontros e eventos da Paróquia de Parobé.</p>
        </div>
        <span class="badge text-bg-light border fs-6"><?= count($eventos) ?> compromisso<?= count($eventos) === 1 ? '' : 's' ?></span>
    </div>

    <form method="get" class="card border-0 shadow-sm mb-5">
        <div class="card-body p-4">
            <div class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Buscar</label>
                    <input class="form-control" name="q" value="<?= e($busca) ?>" placeholder="Título, local...">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Período</label>
                    <select class="form-select" name="periodo">
                        <option value="proximos" <?= $periodo === 'proximos' ? 'selected' : '' ?>>Próximos</option>
                        <option value="hoje" <?= $periodo === 'hoje' ? 'selected' : '' ?>>Hoje</option>
                        <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Este mês</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" name="tipo">
                        <option value="">Todos</option>
                        <option value="culto" <?= $tipo === 'culto' ? 'selected' : '' ?>>Cultos</option>
                        <option value="evento" <?= $tipo === 'evento' ? 'selected' : '' ?>>Eventos</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Categoria</label>
                    <select class="form-select" name="categoria">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option value="<?= (int)$categoria['id'] ?>" <?= $categoriaId === (int)$categoria['id'] ? 'selected' : '' ?>><?= e($categoria['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Comunidade</label>
                    <select class="form-select" name="comunidade">
                        <option value="">Todas</option>
                        <?php foreach ($comunidades as $comunidade): ?>
                            <option value="<?= (int)$comunidade['id'] ?>" <?= $comunidadeId === (int)$comunidade['id'] ? 'selected' : '' ?>><?= e($comunidade['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-1 col-md-6 d-flex gap-2">
                    <button class="btn btn-primary" title="Filtrar"><i class="bi bi-funnel"></i></button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('agenda.php')) ?>" title="Limpar"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </div>
    </form>

    <?php if (!$eventos): ?>
        <div class="alert alert-light border">Nenhum culto ou evento encontrado com esses filtros.</div>
    <?php endif; ?>

    <?php foreach ($grupos as $grupo): ?>
        <div class="d-flex align-items-center gap-3 mb-3 mt-4">
            <h2 class="h4 mb-0"><?= e($grupo['titulo']) ?></h2>
            <div class="border-top flex-grow-1"></div>
        </div>
        <div class="row g-4 mb-5">
            <?php foreach ($grupo['eventos'] as $evento): ?>
                <div class="col-md-6 col-xl-4">
                    <article class="card agenda-card h-100 border-0 shadow-sm overflow-hidden">
                        <?php if ($evento['imagem_capa_midia']): ?>
                            <img src="<?= e(mediaUrl($evento['imagem_capa_midia'])) ?>" class="agenda-card-image" alt="<?= e($evento['imagem_capa_alt'] ?: $evento['titulo']) ?>">
                        <?php endif; ?>
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <span class="badge <?= $evento['tipo'] === 'culto' ? 'text-bg-primary' : 'text-bg-info' ?>"><?= e(eventTypeLabel($evento['tipo'])) ?></span>
                                <?php if ($evento['categoria_nome']): ?><span class="badge text-bg-light border"><?= e($evento['categoria_nome']) ?></span><?php endif; ?>
                                <?php if ((int)$evento['santa_ceia'] === 1): ?><span class="badge text-bg-light border">Santa Ceia</span><?php endif; ?>
                            </div>
                            <div class="agenda-date mb-2"><?= e(formatDateOnlyBr($evento['data_inicio'])) ?> às <?= e(formatTimeBr($evento['data_inicio'])) ?></div>
                            <h3 class="h5"><a class="stretched-link text-decoration-none text-dark" href="<?= e(contentUrl('evento', (string)$evento['slug'])) ?>"><?= e($evento['titulo']) ?></a></h3>
                            <div class="small text-secondary mb-2"><?= e($evento['comunidade_nome'] ?: 'Paroquial') ?></div>
                            <?php if ($evento['local']): ?><div class="small text-secondary"><?= e($evento['local']) ?></div><?php endif; ?>
                            <?php if ($evento['resumo']): ?><p class="text-secondary mt-3 mb-0"><?= e($evento['resumo']) ?></p><?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
