<?php

declare(strict_types=1);

const TARGET_VERSION = '0.41.0';
const MINIMUM_VERSION = '0.40.0';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

$root = __DIR__;
$backupDir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');

function backupFile(string $path): void
{
    global $root,$backupDir;

    if (!is_file($path)) {
        return;
    }

    $relative = ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target))
        && !mkdir(dirname($target),0755,true)
        && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }

    if (!copy($path,$target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeChanged(string $path,string $source,string $label): void
{
    $current = is_file($path) ? (string)file_get_contents($path) : '';

    if ($current === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    backupFile($path);

    if (file_put_contents($path,$source,LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    out('[OK] ' . $label . ' atualizado.');
}

function lintPhp(string $path): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command,$lines,$code);

    if ($code !== 0) {
        throw new RuntimeException(
            basename($path) . " não passou no php -l:\n" . implode(PHP_EOL,$lines)
        );
    }
}

function patchBootstrap(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source,'NewsEngagementService.php')) {
        out('[OK] bootstrap.php já carrega NewsEngagementService.');
        return;
    }

    $anchors = [
        "require_once __DIR__ . '/app/Services/NewsAnalyticsService.php';",
        "require_once __DIR__ . '/app/Services/SearchService.php';",
        "require_once __DIR__ . '/app/Services/HomeService.php';",
    ];

    foreach ($anchors as $anchor) {
        $position = strpos($source,$anchor);

        if ($position === false) {
            continue;
        }

        $insert = $anchor . "\nrequire_once __DIR__ . '/app/Services/NewsEngagementService.php';";
        $source = substr_replace($source,$insert,$position,strlen($anchor));

        writeChanged($path,$source,'bootstrap.php');
        lintPhp($path);
        return;
    }

    throw new RuntimeException('Não foi possível integrar NewsEngagementService no bootstrap.php.');
}

function patchHomeService(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source,"['posts', 'eventos', 'paginas', 'mais_lidas']")) {
        $old = "['posts', 'eventos', 'paginas']";
        if (!str_contains($source,$old)) {
            throw new RuntimeException('Não foi possível ampliar as origens do HomeService.');
        }
        $source = str_replace(
            $old,
            "['posts', 'eventos', 'paginas', 'mais_lidas']",
            $source
        );
    }

    if (!str_contains($source,"if (\$source === 'mais_lidas') {\n            return NewsEngagementService::popular")) {
        $old = <<<'PHP'
        $categoryId = (int)($section['categoria_id'] ?? 0);
        return $this->fetchItems($source, $limit, $categoryId > 0 ? $categoryId : null);
PHP;
        $new = <<<'PHP'
        $categoryId = (int)($section['categoria_id'] ?? 0);

        if ($source === 'mais_lidas') {
            return NewsEngagementService::popular($this->pdo, $limit, '30');
        }

        return $this->fetchItems($source, $limit, $categoryId > 0 ? $categoryId : null);
PHP;
        if (!str_contains($source,$old)) {
            throw new RuntimeException('Não foi possível integrar Mais Lidas em itemsForSection().');
        }
        $source = str_replace($old,$new,$source);
    }

    if (!str_contains($source,"if (\$source === 'mais_lidas' && !empty(\$row['imagem_capa_midia']))")) {
        $anchor = <<<'PHP'
    public function itemImage(array $row, string $source): string
    {
        $table = $source;
PHP;
        $replacement = <<<'PHP'
    public function itemImage(array $row, string $source): string
    {
        if ($source === 'mais_lidas' && !empty($row['imagem_capa_midia'])) {
            return $this->normalizePublicUrl((string)$row['imagem_capa_midia']);
        }
        if ($source === 'mais_lidas') {
            $source = 'posts';
        }

        $table = $source;
PHP;
        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível adaptar itemImage().');
        }
        $source = str_replace($anchor,$replacement,$source);
    }

    if (!str_contains($source,"if (\$source === 'mais_lidas') \$source = 'posts';\n        \$keys = \$source === 'eventos'")) {
        $anchor = <<<'PHP'
    public function itemDate(array $row, string $source): ?DateTimeImmutable
    {
        $keys = $source === 'eventos'
PHP;
        $replacement = <<<'PHP'
    public function itemDate(array $row, string $source): ?DateTimeImmutable
    {
        if ($source === 'mais_lidas') $source = 'posts';
        $keys = $source === 'eventos'
PHP;
        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível adaptar itemDate().');
        }
        $source = str_replace($anchor,$replacement,$source);
    }

    if (!str_contains($source,"public function itemUrl(array \$row, string \$source): string\n    {\n        if (\$source === 'mais_lidas') \$source = 'posts';")) {
        $anchor = <<<'PHP'
    public function itemUrl(array $row, string $source): string
    {
PHP;
        $replacement = <<<'PHP'
    public function itemUrl(array $row, string $source): string
    {
        if ($source === 'mais_lidas') $source = 'posts';
PHP;
        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível adaptar itemUrl().');
        }
        $position = strpos($source,$anchor);
        $source = substr_replace($source,$replacement,$position,strlen($anchor));
    }

    if ($source === $original) {
        out('[OK] HomeService.php já estava atualizado.');
        return;
    }

    writeChanged($path,$source,'app/Services/HomeService.php');
    lintPhp($path);
}

function patchHomeAdmin(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source,"'mais_lidas' => 'Mais Lidas (30 dias)'")) {
        $old = <<<'PHP'
<?= e(match ((string)$section['origem']) { 'eventos' => 'Eventos', 'paginas' => 'Páginas', default => 'Posts / Notícias' }) ?>
PHP;
        $new = <<<'PHP'
<?= e(match ((string)$section['origem']) { 'eventos' => 'Eventos', 'paginas' => 'Páginas', 'mais_lidas' => 'Mais Lidas (30 dias)', default => 'Posts / Notícias' }) ?>
PHP;
        if (!str_contains($source,$old)) {
            throw new RuntimeException('Não foi possível atualizar o rótulo da origem na Home.');
        }
        $source = str_replace($old,$new,$source);
    }

    if (!str_contains($source,'<option value="mais_lidas"')) {
        $anchor = <<<'PHP'
                                <option value="paginas" <?= $form['origem']==='paginas'?'selected':'' ?>>Páginas</option>
PHP;
        $replacement = $anchor . <<<'PHP'

                                <option value="mais_lidas" <?= $form['origem']==='mais_lidas'?'selected':'' ?>>Mais Lidas (30 dias)</option>
PHP;
        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível adicionar Mais Lidas ao seletor da Home.');
        }
        $source = str_replace($anchor,$replacement,$source);
    }

    if ($source === $original) {
        out('[OK] admin/aparencia/home.php já estava atualizado.');
        return;
    }

    writeChanged($path,$source,'admin/aparencia/home.php');
    lintPhp($path);
}

function patchNoticia(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source,'$relatedPosts = NewsEngagementService::related')) {
        $anchor = "\$metaOgType = 'article';\n";
        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível localizar os metadados de noticia.php.');
        }

        $source = str_replace(
            $anchor,
            $anchor . "\$relatedPosts = NewsEngagementService::related(\$pdo, \$post, 4);\n",
            $source
        );
    }

    if (!str_contains($source,'>Leia também</h2>')) {
        $anchor = <<<'PHP'
<?php require themeFile($pdo, 'footer.php'); ?>
PHP;

        $related = <<<'PHP'
<?php if ($relatedPosts): ?>
<section class="container pb-5">
    <div class="border-top pt-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <h2 class="h3 fw-bold mb-0">Leia também</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('mais-lidas')) ?>">Ver mais lidas</a>
        </div>

        <div class="row g-4">
            <?php foreach ($relatedPosts as $related): ?>
                <div class="col-md-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm overflow-hidden">
                        <?php if (!empty($related['imagem_capa_midia'])): ?>
                            <a href="<?= e(contentUrl('noticia', (string)$related['slug'])) ?>">
                                <img
                                    src="<?= e(mediaUrl((string)$related['imagem_capa_midia'])) ?>"
                                    alt="<?= e((string)($related['imagem_capa_alt'] ?: $related['titulo'])) ?>"
                                    class="card-img-top"
                                    style="height:170px;object-fit:cover"
                                >
                            </a>
                        <?php endif; ?>

                        <div class="card-body">
                            <div class="small text-secondary mb-2">
                                <?= e($related['comunidade_nome'] ?: 'Paroquial') ?>
                                ·
                                <?= e(formatDateOnlyBr((string)($related['publicado_em'] ?: $related['created_at']))) ?>
                            </div>

                            <h3 class="h5 card-title">
                                <a
                                    class="text-reset text-decoration-none"
                                    href="<?= e(contentUrl('noticia', (string)$related['slug'])) ?>"
                                >
                                    <?= e($related['titulo']) ?>
                                </a>
                            </h3>

                            <?php if (!empty($related['resumo'])): ?>
                                <p class="card-text text-secondary small mb-0">
                                    <?= e(portalExcerpt((string)$related['resumo'], 130)) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

PHP;

        if (!str_contains($source,$anchor)) {
            throw new RuntimeException('Não foi possível inserir Leia também em noticia.php.');
        }

        $source = str_replace($anchor,$related . $anchor,$source);
    }

    if ($source === $original) {
        out('[OK] noticia.php já possui Leia também.');
        return;
    }

    writeChanged($path,$source,'noticia.php');
    lintPhp($path);
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $original = $source;
    $pattern = "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern,$source)) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $declare = 'declare(strict_types=1);';
        $position = strpos($source,$declare);

        if ($position !== false) {
            $at = $position + strlen($declare);
            $source = substr($source,0,$at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source,$at);
        } else {
            $php = strpos($source,'<?php');
            if ($php === false) {
                throw new RuntimeException('config/config.php inválido.');
            }

            $at = $php + 5;
            $source = substr($source,0,$at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source,$at);
        }
    }

    if ($source !== $original) {
        writeChanged($config,$source,'config/config.php');
    } else {
        out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
    }
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Notícias Relacionadas + Mais Lidas na Home');
out(str_repeat('-',76));

$config = $root . '/config/config.php';

if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}

if (!is_file($root . '/app/Services/NewsEngagementService.php')) {
    fail('app/Services/NewsEngagementService.php não encontrado.');
}

require_once $config;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current,MINIMUM_VERSION,'<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MINIMUM_VERSION . ' ou superior.');
}

try {
    lintPhp($root . '/app/Services/NewsEngagementService.php');
    out('[OK] NewsEngagementService.php validado.');

    patchBootstrap($root . '/bootstrap.php');
    patchHomeService($root . '/app/Services/HomeService.php');
    patchHomeAdmin($root . '/admin/aparencia/home.php');
    patchNoticia($root . '/noticia.php');
    updateVersion($config);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-',76));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('Notícias: Leia também habilitado.');
    out('Home: origem Mais Lidas (30 dias) disponível em Aparência > Página Inicial.');

    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\','/',$backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
