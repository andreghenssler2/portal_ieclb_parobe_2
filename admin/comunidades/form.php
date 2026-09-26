<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('comunidades.gerenciar');

$pdo =
    Database::connection();

CommunityProfileService::ensureSchema(
    $pdo
);

$id =
    isset($_GET['id'])
        ? max(
            0,
            (int)$_GET['id']
        )
        : 0;

$item = [
    'nome' => '',
    'descricao' => '',
    'endereco' => '',
    'cidade' => '',
    'uf' => 'RS',
    'ativa' => 1,
    'ordem' => 0,
];

if ($id > 0) {
    $stmt =
        $pdo->prepare(
            'SELECT *
             FROM comunidades
             WHERE id=:id
             LIMIT 1'
        );

    $stmt->execute([
        'id' => $id,
    ]);

    $found =
        $stmt->fetch();

    if (!$found) {
        http_response_code(404);
        exit('Comunidade não encontrada.');
    }

    $item =
        $found;
}

$profile =
    CommunityProfileService::load(
        $pdo,
        $id
    );

$images =
    CommunityProfileService::editorImages(
        $pdo
    );

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (
        [
            'nome',
            'descricao',
            'endereco',
            'cidade',
            'uf',
            'ordem',
        ]
        as $field
    ) {
        if (array_key_exists($field, $_POST)) {
            $item[$field] =
                $_POST[$field];
        }
    }

    $item['ativa'] =
        isset($_POST['ativa'])
            ? 1
            : 0;

    $profile =
        CommunityProfileService::formState(
            $_POST,
            $profile
        );

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error =
            'Token de segurança inválido.';
    } else {
        $nome =
            trim(
                (string)($_POST['nome'] ?? '')
            );

        if ($nome === '') {
            $error =
                'Informe o nome da comunidade.';
        } else {
            try {
                $slug =
                    uniqueSlug(
                        $pdo,
                        'comunidades',
                        $nome,
                        $id > 0
                            ? $id
                            : null
                    );

                $data = [
                    'nome' =>
                        $nome,
                    'slug' =>
                        $slug,
                    'descricao' =>
                        trim(
                            (string)($_POST['descricao'] ?? '')
                        )
                        ?: null,
                    'endereco' =>
                        trim(
                            (string)($_POST['endereco'] ?? '')
                        )
                        ?: null,
                    'cidade' =>
                        trim(
                            (string)($_POST['cidade'] ?? '')
                        )
                        ?: null,
                    'uf' =>
                        strtoupper(
                            substr(
                                trim(
                                    (string)($_POST['uf'] ?? 'RS')
                                ),
                                0,
                                2
                            )
                        ),
                    'ativa' =>
                        isset($_POST['ativa'])
                            ? 1
                            : 0,
                    'ordem' =>
                        (int)($_POST['ordem'] ?? 0),
                ];

                $pdo->beginTransaction();

                try {
                    if ($id > 0) {
                        $data['id'] =
                            $id;

                        $stmt =
                            $pdo->prepare(
                                'UPDATE comunidades
                                 SET
                                    nome=:nome,
                                    slug=:slug,
                                    descricao=:descricao,
                                    endereco=:endereco,
                                    cidade=:cidade,
                                    uf=:uf,
                                    ativa=:ativa,
                                    ordem=:ordem
                                 WHERE id=:id'
                            );
                    } else {
                        $stmt =
                            $pdo->prepare(
                                'INSERT INTO comunidades
                                    (
                                        nome,
                                        slug,
                                        descricao,
                                        endereco,
                                        cidade,
                                        uf,
                                        ativa,
                                        ordem
                                    )
                                 VALUES
                                    (
                                        :nome,
                                        :slug,
                                        :descricao,
                                        :endereco,
                                        :cidade,
                                        :uf,
                                        :ativa,
                                        :ordem
                                    )'
                            );
                    }

                    $stmt->execute(
                        $data
                    );

                    $savedId =
                        $id > 0
                            ? $id
                            : (int)$pdo->lastInsertId();

                    CommunityProfileService::save(
                        $pdo,
                        $savedId,
                        $_POST
                    );

                    $pdo->commit();
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $txe;
                }

                logAction(
                    $pdo,
                    $id > 0
                        ? 'comunidade.editar'
                        : 'comunidade.criar',
                    'comunidades',
                    $savedId,
                    $nome
                );

                Session::flash(
                    'success',
                    $id > 0
                        ? 'Comunidade atualizada.'
                        : 'Comunidade criada.'
                );

                header(
                    'Location: '
                    . url(
                        'admin/comunidades/form.php?id='
                        . $savedId
                    )
                );

                exit;
            } catch (Throwable $e) {
                $error =
                    $e->getMessage();
            }
        }
    }
}

$pageTitle =
    $id > 0
        ? 'Editar comunidade'
        : 'Nova comunidade';

$selectedPhotos =
    array_map(
        'intval',
        (array)($profile['foto_ids'] ?? [])
    );

$coverId =
    max(
        0,
        (int)($profile['foto_capa_id'] ?? 0)
    );

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            <?= e($pageTitle) ?>
        </h1>

        <p class="text-secondary mb-0">
            Informações públicas, contatos, horários, fotos e localização da comunidade.
        </p>
    </div>

    <?php if ($id > 0 && !empty($item['slug'])): ?>
        <a
            class="btn btn-outline-primary"
            target="_blank"
            rel="noopener"
            href="<?= e(
                url(
                    'comunidade.php?slug='
                    . rawurlencode(
                        (string)$item['slug']
                    )
                )
            ) ?>"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            Ver página pública
        </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post">
    <?= Csrf::field() ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body fw-semibold">
                    Dados da comunidade
                </div>

                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">
                                Nome
                            </label>

                            <input
                                class="form-control"
                                name="nome"
                                value="<?= e((string)$item['nome']) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Ordem
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="ordem"
                                value="<?= (int)$item['ordem'] ?>"
                            >
                        </div>

                        <div class="col-12">
                            <label class="form-label">
                                Descrição
                            </label>

                            <textarea
                                class="form-control"
                                name="descricao"
                                rows="5"
                            ><?= e((string)($item['descricao'] ?? '')) ?></textarea>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">
                                Endereço
                            </label>

                            <input
                                class="form-control"
                                name="endereco"
                                value="<?= e((string)($item['endereco'] ?? '')) ?>"
                                placeholder="Rua, número, bairro"
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">
                                Cidade
                            </label>

                            <input
                                class="form-control"
                                name="cidade"
                                value="<?= e((string)($item['cidade'] ?? '')) ?>"
                            >
                        </div>

                        <div class="col-md-1">
                            <label class="form-label">
                                UF
                            </label>

                            <input
                                class="form-control"
                                name="uf"
                                maxlength="2"
                                value="<?= e((string)($item['uf'] ?? 'RS')) ?>"
                            >
                        </div>

                        <div class="col-12">
                            <div class="form-text">
                                O mapa público é montado automaticamente usando Endereço + Cidade + UF.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body fw-semibold">
                    Cultos e responsável
                </div>

                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">
                                Horários de cultos
                            </label>

                            <textarea
                                class="form-control"
                                name="horarios_cultos"
                                rows="5"
                                placeholder="Domingo, 9h&#10;1º e 3º domingo, Santa Ceia..."
                            ><?= e((string)($profile['horarios_cultos'] ?? '')) ?></textarea>

                            <div class="form-text">
                                Use uma linha para cada horário ou observação.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Pastor(a) / responsável
                            </label>

                            <input
                                class="form-control"
                                name="pastor_nome"
                                value="<?= e((string)($profile['pastor_nome'] ?? '')) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                E-mail
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                name="email"
                                value="<?= e((string)($profile['email'] ?? '')) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Telefone
                            </label>

                            <input
                                class="form-control"
                                name="telefone"
                                value="<?= e((string)($profile['telefone'] ?? '')) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                WhatsApp
                            </label>

                            <input
                                class="form-control"
                                name="whatsapp"
                                value="<?= e((string)($profile['whatsapp'] ?? '')) ?>"
                                placeholder="(51) 99999-9999"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body fw-semibold">
                    Fotos
                </div>

                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label">
                            Foto de capa
                        </label>

                        <select
                            class="form-select"
                            name="foto_capa_id"
                        >
                            <option value="">
                                Sem foto de capa
                            </option>

                            <?php foreach ($images as $image): ?>
                                <?php
                                $imageLabel =
                                    trim(
                                        (string)($image['titulo'] ?? '')
                                    )
                                    ?: (string)($image['nome_original'] ?? 'Imagem');
                                ?>
                                <option
                                    value="<?= (int)$image['id'] ?>"
                                    <?= $coverId === (int)$image['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($imageLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label class="form-label">
                        Galeria da comunidade
                    </label>

                    <div
                        class="row g-2 border rounded p-2"
                        style="max-height:420px;overflow:auto"
                    >
                        <?php if (!$images): ?>
                            <div class="col-12 text-secondary">
                                Nenhuma imagem disponível na Biblioteca de Mídia.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($images as $image): ?>
                            <?php
                            $imageUrl =
                                mediaUrl(
                                    (string)$image['caminho']
                                );

                            $imageLabel =
                                trim(
                                    (string)($image['titulo'] ?? '')
                                )
                                ?: (string)($image['nome_original'] ?? 'Imagem');
                            ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="border rounded p-2 d-block h-100">
                                    <img
                                        src="<?= e($imageUrl) ?>"
                                        alt="<?= e($imageLabel) ?>"
                                        class="w-100 rounded mb-2"
                                        loading="lazy"
                                        style="aspect-ratio:4/3;object-fit:cover"
                                    >

                                    <span class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="foto_ids[]"
                                            value="<?= (int)$image['id'] ?>"
                                            <?= in_array((int)$image['id'], $selectedPhotos, true) ? 'checked' : '' ?>
                                        >

                                        <span class="form-check-label small">
                                            <?= e($imageLabel) ?>
                                        </span>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-text mt-2">
                        Até 30 fotos. Novas imagens podem ser enviadas pela Biblioteca de Mídia.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body fw-semibold">
                    Redes e links
                </div>

                <div class="card-body p-4">
                    <?php foreach (
                        [
                            'instagram' => 'Instagram',
                            'facebook' => 'Facebook',
                            'youtube' => 'YouTube',
                            'site' => 'Site',
                        ]
                        as $field => $label
                    ): ?>
                        <div class="mb-3">
                            <label class="form-label">
                                <?= e($label) ?>
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="<?= e($field) ?>"
                                value="<?= e((string)($profile[$field] ?? '')) ?>"
                                placeholder="https://..."
                            >
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body fw-semibold">
                    Publicação
                </div>

                <div class="card-body p-4">
                    <div class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="ativa"
                            id="ativa"
                            <?= !empty($item['ativa']) ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label"
                            for="ativa"
                        >
                            Comunidade ativa
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    <i class="bi bi-check2 me-1"></i>
                    Salvar comunidade
                </button>

                <a
                    class="btn btn-outline-secondary"
                    href="<?= e(url('admin/comunidades/index.php')) ?>"
                >
                    Voltar
                </a>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_footer.php'; ?>
