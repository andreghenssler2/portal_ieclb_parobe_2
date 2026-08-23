<?php

declare(strict_types=1);

const TARGET_VERSION = '0.38.3';
const MINIMUM_VERSION = '0.38.2';

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
    global $root, $backupDir;

    if (!is_file($path)) {
        return;
    }

    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }

    if (!copy($path, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeChanged(string $path, string $content, string $label): void
{
    $old = is_file($path) ? (string)file_get_contents($path) : '';

    if ($old === $content) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    backupFile($path);

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    out('[OK] ' . $label . ' atualizado.');
}

function patchNoticia(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('noticia.php não encontrado.');
    }

    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, 'imagem_capa_largura')) {
        $source = str_replace(
            'm.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt',
            'm.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt, m.largura AS imagem_capa_largura, m.altura AS imagem_capa_altura, m.mime_type AS imagem_capa_mime',
            $source
        );
    }

    if (!str_contains($source, '$metaImageAlt =') && !str_contains($source, '$metaImageAlt=')) {
        $pattern = '~\$metaTitle=\$post\[\'titulo\'\]\.\' - IECLB Parobé\';\R'
            . '\$metaDescription=\$post\[\'resumo\'\] \?: strip_tags\(mb_substr\(\$post\[\'conteudo\'\],0,150\)\);\R'
            . 'require __DIR__\.\'/theme/ieclb/header\.php\';\R'
            . '\$cover = \$post\[\'imagem_capa_midia\'\] \?: \$post\[\'imagem_capa\'\];~';

        $replacement = <<<'PHP'
$cover = $post['imagem_capa_midia'] ?: ($post['imagem_capa'] ?? '');
$metaTitle = trim((string)($post['seo_titulo'] ?? '')) ?: $post['titulo'];
$metaDescription = trim((string)($post['seo_descricao'] ?? '')) ?: ($post['resumo'] ?: trim(strip_tags(mb_substr((string)$post['conteudo'], 0, 160))));
$metaNoindex = (int)($post['seo_noindex'] ?? 0) === 1;
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$metaImageAlt = trim((string)($post['imagem_capa_alt'] ?? '')) ?: (string)$post['titulo'];
$metaImageWidth = (int)($post['imagem_capa_largura'] ?? 0);
$metaImageHeight = (int)($post['imagem_capa_altura'] ?? 0);
$metaImageType = trim((string)($post['imagem_capa_mime'] ?? ''));
$canonicalUrl = contentUrl('noticia', (string)$post['slug']);
$metaOgType = 'article';
require themeFile($pdo, 'header.php');
PHP;

        $patched = preg_replace($pattern, $replacement, $source, 1, $count);
        if (!is_string($patched) || $count !== 1) {
            throw new RuntimeException('Não foi possível integrar a imagem social em noticia.php.');
        }
        $source = $patched;
    }

    $source = str_replace(
        "require __DIR__.'/theme/ieclb/footer.php';",
        "require themeFile(\$pdo, 'footer.php');",
        $source
    );

    if ($source === $original) {
        out('[OK] noticia.php já usa a capa no compartilhamento.');
        return;
    }

    writeChanged($path, $source, 'noticia.php');
}

function enrichContentMeta(string $path, string $label, string $fieldPrefix, string $titleExpression, bool $article = false): void
{
    if (!is_file($path)) {
        throw new RuntimeException($label . ' não encontrado.');
    }

    $source = (string)file_get_contents($path);
    $original = $source;

    $baseAlias = $fieldPrefix . '_caminho';
    if ($fieldPrefix === 'imagem_capa') {
        $baseAlias = 'imagem_capa_midia';
    }

    if ($fieldPrefix === 'imagem_capa') {
        $search = 'm.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt';
        $replace = 'm.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt, m.largura AS imagem_capa_largura, m.altura AS imagem_capa_altura, m.mime_type AS imagem_capa_mime';
    } else {
        $search = 'm.caminho AS capa_caminho, m.alt_text AS capa_alt';
        $replace = 'm.caminho AS capa_caminho, m.alt_text AS capa_alt, m.largura AS capa_largura, m.altura AS capa_altura, m.mime_type AS capa_mime';
    }

    if (!str_contains($source, $fieldPrefix . '_largura') && str_contains($source, $search)) {
        $source = str_replace($search, $replace, $source);
    }

    if (!str_contains($source, '$metaImageAlt')) {
        if ($fieldPrefix === 'imagem_capa') {
            $anchorPattern = '~(\$metaImage\s*=\s*[^;]+;\R)~';
            $metaBlock = <<<'PHP'
$metaImageAlt = trim((string)($CONTENT['imagem_capa_alt'] ?? '')) ?: (string)TITLE_EXPR;
$metaImageWidth = (int)($CONTENT['imagem_capa_largura'] ?? 0);
$metaImageHeight = (int)($CONTENT['imagem_capa_altura'] ?? 0);
$metaImageType = trim((string)($CONTENT['imagem_capa_mime'] ?? ''));
PHP;
        } else {
            $anchorPattern = '~(\$metaImage\s*=\s*[^;]+;\R)~';
            $metaBlock = <<<'PHP'
$metaImageAlt = trim((string)($CONTENT['capa_alt'] ?? '')) ?: (string)TITLE_EXPR;
$metaImageWidth = (int)($CONTENT['capa_largura'] ?? 0);
$metaImageHeight = (int)($CONTENT['capa_altura'] ?? 0);
$metaImageType = trim((string)($CONTENT['capa_mime'] ?? ''));
PHP;
        }

        if (str_contains($path, 'pagina.php')) {
            $contentVar = '$pagina';
        } elseif (str_contains($path, 'evento.php')) {
            $contentVar = '$evento';
        } else {
            $contentVar = '$galeria';
        }

        $metaBlock = str_replace('$CONTENT', $contentVar, $metaBlock);
        $metaBlock = str_replace('TITLE_EXPR', $titleExpression, $metaBlock);
        if ($article) {
            $metaBlock .= "\$metaOgType = 'article';\n";
        }

        $patched = preg_replace($anchorPattern, '$1' . $metaBlock, $source, 1, $count);
        if (!is_string($patched) || $count !== 1) {
            throw new RuntimeException('Não foi possível enriquecer os metadados em ' . $label . '.');
        }
        $source = $patched;
    }

    if ($source === $original) {
        out('[OK] ' . $label . ' já usa metadados completos de compartilhamento.');
        return;
    }

    writeChanged($path, $source, $label);
}

function patchThemeHeader(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('theme/ieclb/header.php não encontrado.');
    }

    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, '$resolvedImageAlt =')) {
        $old = <<<'PHP'
$resolvedImage = trim((string)($metaImage ?? ''));
if ($resolvedImage === '' && $ogMedia) {
    $resolvedImage = mediaUrl((string)$ogMedia['caminho']);
}
PHP;

        $new = <<<'PHP'
$resolvedImage = trim((string)($metaImage ?? ''));
$resolvedImageAlt = trim((string)($metaImageAlt ?? ''));
$resolvedImageWidth = max(0, (int)($metaImageWidth ?? 0));
$resolvedImageHeight = max(0, (int)($metaImageHeight ?? 0));
$resolvedImageType = trim((string)($metaImageType ?? ''));

if ($resolvedImage !== '' && !preg_match('#^https?://#i', $resolvedImage)) {
    $resolvedImage = mediaUrl($resolvedImage);
}

if ($resolvedImage === '' && $ogMedia) {
    $resolvedImage = mediaUrl((string)$ogMedia['caminho']);
    $resolvedImageAlt = trim((string)($ogMedia['alt_text'] ?? $ogMedia['titulo'] ?? $siteName));
    $resolvedImageWidth = max(0, (int)($ogMedia['largura'] ?? 0));
    $resolvedImageHeight = max(0, (int)($ogMedia['altura'] ?? 0));
    $resolvedImageType = trim((string)($ogMedia['mime_type'] ?? ''));
}

if ($resolvedImageAlt === '') {
    $resolvedImageAlt = $socialTitle;
}
PHP;

        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível localizar a resolução de imagem social no header do tema.');
        }

        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, 'og:image:width')) {
        $old = <<<'PHP'
    <?php if ($resolvedImage !== ''): ?><meta property="og:image" content="<?= e($resolvedImage) ?>"><?php endif; ?>
PHP;

        $new = <<<'PHP'
    <?php if ($resolvedImage !== ''): ?>
    <meta property="og:image" content="<?= e($resolvedImage) ?>">
    <?php if (str_starts_with(strtolower($resolvedImage), 'https://')): ?><meta property="og:image:secure_url" content="<?= e($resolvedImage) ?>"><?php endif; ?>
    <?php if ($resolvedImageType !== ''): ?><meta property="og:image:type" content="<?= e($resolvedImageType) ?>"><?php endif; ?>
    <?php if ($resolvedImageWidth > 0): ?><meta property="og:image:width" content="<?= (int)$resolvedImageWidth ?>"><?php endif; ?>
    <?php if ($resolvedImageHeight > 0): ?><meta property="og:image:height" content="<?= (int)$resolvedImageHeight ?>"><?php endif; ?>
    <meta property="og:image:alt" content="<?= e($resolvedImageAlt) ?>">
    <?php endif; ?>
PHP;

        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível ampliar og:image no header do tema.');
        }

        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, 'twitter:image:alt')) {
        $old = <<<'PHP'
    <?php if ($resolvedImage !== ''): ?><meta name="twitter:image" content="<?= e($resolvedImage) ?>"><?php endif; ?>
PHP;

        $new = <<<'PHP'
    <?php if ($resolvedImage !== ''): ?>
    <meta name="twitter:image" content="<?= e($resolvedImage) ?>">
    <meta name="twitter:image:alt" content="<?= e($resolvedImageAlt) ?>">
    <?php endif; ?>
PHP;

        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível ampliar twitter:image no header do tema.');
        }

        $source = str_replace($old, $new, $source);
    }

    if ($source === $original) {
        out('[OK] theme/ieclb/header.php já possui metadados sociais completos.');
        return;
    }

    writeChanged($path, $source, 'theme/ieclb/header.php');
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
    $original = $source;
    $pattern = "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern, $source)) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $declare = 'declare(strict_types=1);';
        $pos = strpos($source, $declare);

        if ($pos !== false) {
            $at = $pos + strlen($declare);
            $source = substr($source, 0, $at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source, $at);
        } else {
            $php = strpos($source, '<?php');
            if ($php === false) {
                throw new RuntimeException('config/config.php inválido.');
            }
            $at = $php + 5;
            $source = substr($source, 0, $at)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source, $at);
        }
    }

    if ($source !== $original) {
        writeChanged($config, $source, 'config/config.php');
    } else {
        out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
    }
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Imagem de capa no compartilhamento');
out(str_repeat('-', 76));

$config = $root . '/config/config.php';

if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}

require_once $config;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current, MINIMUM_VERSION, '<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MINIMUM_VERSION . ' ou superior.');
}

try {
    patchNoticia($root . '/noticia.php');

    enrichContentMeta(
        $root . '/pagina.php',
        'pagina.php',
        'imagem_capa',
        "\$pagina['titulo']",
        true
    );

    enrichContentMeta(
        $root . '/evento.php',
        'evento.php',
        'imagem_capa',
        "\$evento['titulo']",
        true
    );

    enrichContentMeta(
        $root . '/galeria.php',
        'galeria.php',
        'capa',
        "\$galeria['titulo']",
        false
    );

    patchThemeHeader($root . '/theme/ieclb/header.php');
    updateVersion($config);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('A imagem de capa agora é priorizada nas prévias de compartilhamento.');
    out('WhatsApp/Facebook/Telegram podem manter cache de links já compartilhados.');
    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
