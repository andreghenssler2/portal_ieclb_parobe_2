<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('midias.gerenciar');

$pdo = Database::connection();

$error = '';

/**
 * Monta URL preservando os filtros atuais.
 *
 * @param array<string,mixed> $override
 */
function mediaAdminUrl(array $override = []): string
{
    $params = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'tipo' => (string)($_GET['tipo'] ?? 'todos'),
        'ordem' => (string)($_GET['ordem'] ?? 'recentes'),
        'visualizacao' => (string)($_GET['visualizacao'] ?? 'grade'),
        'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
    ];

    foreach ($override as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }

        $params[$key] = $value;
    }

    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }

    if (($params['tipo'] ?? 'todos') === 'todos') {
        unset($params['tipo']);
    }

    if (($params['ordem'] ?? 'recentes') === 'recentes') {
        unset($params['ordem']);
    }

    if (($params['visualizacao'] ?? 'grade') === 'grade') {
        unset($params['visualizacao']);
    }

    if ((int)($params['pagina'] ?? 1) <= 1) {
        unset($params['pagina']);
    }

    return url(
        'admin/midias/index.php'
        . (
            $params
                ? '?' . http_build_query($params)
                : ''
        )
    );
}

/**
 * Retorno seguro após ações POST.
 */
function mediaAdminReturnUrl(): string
{
    $return = trim((string)($_POST['return'] ?? ''));

    if (
        $return !== ''
        && !preg_match('~^(?:https?:)?//~i', $return)
        && !str_contains($return, '..')
        && str_starts_with($return, 'admin/midias/index.php')
    ) {
        return url($return);
    }

    return url('admin/midias/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'upload'));

        if ($action === 'delete') {
            $id = max(0, (int)($_POST['id'] ?? 0));

            if ($id > 0) {
                try {
                    $media = MediaService::find($pdo, $id);

                    if (!$media) {
                        throw new RuntimeException(
                            'A mídia selecionada não existe mais.'
                        );
                    }

                    if (!MediaService::delete($pdo, $id)) {
                        throw new RuntimeException(
                            'Não foi possível excluir a mídia.'
                        );
                    }

                    logAction(
                        $pdo,
                        'midia.excluir',
                        'midias',
                        $id,
                        (string)(
                            $media['nome_original']
                            ?? ''
                        )
                    );

                    Session::flash(
                        'success',
                        'Mídia excluída.'
                    );
                } catch (Throwable $e) {
                    Session::flash(
                        'error',
                        'Não foi possível excluir: '
                        . $e->getMessage()
                    );
                }
            }

            header(
                'Location: '
                . mediaAdminReturnUrl()
            );

            exit;
        }

        if ($action === 'bulk_delete') {
            $ids = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            (array)($_POST['ids'] ?? [])
                        ),
                        static fn(int $id): bool =>
                            $id > 0
                    )
                )
            );

            if (!$ids) {
                Session::flash(
                    'error',
                    'Selecione pelo menos uma mídia.'
                );
            } else {
                $deleted = 0;
                $failed = [];

                foreach ($ids as $id) {
                    try {
                        $media = MediaService::find(
                            $pdo,
                            $id
                        );

                        if (!$media) {
                            continue;
                        }

                        if (!MediaService::delete($pdo, $id)) {
                            throw new RuntimeException(
                                'exclusão não concluída'
                            );
                        }

                        logAction(
                            $pdo,
                            'midia.excluir',
                            'midias',
                            $id,
                            'Exclusão em massa: '
                            . (string)(
                                $media['nome_original']
                                ?? ''
                            )
                        );

                        $deleted++;
                    } catch (Throwable $e) {
                        $failed[] =
                            '#'
                            . $id
                            . ': '
                            . $e->getMessage();
                    }
                }

                if ($deleted > 0) {
                    Session::flash(
                        'success',
                        $deleted
                        . ' mídia(s) excluída(s).'
                    );
                }

                if ($failed) {
                    Session::flash(
                        'error',
                        'Alguns arquivos não puderam ser excluídos: '
                        . implode(
                            ' | ',
                            array_slice(
                                $failed,
                                0,
                                5
                            )
                        )
                    );
                }
            }

            header(
                'Location: '
                . mediaAdminReturnUrl()
            );

            exit;
        }

        if ($action === 'upload') {
            $files = $_FILES['arquivos'] ?? null;

            if (
                !$files
                || !is_array(
                    $files['name']
                    ?? null
                )
            ) {
                $error =
                    'Selecione pelo menos um arquivo.';
            } else {
                $success = 0;
                $errors = [];

                foreach (
                    $files['name']
                    as $i => $name
                ) {
                    if (
                        (
                            $files['error'][$i]
                            ?? UPLOAD_ERR_NO_FILE
                        )
                        === UPLOAD_ERR_NO_FILE
                    ) {
                        continue;
                    }

                    $file = [
                        'name' => $name,
                        'type' =>
                            $files['type'][$i]
                            ?? '',
                        'tmp_name' =>
                            $files['tmp_name'][$i]
                            ?? '',
                        'error' =>
                            $files['error'][$i]
                            ?? UPLOAD_ERR_NO_FILE,
                        'size' =>
                            $files['size'][$i]
                            ?? 0,
                    ];

                    try {
                        $media =
                            MediaService::upload(
                                $pdo,
                                $file,
                                (int)Auth::id()
                            );

                        logAction(
                            $pdo,
                            'midia.upload',
                            'midias',
                            (int)$media['id'],
                            (string)$media['nome_original']
                        );

                        $success++;
                    } catch (Throwable $e) {
                        $errors[] =
                            (string)$name
                            . ': '
                            . $e->getMessage();
                    }
                }

                if ($success > 0) {
                    Session::flash(
                        'success',
                        $success
                        . ' arquivo(s) enviado(s) com sucesso.'
                    );
                }

                if (!$errors) {
                    header(
                        'Location: '
                        . mediaAdminReturnUrl()
                    );

                    exit;
                }

                $error =
                    implode(
                        ' ',
                        $errors
                    );
            }
        }
    }
}

$q =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

$filter =
    strtolower(
        trim(
            (string)(
                $_GET['tipo']
                ?? 'todos'
            )
        )
    );

if (
    !in_array(
        $filter,
        [
            'todos',
            'imagens',
            'documentos',
        ],
        true
    )
) {
    $filter = 'todos';
}

$order =
    strtolower(
        trim(
            (string)(
                $_GET['ordem']
                ?? 'recentes'
            )
        )
    );

$orderOptions = [
    'recentes' => 'm.id DESC',
    'antigos' => 'm.id ASC',
    'nome_az' =>
        'COALESCE(NULLIF(m.titulo, \'\'), m.nome_original) ASC, m.id DESC',
    'nome_za' =>
        'COALESCE(NULLIF(m.titulo, \'\'), m.nome_original) DESC, m.id DESC',
    'maiores' =>
        'm.tamanho DESC, m.id DESC',
    'menores' =>
        'm.tamanho ASC, m.id DESC',
];

if (!isset($orderOptions[$order])) {
    $order = 'recentes';
}

$view =
    strtolower(
        trim(
            (string)(
                $_GET['visualizacao']
                ?? 'grade'
            )
        )
    );

if (
    !in_array(
        $view,
        [
            'grade',
            'lista',
        ],
        true
    )
) {
    $view = 'grade';
}

$page =
    max(
        1,
        (int)(
            $_GET['pagina']
            ?? 1
        )
    );

$perPage = 24;

$where = [];
$params = [];

if ($q !== '') {
    $where[] =
        "(
            m.nome_original LIKE :q1
            OR COALESCE(m.titulo,'') LIKE :q2
            OR COALESCE(m.alt_text,'') LIKE :q3
            OR COALESCE(m.extensao,'') LIKE :q4
        )";

    $like = '%' . $q . '%';

    $params += [
        'q1' => $like,
        'q2' => $like,
        'q3' => $like,
        'q4' => $like,
    ];
}

if ($filter === 'imagens') {
    $where[] =
        "m.mime_type LIKE 'image/%'";
} elseif ($filter === 'documentos') {
    $where[] =
        "m.mime_type NOT LIKE 'image/%'";
}

$whereSql =
    $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

$countSql =
    "SELECT COUNT(*)
     FROM midias m
     {$whereSql}";

$countStmt =
    $pdo->prepare($countSql);

$countStmt->execute($params);

$total =
    (int)$countStmt->fetchColumn();

$totalPages =
    max(
        1,
        (int)ceil(
            $total / $perPage
        )
    );

if (
    $total > 0
    && $page > $totalPages
) {
    header(
        'Location: '
        . mediaAdminUrl([
            'pagina' => $totalPages,
        ])
    );

    exit;
}

$offset =
    ($page - 1)
    * $perPage;

$listSql =
    "SELECT
        m.*,
        u.nome AS usuario_nome
     FROM midias m
     LEFT JOIN usuarios u
        ON u.id=m.usuario_id
     {$whereSql}
     ORDER BY {$orderOptions[$order]}
     LIMIT {$perPage}
     OFFSET {$offset}";

$listStmt =
    $pdo->prepare($listSql);

$listStmt->execute($params);

$midias =
    $listStmt->fetchAll(
        PDO::FETCH_ASSOC
    ) ?: [];

$counts = [
    'todos' => 0,
    'imagens' => 0,
    'documentos' => 0,
];

try {
    $countRows =
        $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN mime_type LIKE 'image/%'
                        THEN 1
                        ELSE 0
                    END
                ) AS imagens,
                SUM(
                    CASE
                        WHEN mime_type NOT LIKE 'image/%'
                        THEN 1
                        ELSE 0
                    END
                ) AS documentos
             FROM midias"
        )->fetch(PDO::FETCH_ASSOC)
        ?: [];

    $counts = [
        'todos' =>
            (int)($countRows['total'] ?? 0),
        'imagens' =>
            (int)($countRows['imagens'] ?? 0),
        'documentos' =>
            (int)($countRows['documentos'] ?? 0),
    ];
} catch (Throwable $ignored) {
}

$firstItem =
    $total > 0
        ? $offset + 1
        : 0;

$lastItem =
    min(
        $total,
        $offset
        + count($midias)
    );

$returnQuery = [
    'q' => $q,
    'tipo' => $filter,
    'ordem' => $order,
    'visualizacao' => $view,
    'pagina' => $page,
];

$returnQuery = array_filter(
    $returnQuery,
    static fn(mixed $value): bool =>
        $value !== ''
        && $value !== null
);

$returnRoute =
    'admin/midias/index.php'
    . (
        $returnQuery
            ? '?' . http_build_query($returnQuery)
            : ''
    );

$pageTitle =
    'Biblioteca de Mídia';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            Mídia
        </div>

        <h1 class="h3 mb-1">
            Biblioteca de Mídia
        </h1>

        <p class="text-secondary mb-0">
            Pesquise, filtre e gerencie imagens e documentos do Portal.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary <?= $view === 'grade' ? 'active' : '' ?>"
            href="<?= e(mediaAdminUrl([
                'visualizacao' => 'grade',
                'pagina' => 1,
            ])) ?>"
            title="Visualização em grade"
        >
            <i class="bi bi-grid-3x3-gap"></i>
        </a>

        <a
            class="btn btn-outline-secondary <?= $view === 'lista' ? 'active' : '' ?>"
            href="<?= e(mediaAdminUrl([
                'visualizacao' => 'lista',
                'pagina' => 1,
            ])) ?>"
            title="Visualização em lista"
        >
            <i class="bi bi-list-ul"></i>
        </a>

        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/midias/otimizar.php')) ?>"
        >
            <i class="bi bi-magic me-1"></i>
            Otimizar imagens
        </a>

        <a
            class="btn btn-primary"
            href="#adicionar-novo"
        >
            <i class="bi bi-cloud-arrow-up me-1"></i>
            Enviar arquivos
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div
    class="card border-0 shadow-sm mb-4"
    id="adicionar-novo"
>
    <div class="card-body p-4">
        <form
            method="post"
            enctype="multipart/form-data"
        >
            <?= Csrf::field() ?>

            <input
                type="hidden"
                name="action"
                value="upload"
            >

            <input
                type="hidden"
                name="return"
                value="<?= e($returnRoute) ?>"
            >

            <div class="row g-3 align-items-end">
                <div class="col-lg-9">
                    <label class="form-label">
                        Enviar arquivos
                    </label>

                    <input
                        class="form-control"
                        type="file"
                        name="arquivos[]"
                        multiple
                        required
                        accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"
                    >

                    <div class="form-text">
                        Imagens, PDF e documentos.
                        Máximo
                        <?= e(
                            formatBytes(
                                mediaUploadMaxSize($pdo)
                            )
                        ) ?>
                        por arquivo.
                    </div>
                </div>

                <div class="col-lg-3">
                    <button class="btn btn-primary w-100">
                        Enviar para biblioteca
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form
            method="get"
            class="row g-3 align-items-end"
        >
            <div class="col-lg-6">
                <label class="form-label small">
                    Pesquisar
                </label>

                <input
                    type="search"
                    class="form-control"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="Título, nome do arquivo, texto alternativo ou extensão"
                >
            </div>

            <div class="col-sm-6 col-lg-3">
                <label class="form-label small">
                    Tipo
                </label>

                <select
                    class="form-select"
                    name="tipo"
                >
                    <option
                        value="todos"
                        <?= $filter === 'todos' ? 'selected' : '' ?>
                    >
                        Todos
                    </option>

                    <option
                        value="imagens"
                        <?= $filter === 'imagens' ? 'selected' : '' ?>
                    >
                        Imagens
                    </option>

                    <option
                        value="documentos"
                        <?= $filter === 'documentos' ? 'selected' : '' ?>
                    >
                        Documentos
                    </option>
                </select>
            </div>

            <div class="col-sm-6 col-lg-3">
                <label class="form-label small">
                    Ordenar
                </label>

                <select
                    class="form-select"
                    name="ordem"
                >
                    <?php foreach (
                        [
                            'recentes' => 'Mais recentes',
                            'antigos' => 'Mais antigos',
                            'nome_az' => 'Nome A–Z',
                            'nome_za' => 'Nome Z–A',
                            'maiores' => 'Maiores arquivos',
                            'menores' => 'Menores arquivos',
                        ]
                        as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $order === $value ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input
                type="hidden"
                name="visualizacao"
                value="<?= e($view) ?>"
            >

            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-dark">
                    <i class="bi bi-search me-1"></i>
                    Aplicar filtros
                </button>

                <?php if (
                    $q !== ''
                    || $filter !== 'todos'
                    || $order !== 'recentes'
                ): ?>
                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e(
                            url(
                                'admin/midias/index.php'
                                . (
                                    $view !== 'grade'
                                        ? '?visualizacao=' . rawurlencode($view)
                                        : ''
                                )
                            )
                        ) ?>"
                    >
                        Limpar filtros
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <?php foreach (
            [
                'todos' => 'Todos',
                'imagens' => 'Imagens',
                'documentos' => 'Documentos',
            ]
            as $value => $label
        ): ?>
            <a
                class="btn btn-sm <?= $filter === $value ? 'btn-dark' : 'btn-outline-secondary' ?>"
                href="<?= e(mediaAdminUrl([
                    'tipo' => $value,
                    'pagina' => 1,
                ])) ?>"
            >
                <?= e($label) ?>

                <span class="badge <?= $filter === $value ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1">
                    <?= (int)$counts[$value] ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="small text-secondary">
        <?php if ($total > 0): ?>
            Exibindo
            <?= (int)$firstItem ?>
            –
            <?= (int)$lastItem ?>
            de
            <?= (int)$total ?>
        <?php else: ?>
            Nenhuma mídia encontrada
        <?php endif; ?>
    </div>
</div>

<form
    method="post"
    id="mediaBulkForm"
>
    <?= Csrf::field() ?>

    <input
        type="hidden"
        name="action"
        value="bulk_delete"
    >

    <input
        type="hidden"
        name="return"
        value="<?= e($returnRoute) ?>"
    >

    <?php if ($midias): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <label class="form-check mb-0">
                <input
                    class="form-check-input"
                    type="checkbox"
                    id="mediaSelectAll"
                >

                <span class="form-check-label">
                    Selecionar esta página
                </span>
            </label>

            <button
                type="submit"
                class="btn btn-sm btn-outline-danger"
                id="mediaBulkDeleteButton"
                disabled
                onclick="return confirm('Excluir permanentemente as mídias selecionadas? Arquivos em uso podem não ser excluídos.');"
            >
                <i class="bi bi-trash me-1"></i>
                Excluir selecionadas
            </button>
        </div>
    <?php endif; ?>

    <?php if (!$midias): ?>
        <div class="alert alert-light border">
            <?php if (
                $q !== ''
                || $filter !== 'todos'
            ): ?>
                Nenhuma mídia corresponde aos filtros informados.
            <?php else: ?>
                Nenhuma mídia cadastrada.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (
        $midias
        && $view === 'grade'
    ): ?>
        <div class="row g-3">
            <?php foreach ($midias as $m): ?>
                <?php
                $isImage =
                    str_starts_with(
                        (string)$m['mime_type'],
                        'image/'
                    );

                $title =
                    trim(
                        (string)(
                            $m['titulo']
                            ?? ''
                        )
                    );

                if ($title === '') {
                    $title =
                        (string)$m['nome_original'];
                }
                ?>

                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <div class="card h-100 border-0 shadow-sm media-card position-relative">
                        <div class="position-absolute top-0 start-0 m-2 z-2">
                            <input
                                class="form-check-input media-select"
                                type="checkbox"
                                name="ids[]"
                                value="<?= (int)$m['id'] ?>"
                                aria-label="Selecionar <?= e($title) ?>"
                            >
                        </div>

                        <?php if ($isImage): ?>
                            <img
                                src="<?= e(
                                    class_exists('ImageOptimizationService')
                                        ? ImageOptimizationService::bestUrl(
                                            $pdo,
                                            (int)$m['id'],
                                            ImageOptimizationService::VARIANT_THUMB,
                                            (string)$m['caminho']
                                        )
                                        : mediaUrl((string)$m['caminho'])
                                ) ?>"
                                class="card-img-top media-thumb"
                                alt="<?= e(
                                    (string)(
                                        $m['alt_text']
                                        ?: $title
                                    )
                                ) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="media-file-placeholder">
                                <strong>
                                    .<?= e(
                                        strtoupper(
                                            (string)$m['extensao']
                                        )
                                    ) ?>
                                </strong>
                            </div>
                        <?php endif; ?>

                        <div class="card-body">
                            <div
                                class="fw-semibold text-truncate"
                                title="<?= e($title) ?>"
                            >
                                <?= e($title) ?>
                            </div>

                            <div
                                class="small text-secondary text-truncate mt-1"
                                title="<?= e((string)$m['nome_original']) ?>"
                            >
                                <?= e((string)$m['nome_original']) ?>
                            </div>

                            <div class="small text-secondary mt-2">
                                <?= e(
                                    formatBytes(
                                        (int)$m['tamanho']
                                    )
                                ) ?>

                                <?php if (
                                    !empty($m['largura'])
                                    && !empty($m['altura'])
                                ): ?>
                                    ·
                                    <?= (int)$m['largura'] ?>
                                    ×
                                    <?= (int)$m['altura'] ?>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($m['usuario_nome'])): ?>
                                <div class="small text-secondary mt-1">
                                    por
                                    <?= e((string)$m['usuario_nome']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer bg-white border-0 d-flex gap-2">
                            <a
                                class="btn btn-sm btn-outline-secondary flex-grow-1"
                                href="<?= e(
                                    url(
                                        'admin/midias/editar.php?id='
                                        . (int)$m['id']
                                    )
                                ) ?>"
                            >
                                Detalhes
                            </a>

                            <a
                                class="btn btn-sm btn-outline-primary"
                                href="<?= e(
                                    mediaUrl(
                                        (string)$m['caminho']
                                    )
                                ) ?>"
                                target="_blank"
                            >
                                Abrir
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($midias): ?>
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:44px"></th>
                            <th>Arquivo</th>
                            <th>Tipo</th>
                            <th>Tamanho</th>
                            <th>Dimensões</th>
                            <th>Usuário</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($midias as $m): ?>
                            <?php
                            $isImage =
                                str_starts_with(
                                    (string)$m['mime_type'],
                                    'image/'
                                );

                            $title =
                                trim(
                                    (string)(
                                        $m['titulo']
                                        ?? ''
                                    )
                                );

                            if ($title === '') {
                                $title =
                                    (string)$m['nome_original'];
                            }
                            ?>

                            <tr>
                                <td>
                                    <input
                                        class="form-check-input media-select"
                                        type="checkbox"
                                        name="ids[]"
                                        value="<?= (int)$m['id'] ?>"
                                        aria-label="Selecionar <?= e($title) ?>"
                                    >
                                </td>

                                <td style="min-width:260px">
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if ($isImage): ?>
                                            <img
                                                src="<?= e(
                                                    class_exists('ImageOptimizationService')
                                                        ? ImageOptimizationService::bestUrl(
                                                            $pdo,
                                                            (int)$m['id'],
                                                            ImageOptimizationService::VARIANT_THUMB,
                                                            (string)$m['caminho']
                                                        )
                                                        : mediaUrl((string)$m['caminho'])
                                                ) ?>"
                                                alt=""
                                                loading="lazy"
                                                style="width:56px;height:56px;object-fit:cover;border-radius:.5rem"
                                            >
                                        <?php else: ?>
                                            <span
                                                class="rounded bg-body-tertiary border d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                                style="width:56px;height:56px"
                                            >
                                                <strong class="small">
                                                    <?= e(
                                                        strtoupper(
                                                            (string)$m['extensao']
                                                        )
                                                    ) ?>
                                                </strong>
                                            </span>
                                        <?php endif; ?>

                                        <div class="min-w-0">
                                            <div class="fw-semibold text-truncate">
                                                <?= e($title) ?>
                                            </div>

                                            <div class="small text-secondary text-truncate">
                                                <?= e((string)$m['nome_original']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-nowrap">
                                    <?= e((string)$m['mime_type']) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e(
                                        formatBytes(
                                            (int)$m['tamanho']
                                        )
                                    ) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?php if (
                                        !empty($m['largura'])
                                        && !empty($m['altura'])
                                    ): ?>
                                        <?= (int)$m['largura'] ?>
                                        ×
                                        <?= (int)$m['altura'] ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(
                                        (string)(
                                            $m['usuario_nome']
                                            ?: '—'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end text-nowrap">
                                    <a
                                        class="btn btn-sm btn-outline-secondary"
                                        href="<?= e(
                                            url(
                                                'admin/midias/editar.php?id='
                                                . (int)$m['id']
                                            )
                                        ) ?>"
                                    >
                                        Detalhes
                                    </a>

                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="<?= e(mediaUrl((string)$m['caminho'])) ?>"
                                        target="_blank"
                                    >
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</form>

<?php if ($totalPages > 1): ?>
    <nav
        class="mt-4"
        aria-label="Paginação da Biblioteca de Mídia"
    >
        <ul class="pagination flex-wrap mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a
                    class="page-link"
                    href="<?= e(
                        $page > 1
                            ? mediaAdminUrl([
                                'pagina' => $page - 1,
                            ])
                            : '#'
                    ) ?>"
                >
                    Anterior
                </a>
            </li>

            <?php
            $start =
                max(
                    1,
                    $page - 2
                );

            $end =
                min(
                    $totalPages,
                    $page + 2
                );

            if ($start > 1):
            ?>
                <li class="page-item">
                    <a
                        class="page-link"
                        href="<?= e(mediaAdminUrl([
                            'pagina' => 1,
                        ])) ?>"
                    >
                        1
                    </a>
                </li>

                <?php if ($start > 2): ?>
                    <li class="page-item disabled">
                        <span class="page-link">
                            …
                        </span>
                    </li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a
                        class="page-link"
                        href="<?= e(mediaAdminUrl([
                            'pagina' => $p,
                        ])) ?>"
                    >
                        <?= (int)$p ?>
                    </a>
                </li>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled">
                        <span class="page-link">
                            …
                        </span>
                    </li>
                <?php endif; ?>

                <li class="page-item">
                    <a
                        class="page-link"
                        href="<?= e(mediaAdminUrl([
                            'pagina' => $totalPages,
                        ])) ?>"
                    >
                        <?= (int)$totalPages ?>
                    </a>
                </li>
            <?php endif; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a
                    class="page-link"
                    href="<?= e(
                        $page < $totalPages
                            ? mediaAdminUrl([
                                'pagina' => $page + 1,
                            ])
                            : '#'
                    ) ?>"
                >
                    Próxima
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll =
        document.getElementById('mediaSelectAll');

    const bulkButton =
        document.getElementById('mediaBulkDeleteButton');

    const items = Array.from(
        document.querySelectorAll('.media-select')
    );

    function updateBulk() {
        const checked =
            items.filter(function (item) {
                return item.checked;
            });

        if (bulkButton) {
            bulkButton.disabled =
                checked.length === 0;

            bulkButton.innerHTML =
                '<i class="bi bi-trash me-1"></i>'
                + (
                    checked.length > 0
                        ? 'Excluir selecionadas (' + checked.length + ')'
                        : 'Excluir selecionadas'
                );
        }

        if (selectAll) {
            selectAll.checked =
                items.length > 0
                && checked.length === items.length;

            selectAll.indeterminate =
                checked.length > 0
                && checked.length < items.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener(
            'change',
            function () {
                items.forEach(function (item) {
                    item.checked =
                        selectAll.checked;
                });

                updateBulk();
            }
        );
    }

    items.forEach(function (item) {
        item.addEventListener(
            'change',
            updateBulk
        );
    });

    updateBulk();
});
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
