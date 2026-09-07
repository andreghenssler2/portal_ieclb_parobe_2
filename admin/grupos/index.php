<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/GroupService.php';

Auth::requirePermission('grupos.gerenciar');

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        Session::flash('error', 'Token de segurança inválido.');
    } else {
        $id = max(0, (int)($_POST['id'] ?? 0));

        try {
            $item = GroupService::find($pdo, $id);

            if (!$item) {
                throw new RuntimeException('Grupo / Ministério não encontrado.');
            }

            GroupService::delete($pdo, $id);

            logAction(
                $pdo,
                'grupo.excluir',
                'grupos',
                $id,
                (string)$item['nome']
            );

            Session::flash('success', 'Grupo / Ministério removido.');
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
    }

    header('Location: ' . url('admin/grupos/index.php'));
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$type = strtolower(trim((string)($_GET['tipo'] ?? '')));
$communityId = max(0, (int)($_GET['comunidade'] ?? 0));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$page = max(1, (int)($_GET['pagina'] ?? 1));

$list = GroupService::adminList(
    $pdo,
    $q,
    $type,
    $communityId,
    $status,
    $page
);

$communities = GroupService::communities($pdo);

function groupAdminUrl(
    int $page,
    string $q,
    string $type,
    int $communityId,
    string $status
): string {
    $params = [];

    if ($q !== '') {
        $params['q'] = $q;
    }

    if ($type !== '') {
        $params['tipo'] = $type;
    }

    if ($communityId > 0) {
        $params['comunidade'] = $communityId;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($page > 1) {
        $params['pagina'] = $page;
    }

    return url(
        'admin/grupos/index.php'
        . ($params ? '?' . http_build_query($params) : '')
    );
}

$pageTitle = 'Grupos / Ministérios';
require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Grupos / Ministérios</h1>
        <p class="text-secondary mb-0">
            Gerencie grupos, ministérios, comissões e projetos da paróquia.
        </p>
    </div>

    <a class="btn btn-primary" href="<?= e(url('admin/grupos/form.php')) ?>">
        <i class="bi bi-people-fill me-1"></i>
        Adicionar novo
    </a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small">Pesquisar</label>
                <input
                    class="form-control"
                    type="search"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="Nome, descrição, responsável ou local"
                >
            </div>

            <div class="col-md-4 col-lg-2">
                <label class="form-label small">Tipo</label>
                <select class="form-select" name="tipo">
                    <option value="">Todos</option>
                    <?php foreach (GroupService::typeLabels() as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $type === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 col-lg-3">
                <label class="form-label small">Comunidade</label>
                <select class="form-select" name="comunidade">
                    <option value="0">Todas</option>

                    <?php foreach ($communities as $community): ?>
                        <option
                            value="<?= (int)$community['id'] ?>"
                            <?= $communityId === (int)$community['id'] ? 'selected' : '' ?>
                        >
                            <?= e($community['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 col-lg-2">
                <label class="form-label small">Status</label>
                <select class="form-select" name="status">
                    <option value="">Todos</option>
                    <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>
                        Ativos
                    </option>
                    <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>
                        Inativos
                    </option>
                </select>
            </div>

            <div class="col-lg-1 d-flex gap-1">
                <button class="btn btn-outline-primary flex-grow-1" title="Filtrar">
                    <i class="bi bi-search"></i>
                </button>

                <?php if (
                    $q !== ''
                    || $type !== ''
                    || $communityId > 0
                    || $status !== ''
                ): ?>
                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e(url('admin/grupos/index.php')) ?>"
                        title="Limpar filtros"
                    >×</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Grupo / Ministério</th>
                    <th>Tipo</th>
                    <th>Comunidade</th>
                    <th>Encontros</th>
                    <th>Status</th>
                    <th>Ordem</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$list['items']): ?>
                    <tr>
                        <td colspan="7" class="text-secondary py-4">
                            Nenhum grupo ou ministério encontrado.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($list['items'] as $item): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <?php if (!empty($item['imagem_caminho'])): ?>
                                    <img
                                        src="<?= e(mediaUrl((string)$item['imagem_caminho'])) ?>"
                                        alt=""
                                        style="width:56px;height:56px;object-fit:cover;border-radius:.75rem"
                                    >
                                <?php else: ?>
                                    <div
                                        class="d-flex align-items-center justify-content-center bg-body-tertiary text-secondary"
                                        style="width:56px;height:56px;border-radius:.75rem"
                                    >
                                        <i class="bi bi-people fs-4"></i>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <div class="fw-semibold">
                                        <?= e($item['nome']) ?>
                                    </div>

                                    <?php if (!empty($item['responsavel'])): ?>
                                        <div class="small text-secondary">
                                            Responsável: <?= e($item['responsavel']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="small text-secondary">
                                        <code><?= e($item['slug']) ?></code>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <?= e(GroupService::typeLabel((string)$item['tipo'])) ?>
                        </td>

                        <td>
                            <?= !empty($item['comunidade_nome'])
                                ? e($item['comunidade_nome'])
                                : '<span class="text-secondary">Paroquial / Geral</span>' ?>
                        </td>

                        <td>
                            <?php if (!empty($item['dia_semana']) || !empty($item['horario'])): ?>
                                <div><?= e((string)($item['dia_semana'] ?? '')) ?></div>
                                <?php if (!empty($item['horario'])): ?>
                                    <div class="small text-secondary">
                                        <?= e($item['horario']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= !empty($item['ativo'])
                                ? '<span class="badge text-bg-success">Ativo</span>'
                                : '<span class="badge text-bg-secondary">Inativo</span>' ?>
                        </td>

                        <td><?= (int)$item['ordem'] ?></td>

                        <td class="text-end text-nowrap">
                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="<?= e(url('admin/grupos/form.php?id=' . (int)$item['id'])) ?>"
                            >
                                Editar
                            </a>

                            <form
                                method="post"
                                class="d-inline"
                                onsubmit="return confirm('Excluir este grupo / ministério? Lideranças vinculadas deixarão de ter esse vínculo.');"
                            >
                                <?= Csrf::field() ?>
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$item['id'] ?>"
                                >
                                <button class="btn btn-sm btn-outline-danger">
                                    Excluir
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($list['total'] > 0): ?>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="small text-secondary">
                Exibindo <?= (int)$list['from'] ?>–<?= (int)$list['to'] ?>
                de <?= (int)$list['total'] ?> itens ·
                <?= GroupService::ADMIN_PER_PAGE ?> por página
            </div>

            <?php if ($list['pages'] > 1): ?>
                <nav aria-label="Paginação">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $list['page'] <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e(groupAdminUrl(
                                    max(1, $list['page'] - 1),
                                    $q,
                                    $type,
                                    $communityId,
                                    $status
                                )) ?>"
                            >
                                Anterior
                            </a>
                        </li>

                        <?php
                        $start = max(1, $list['page'] - 2);
                        $end = min($list['pages'], $list['page'] + 2);
                        ?>

                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <li class="page-item <?= $p === $list['page'] ? 'active' : '' ?>">
                                <a
                                    class="page-link"
                                    href="<?= e(groupAdminUrl(
                                        $p,
                                        $q,
                                        $type,
                                        $communityId,
                                        $status
                                    )) ?>"
                                >
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $list['page'] >= $list['pages'] ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e(groupAdminUrl(
                                    min($list['pages'], $list['page'] + 1),
                                    $q,
                                    $type,
                                    $communityId,
                                    $status
                                )) ?>"
                            >
                                Próxima
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
