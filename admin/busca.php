<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

Auth::requireLogin();

$pdo =
    Database::connection();

if (
    !class_exists(
        'AdminAdvancedSearchService'
    )
) {
    $fallback =
        __DIR__
        . '/../app/Services/AdminAdvancedSearchService.php';

    if (is_file($fallback)) {
        require_once $fallback;
    }
}

if (
    !class_exists(
        'AdminAdvancedSearchService'
    )
) {
    Session::flash(
        'error',
        'O serviço de busca avançada não está disponível.'
    );

    header(
        'Location: '
        . url('admin/index.php')
    );
    exit;
}

$service =
    new AdminAdvancedSearchService(
        $pdo
    );

$modules =
    $service->allowedModules();

$filters = [
    'q' =>
        trim(
            (string)(
                $_GET['q']
                ?? ''
            )
        ),

    'modulo' =>
        trim(
            (string)(
                $_GET['modulo']
                ?? 'todos'
            )
        ),

    'status' =>
        trim(
            (string)(
                $_GET['status']
                ?? ''
            )
        ),

    'data_de' =>
        trim(
            (string)(
                $_GET['data_de']
                ?? ''
            )
        ),

    'data_ate' =>
        trim(
            (string)(
                $_GET['data_ate']
                ?? ''
            )
        ),

    'somente_titulo' =>
        isset(
            $_GET['somente_titulo']
        )
            ? 1
            : 0,

    'ordem' =>
        trim(
            (string)(
                $_GET['ordem']
                ?? 'recentes'
            )
        ),

    'limite' =>
        max(
            5,
            min(
                50,
                (int)(
                    $_GET['limite']
                    ?? 25
                )
            )
        ),
];

$hasSearch =
    $filters['q'] !== ''
    || $filters['modulo'] !== 'todos'
    || $filters['status'] !== ''
    || $filters['data_de'] !== ''
    || $filters['data_ate'] !== '';

$data =
    $hasSearch
        ? $service->search(
            $filters
        )
        : [
            'query' => '',
            'total' => 0,
            'results' => [],
            'sections' => [],
            'truncated' => false,
        ];

$pageTitle =
    'Busca Avançada';

require __DIR__ . '/_header.php';
?>

<div class="d-flex flex-column flex-xl-row align-items-xl-start justify-content-between gap-3 mb-4">
    <div>
        <div class="text-uppercase small text-secondary fw-semibold mb-1">
            Administração
        </div>

        <h1 class="h3 mb-1">
            Busca Avançada
        </h1>

        <p class="text-secondary mb-0">
            Pesquise o conteúdo do painel usando módulo, status, período e ordenação.
        </p>
    </div>

    <button
        class="btn btn-outline-secondary"
        type="button"
        data-admin-global-search-open
    >
        <i class="bi bi-command me-1"></i>
        Busca rápida
        <span class="small ms-1">Ctrl K</span>
    </button>
</div>

<form
    method="get"
    class="card border-0 shadow-sm mb-4"
>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-12">
                <label
                    class="form-label fw-semibold"
                    for="advancedSearchQuery"
                >
                    O que você procura?
                </label>

                <div class="input-group input-group-lg">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>

                    <input
                        id="advancedSearchQuery"
                        type="search"
                        class="form-control"
                        name="q"
                        value="<?= e((string)$filters['q']) ?>"
                        placeholder="Título, resumo, conteúdo, slug, e-mail, nome de arquivo..."
                        autofocus
                    >
                </div>
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Módulo
                </label>

                <select
                    class="form-select"
                    name="modulo"
                >
                    <option value="todos">
                        Todos os módulos permitidos
                    </option>

                    <?php foreach ($modules as $key => $label): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= $filters['modulo'] === $key ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Status
                </label>

                <select
                    class="form-select"
                    name="status"
                >
                    <?php
                    $statuses = [
                        '' => 'Qualquer status',
                        'rascunho' => 'Rascunho',
                        'agendado' => 'Agendado',
                        'publicado' => 'Publicado',
                        'arquivado' => 'Arquivado',
                        'ativo' => 'Usuário ativo',
                        'inativo' => 'Usuário inativo',
                        'ativa' => 'Comunidade ativa',
                        'inativa' => 'Comunidade inativa',
                    ];
                    ?>

                    <?php foreach ($statuses as $value => $label): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $filters['status'] === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Data inicial
                </label>

                <input
                    type="date"
                    class="form-control"
                    name="data_de"
                    value="<?= e((string)$filters['data_de']) ?>"
                >
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Data final
                </label>

                <input
                    type="date"
                    class="form-control"
                    name="data_ate"
                    value="<?= e((string)$filters['data_ate']) ?>"
                >
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Ordenação
                </label>

                <select
                    class="form-select"
                    name="ordem"
                >
                    <option
                        value="recentes"
                        <?= $filters['ordem'] === 'recentes' ? 'selected' : '' ?>
                    >
                        Mais recentes
                    </option>

                    <option
                        value="antigos"
                        <?= $filters['ordem'] === 'antigos' ? 'selected' : '' ?>
                    >
                        Mais antigos
                    </option>

                    <option
                        value="titulo"
                        <?= $filters['ordem'] === 'titulo' ? 'selected' : '' ?>
                    >
                        Título A–Z
                    </option>
                </select>
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label">
                    Máximo por módulo
                </label>

                <select
                    class="form-select"
                    name="limite"
                >
                    <?php foreach ([10, 25, 50] as $limit): ?>
                        <option
                            value="<?= $limit ?>"
                            <?= (int)$filters['limite'] === $limit ? 'selected' : '' ?>
                        >
                            <?= $limit ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-3 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="somente_titulo"
                        id="searchOnlyTitle"
                        value="1"
                        <?= !empty($filters['somente_titulo']) ? 'checked' : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="searchOnlyTitle"
                    >
                        Pesquisar somente no título/nome
                    </label>
                </div>
            </div>

            <div class="col-md-6 col-xl-3 d-flex align-items-end gap-2">
                <button
                    class="btn btn-primary flex-grow-1"
                    type="submit"
                >
                    <i class="bi bi-search me-1"></i>
                    Pesquisar
                </button>

                <a
                    class="btn btn-outline-secondary"
                    href="<?= e(url('admin/busca.php')) ?>"
                    title="Limpar filtros"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </div>
</form>

<?php if (!$hasSearch): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-5 text-center">
            <i class="bi bi-search fs-1 text-secondary"></i>

            <h2 class="h5 mt-3">
                Use os filtros para começar
            </h2>

            <p class="text-secondary mb-0">
                A busca respeita as permissões do seu perfil e consulta somente módulos aos quais você possui acesso.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <strong>
                <?= (int)$data['total'] ?>
                resultado(s)
            </strong>

            <?php if ((string)$filters['q'] !== ''): ?>
                <span class="text-secondary">
                    para “<?= e((string)$filters['q']) ?>”
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($data['sections'])): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($data['sections'] as $section => $count): ?>
                    <span class="badge text-bg-light border">
                        <?= e((string)$section) ?>:
                        <?= (int)$count ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($data['truncated'])): ?>
        <div class="alert alert-info">
            Alguns módulos possuem mais resultados do que o limite escolhido.
            Aumente “Máximo por módulo” ou refine os filtros.
        </div>
    <?php endif; ?>

    <?php if (!$data['results']): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-5 text-center">
                <i class="bi bi-search fs-1 text-secondary"></i>

                <h2 class="h5 mt-3">
                    Nenhum resultado encontrado
                </h2>

                <p class="text-secondary mb-0">
                    Tente remover algum filtro, pesquisar outro termo ou escolher outro módulo.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm overflow-hidden">
            <div class="list-group list-group-flush">
                <?php
                $currentSection = null;
                ?>

                <?php foreach ($data['results'] as $result): ?>
                    <?php if ($currentSection !== (string)$result['section']): ?>
                        <?php
                        $currentSection =
                            (string)$result['section'];
                        ?>

                        <div class="list-group-item bg-body-tertiary py-2">
                            <div class="small text-uppercase text-secondary fw-semibold">
                                <?= e($currentSection) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <a
                        class="list-group-item list-group-item-action p-3 p-lg-4"
                        href="<?= e((string)$result['url']) ?>"
                    >
                        <div class="d-flex gap-3 align-items-start">
                            <span
                                class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:44px;height:44px"
                            >
                                <i class="bi <?= e((string)$result['icon']) ?>"></i>
                            </span>

                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <div class="fw-semibold">
                                        <?= e((string)$result['label']) ?>
                                    </div>

                                    <?php if (!empty($result['status'])): ?>
                                        <span class="badge text-bg-secondary">
                                            <?= e((string)$result['status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($result['subtitle'])): ?>
                                    <div class="small text-secondary mt-1">
                                        <?= e((string)$result['subtitle']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($result['date_sort'])): ?>
                                    <div class="small text-secondary mt-1">
                                        <i class="bi bi-clock me-1"></i>
                                        <?= e(formatDateBr((string)$result['date_sort'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="small text-primary text-nowrap">
                                Abrir
                                <i class="bi bi-chevron-right ms-1"></i>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
