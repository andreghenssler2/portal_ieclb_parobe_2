<?php

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('configuracoes.gerenciar');

$pdo = Database::connection();
$error = '';

$defaults = [
    'permalink_noticia'    => 'noticia',
    'permalink_pagina'     => 'pagina',
    'permalink_evento'     => 'evento',
    'permalink_galeria'    => 'galeria',
    'permalink_formulario' => 'formulario',
];

$s = array_merge($defaults, siteConfigAll($pdo));

/*
 * Último prefixo normal usado pelas Notícias.
 * Exemplo: "post". Ele é preservado para redirecionar
 * /post/minha-noticia depois que Notícias passam para /minha-noticia.
 */
$noticiaPrefix = trim(
    siteConfig(
        $pdo,
        'permalink_noticia_prefix',
        $s['permalink_noticia'] !== '__root__'
            ? $s['permalink_noticia']
            : 'noticia'
    )
);

if (
    $noticiaPrefix === ''
    || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $noticiaPrefix)
) {
    $noticiaPrefix = 'noticia';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Csrf::validate($_POST['_token'] ?? null)) {

        $error = 'Token de segurança inválido.';

    } else {

        try {

            $reserved = [
                'admin',
                'theme',
                'public',
                'uploads',
                'sitemap',
                'sitemap.xml',
                'robots',
                'robots.txt',
            ];

            /*
             * Notícias
             */
            $noticiaPrefix = trim(
                strtolower(
                    (string)($_POST['permalink_noticia_prefix'] ?? 'noticia')
                )
            );

            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $noticiaPrefix)) {
                throw new RuntimeException('Prefixo inválido em notícias.');
            }

            if (in_array($noticiaPrefix, $reserved, true)) {
                throw new RuntimeException(
                    'O prefixo "' . $noticiaPrefix . '" é reservado.'
                );
            }

            $noticiaNaRaiz =
                isset($_POST['permalink_noticia_root'])
                && $_POST['permalink_noticia_root'] === '1';

            $s['permalink_noticia'] =
                $noticiaNaRaiz
                    ? '__root__'
                    : $noticiaPrefix;

            /*
             * Páginas
             */
            $paginaValue = trim(
                strtolower(
                    (string)($_POST['permalink_pagina'] ?? 'pagina')
                )
            );

            if ($paginaValue === '__root__') {

                $s['permalink_pagina'] = '__root__';

            } else {

                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $paginaValue)) {
                    throw new RuntimeException('Prefixo inválido em páginas.');
                }

                if (in_array($paginaValue, $reserved, true)) {
                    throw new RuntimeException(
                        'O prefixo "' . $paginaValue . '" é reservado.'
                    );
                }

                $s['permalink_pagina'] = $paginaValue;
            }

            /*
             * Eventos / Galerias / Formulários
             */
            foreach (
                [
                    'permalink_evento',
                    'permalink_galeria',
                    'permalink_formulario',
                ] as $key
            ) {
                $default = $defaults[$key];

                $value = trim(
                    strtolower(
                        (string)($_POST[$key] ?? $default)
                    )
                );

                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
                    throw new RuntimeException(
                        'Prefixo inválido em '
                        . str_replace('permalink_', '', $key)
                        . '.'
                    );
                }

                if (in_array($value, $reserved, true)) {
                    throw new RuntimeException(
                        'O prefixo "' . $value . '" é reservado.'
                    );
                }

                $s[$key] = $value;
            }

            /*
             * Evita ambiguidade:
             * /historia não pode ser simultaneamente uma página e uma notícia.
             */
            if (
                $s['permalink_noticia'] === '__root__'
                && $s['permalink_pagina'] === '__root__'
            ) {
                throw new RuntimeException(
                    'Notícias e Páginas não podem usar /{slug} '
                    . 'na raiz ao mesmo tempo.'
                );
            }

            $prefixes = array_filter([
                $s['permalink_noticia'] === '__root__'
                    ? null
                    : $s['permalink_noticia'],
                $s['permalink_evento'],
                $s['permalink_galeria'],
                $s['permalink_formulario'],
                $s['permalink_pagina'] === '__root__'
                    ? null
                    : $s['permalink_pagina'],
            ]);

            if (count($prefixes) !== count(array_unique($prefixes))) {
                throw new RuntimeException(
                    'Os prefixos precisam ser diferentes entre si.'
                );
            }

            foreach ($defaults as $key => $_) {
                saveSiteConfig(
                    $pdo,
                    $key,
                    $s[$key],
                    'texto'
                );
            }

            saveSiteConfig(
                $pdo,
                'permalink_noticia_prefix',
                $noticiaPrefix,
                'texto'
            );

            logAction(
                $pdo,
                'configuracoes.permalinks',
                'configuracoes'
            );

            Session::flash(
                'success',
                'Links permanentes atualizados. '
                . 'URLs antigas serão redirecionadas para o novo formato.'
            );

            header(
                'Location: '
                . url('admin/configuracoes/links-permanentes.php')
            );

            exit;

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Links permanentes';

require __DIR__ . '/../_header.php';

?>

<h1 class="h3 mb-1">Links Permanentes</h1>

<p class="text-secondary mb-4">
    Defina os prefixos usados nas URLs públicas.
    A slug do conteúdo continua sendo gerada ao salvar.
</p>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm">

    <div class="card-body p-4">

        <?= Csrf::field() ?>

        <div class="row align-items-center mb-3">

            <label class="col-md-3 col-form-label">
                Notícias
            </label>

            <div class="col-md-5">

                <div class="input-group">
                    <span class="input-group-text">/</span>

                    <input
                        type="text"
                        class="form-control"
                        name="permalink_noticia_prefix"
                        value="<?= e($noticiaPrefix) ?>"
                        placeholder="post"
                        required
                    >
                </div>

                <div class="form-check mt-2">

                    <input
                        class="form-check-input"
                        type="checkbox"
                        value="1"
                        id="permalink_noticia_root"
                        name="permalink_noticia_root"
                        <?= $s['permalink_noticia'] === '__root__'
                            ? 'checked'
                            : '' ?>
                    >

                    <label
                        class="form-check-label"
                        for="permalink_noticia_root"
                    >
                        Usar /{slug} na raiz
                    </label>

                </div>

            </div>

            <div class="col-md-4 small text-secondary">

                <?php if ($s['permalink_noticia'] === '__root__'): ?>
                    /minha-noticia
                <?php else: ?>
                    /<?= e($noticiaPrefix) ?>/minha-noticia
                <?php endif; ?>

            </div>

        </div>

        <?php foreach (
            [
                'permalink_evento' => ['Eventos', 'culto-especial'],
                'permalink_galeria' => ['Galerias', 'fotos-do-culto'],
                'permalink_formulario' => ['Formulários', 'contato'],
            ] as $key => [$label, $slug]
        ): ?>

            <div class="row align-items-center mb-3">

                <label class="col-md-3 col-form-label">
                    <?= e($label) ?>
                </label>

                <div class="col-md-5">
                    <input
                        class="form-control"
                        name="<?= e($key) ?>"
                        value="<?= e($s[$key]) ?>"
                        required
                    >
                </div>

                <div class="col-md-4 small text-secondary">
                    /<?= e($s[$key]) ?>/<?= e($slug) ?>
                </div>

            </div>

        <?php endforeach; ?>

        <div class="row align-items-center mb-3">

            <label class="col-md-3 col-form-label">
                Páginas
            </label>

            <div class="col-md-5">

                <select
                    class="form-select"
                    name="permalink_pagina"
                >

                    <option
                        value="pagina"
                        <?= $s['permalink_pagina'] === 'pagina'
                            ? 'selected'
                            : '' ?>
                    >
                        /pagina/{slug}
                    </option>

                    <option
                        value="__root__"
                        <?= $s['permalink_pagina'] === '__root__'
                            ? 'selected'
                            : '' ?>
                    >
                        /{slug} (na raiz)
                    </option>

                </select>

            </div>

            <div class="col-md-4 small text-secondary">

                Ex.:
                <?= e(
                    $s['permalink_pagina'] === '__root__'
                        ? '/quem-somos'
                        : '/pagina/quem-somos'
                ) ?>

            </div>

        </div>

        <div class="alert alert-warning mb-4">

            <strong>Atenção:</strong>

            Notícias e Páginas não podem usar
            <code>/{slug}</code> na raiz ao mesmo tempo.

            Rotas internas como
            <code>admin</code>,
            <code>agenda</code>,
            <code>galerias</code>,
            <code>comunidades</code> e
            <code>documentos</code>
            continuam tendo prioridade.

        </div>

        <button class="btn btn-primary">
            Salvar links permanentes
        </button>

    </div>

</form>

<?php require __DIR__ . '/../_footer.php'; ?>