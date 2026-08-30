<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../app/Services/CategoryService.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo = Database::connection();
$error = '';

$defaults = [
    'writing_default_category' => '',
    'writing_default_status' => 'rascunho',
    'writing_excerpt_length' => '180',
    'writing_revision_limit' => '30',
    'writing_require_review' => '1',
];

$s = array_merge(
    $defaults,
    siteConfigAll($pdo)
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (
        [
            'writing_default_category',
            'writing_default_status',
            'writing_excerpt_length',
            'writing_revision_limit',
        ] as $key
    ) {
        if (array_key_exists($key, $_POST)) {
            $s[$key] = trim((string)$_POST[$key]);
        }
    }

    $s['writing_require_review'] =
        isset($_POST['writing_require_review'])
            ? '1'
            : '0';

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $cat =
                $s['writing_default_category'] !== ''
                    ? (int)$s['writing_default_category']
                    : 0;

            if ($cat > 0) {
                $st = $pdo->prepare(
                    'SELECT 1
                     FROM categorias
                     WHERE id=:id'
                );

                $st->execute([
                    'id' => $cat,
                ]);

                if (!$st->fetchColumn()) {
                    throw new RuntimeException(
                        'Categoria padrão inválida.'
                    );
                }
            }

            if (
                !in_array(
                    $s['writing_default_status'],
                    ['rascunho', 'publicado'],
                    true
                )
            ) {
                $s['writing_default_status'] =
                    'rascunho';
            }

            /*
             * Quando revisão obrigatória está ativa, novos conteúdos
             * devem começar como rascunho.
             */
            if ($s['writing_require_review'] === '1') {
                $s['writing_default_status'] =
                    'rascunho';
            }

            $s['writing_excerpt_length'] =
                (string)max(
                    80,
                    min(
                        500,
                        (int)$s['writing_excerpt_length']
                    )
                );

            $s['writing_revision_limit'] =
                (string)max(
                    5,
                    min(
                        100,
                        (int)$s['writing_revision_limit']
                    )
                );

            foreach ($defaults as $key => $_) {
                $type = match ($key) {
                    'writing_excerpt_length',
                    'writing_revision_limit' => 'numero',

                    'writing_require_review' => 'booleano',

                    default => 'texto',
                };

                saveSiteConfig(
                    $pdo,
                    $key,
                    $s[$key],
                    $type
                );
            }

            logAction(
                $pdo,
                'configuracoes.escrita',
                'configuracoes'
            );

            Session::flash(
                'success',
                'Configurações de escrita atualizadas.'
            );

            header(
                'Location: '
                . url('admin/configuracoes/escrita.php')
            );

            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$cats = CategoryService::tree($pdo);

$pageTitle = 'Configurações de escrita';

require __DIR__ . '/../_header.php';
?>

<h1 class="h3 mb-1">Escrita</h1>

<p class="text-secondary mb-4">
    Padrões usados ao criar e revisar notícias.
</p>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label">
                    Categoria padrão
                </label>

                <select
                    class="form-select"
                    name="writing_default_category"
                >
                    <option value="">
                        Sem categoria
                    </option>

                    <?php foreach ($cats as $c): ?>
                        <option
                            value="<?= (int)$c['id'] ?>"
                            <?= (string)$s['writing_default_category'] === (string)$c['id'] ? 'selected' : '' ?>
                        >
                            <?= e(CategoryService::optionLabel($c)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <label class="form-label">
                    Status padrão
                </label>

                <select
                    class="form-select"
                    name="writing_default_status"
                    <?= $s['writing_require_review'] === '1' ? 'disabled' : '' ?>
                >
                    <option
                        value="rascunho"
                        <?= $s['writing_default_status'] === 'rascunho' ? 'selected' : '' ?>
                    >
                        Rascunho
                    </option>

                    <option
                        value="publicado"
                        <?= $s['writing_default_status'] === 'publicado' ? 'selected' : '' ?>
                    >
                        Publicado
                    </option>
                </select>

                <?php if ($s['writing_require_review'] === '1'): ?>
                    <input
                        type="hidden"
                        name="writing_default_status"
                        value="rascunho"
                    >

                    <div class="form-text">
                        Revisão obrigatória ativa: novos posts começam como rascunho.
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-3">
                <label class="form-label">
                    Tamanho do resumo automático
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="80"
                        max="500"
                        name="writing_excerpt_length"
                        value="<?= e($s['writing_excerpt_length']) ?>"
                    >

                    <span class="input-group-text">
                        caracteres
                    </span>
                </div>
            </div>

            <div class="col-lg-3">
                <label class="form-label">
                    Revisões por conteúdo
                </label>

                <div class="input-group">
                    <input
                        class="form-control"
                        type="number"
                        min="5"
                        max="100"
                        name="writing_revision_limit"
                        value="<?= e($s['writing_revision_limit']) ?>"
                    >

                    <span class="input-group-text">
                        versões
                    </span>
                </div>

                <div class="form-text">
                    As revisões mais antigas são removidas automaticamente acima deste limite.
                </div>
            </div>

            <div class="col-lg-9">
                <div class="border rounded p-3 h-100">
                    <div class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="writing_require_review"
                            id="writingRequireReview"
                            <?= $s['writing_require_review'] === '1' ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label fw-semibold"
                            for="writingRequireReview"
                        >
                            Exigir aprovação editorial antes de publicar novas notícias
                        </label>
                    </div>

                    <div class="form-text mt-2">
                        Com esta opção ativa, uma notícia nova precisa passar por:
                        <strong>Rascunho → Em revisão → Aprovado → Publicado</strong>.
                        Perfis sem permissão de publicação não conseguem colocar
                        novos conteúdos diretamente no ar.
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="form-text">
                    A revisão obrigatória atua sobre conteúdos ainda não publicados.
                    A edição de uma notícia que já está no ar continua funcionando
                    como nas versões anteriores.
                </div>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    Salvar escrita
                </button>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../_footer.php'; ?>
