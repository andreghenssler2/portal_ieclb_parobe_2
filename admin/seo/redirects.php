<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('seo.gerenciar');

$pdo = Database::connection();

SeoSharingService::ensureSchema($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');

            if ($action === 'save') {
                $id = max(0, (int)($_POST['id'] ?? 0));

                $savedId = SeoSharingService::saveRedirect(
                    $pdo,
                    (string)($_POST['source_path'] ?? ''),
                    (string)($_POST['target_path'] ?? ''),
                    (int)($_POST['http_code'] ?? 301),
                    isset($_POST['ativo']),
                    $id > 0 ? $id : null
                );

                logAction(
                    $pdo,
                    'seo.redirect.salvar',
                    'seo_redirects',
                    $savedId,
                    'Redirect SEO salvo.'
                );

                Session::flash(
                    'success',
                    'Redirect salvo.'
                );
            } elseif ($action === 'delete') {
                $id = max(0, (int)($_POST['id'] ?? 0));

                if (!SeoSharingService::deleteRedirect($pdo, $id)) {
                    throw new RuntimeException(
                        'Redirect não encontrado.'
                    );
                }

                logAction(
                    $pdo,
                    'seo.redirect.excluir',
                    'seo_redirects',
                    $id,
                    'Redirect SEO excluído.',
                    'warning'
                );

                Session::flash(
                    'success',
                    'Redirect excluído.'
                );
            } else {
                throw new RuntimeException(
                    'Ação inválida.'
                );
            }

            header(
                'Location: '
                . url('admin/seo/redirects.php')
            );

            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$items = SeoSharingService::redirects(
    $pdo,
    300
);

$pageTitle = 'SEO - Redirects';
require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
    <div>
        <div class="small text-uppercase text-secondary fw-semibold mb-1">
            SEO v1.1.14
        </div>

        <h1 class="h3 mb-1">
            Redirects de conteúdos antigos
        </h1>

        <p class="text-secondary mb-0">
            Encaminhe URLs antigas para a URL canônica atual sem perder acessos ou referências externas.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/seo/social.php')) ?>"
        >
            Social
        </a>

        <a
            class="btn btn-outline-secondary"
            target="_blank"
            href="<?= e(url('sitemap.xml')) ?>"
        >
            Sitemap
        </a>
    </div>
</div>

<?php if ($msg = Session::flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="alert alert-info">
    <strong>Somente caminhos internos.</strong>
    Exemplo:
    <code>/noticia/endereco-antigo</code>
    →
    <code>/noticia/endereco-novo</code>.
    O Portal não permite usar esta tela para redirecionar visitantes para outro domínio.
</div>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold py-3">
        Novo redirect
    </div>

    <div class="card-body p-4">
        <form method="post" class="row g-3 align-items-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">

            <div class="col-lg-4">
                <label class="form-label">
                    URL/caminho antigo
                </label>

                <input
                    class="form-control"
                    name="source_path"
                    maxlength="500"
                    required
                    placeholder="/noticia/url-antiga"
                >
            </div>

            <div class="col-lg-4">
                <label class="form-label">
                    URL/caminho atual
                </label>

                <input
                    class="form-control"
                    name="target_path"
                    maxlength="500"
                    required
                    placeholder="/noticia/url-atual"
                >
            </div>

            <div class="col-sm-4 col-lg-2">
                <label class="form-label">
                    Tipo
                </label>

                <select class="form-select" name="http_code">
                    <option value="301">301 permanente</option>
                    <option value="302">302 temporário</option>
                </select>
            </div>

            <div class="col-sm-4 col-lg-1">
                <div class="form-check form-switch mb-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="ativo"
                        id="redirectActive"
                        checked
                    >
                    <label class="form-check-label" for="redirectActive">
                        Ativo
                    </label>
                </div>
            </div>

            <div class="col-sm-4 col-lg-1">
                <button class="btn btn-primary w-100">
                    Salvar
                </button>
            </div>
        </form>
    </div>
</section>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-body d-flex justify-content-between align-items-center gap-3 py-3">
        <strong>Redirects cadastrados</strong>
        <span class="badge text-bg-secondary">
            <?= count($items) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Origem</th>
                    <th>Destino</th>
                    <th>Código</th>
                    <th>Status</th>
                    <th>Acessos</th>
                    <th>Último acesso</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="7" class="text-center text-secondary py-5">
                            Nenhum redirect cadastrado.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <code><?= e((string)$item['source_path']) ?></code>
                        </td>

                        <td>
                            <a
                                href="<?= e(url(ltrim((string)$item['target_path'], '/'))) ?>"
                                target="_blank"
                            >
                                <code><?= e((string)$item['target_path']) ?></code>
                            </a>
                        </td>

                        <td>
                            <?= (int)$item['http_code'] ?>
                        </td>

                        <td>
                            <span class="badge <?= (int)$item['ativo'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= (int)$item['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>

                        <td>
                            <?= (int)$item['hits'] ?>
                        </td>

                        <td class="text-nowrap">
                            <?= !empty($item['last_hit_at'])
                                ? e(formatDateBr((string)$item['last_hit_at']))
                                : '—' ?>
                        </td>

                        <td class="text-end">
                            <form
                                method="post"
                                onsubmit="return confirm('Excluir este redirect?');"
                            >
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

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
</section>

<?php require __DIR__ . '/../_footer.php'; ?>
