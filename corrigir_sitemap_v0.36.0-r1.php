<?php

declare(strict_types=1);

const FIX_NAME = 'v0.36.0-r1';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

/**
 * Reconstroi a lista de chaves do foreach do Sitemap para remover vírgulas
 * vazias e garantir a chave de Lideranças, preservando as demais.
 */
function repairSitemapSettingsLoop(string $source): string
{
    $pattern = '/foreach\s*\(\s*\[([^\]]*seo_sitemap_ativo[^\]]*)\]\s+as\s+\$key\s*\)/s';

    if (!preg_match($pattern, $source, $match)) {
        throw new RuntimeException('Não foi possível localizar a lista de configurações do Sitemap.');
    }

    preg_match_all("/['\"](seo_sitemap_[a-z0-9_]+)['\"]/i", (string)$match[1], $keysMatch);
    $keys = [];
    foreach (($keysMatch[1] ?? []) as $key) {
        $key = strtolower((string)$key);
        if ($key !== '' && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    if (!$keys) {
        throw new RuntimeException('A lista de configurações do Sitemap está vazia ou inválida.');
    }

    if (!in_array('seo_sitemap_liderancas', $keys, true)) {
        $insertAt = array_search('seo_sitemap_documentos', $keys, true);
        if ($insertAt === false) {
            $insertAt = array_search('seo_sitemap_formularios', $keys, true);
        }
        if ($insertAt === false) {
            $insertAt = array_search('seo_sitemap_imagens', $keys, true);
        }
        if ($insertAt === false) {
            $keys[] = 'seo_sitemap_liderancas';
        } else {
            array_splice($keys, (int)$insertAt, 0, ['seo_sitemap_liderancas']);
        }
    }

    $replacement = 'foreach (['
        . implode(',', array_map(static fn(string $key): string => "'" . $key . "'", $keys))
        . '] as $key)';

    $result = preg_replace($pattern, $replacement, $source, 1);
    if (!is_string($result)) {
        throw new RuntimeException('Não foi possível reconstruir a lista de configurações do Sitemap.');
    }

    return $result;
}

function ensureLeadershipDefault(string $source): string
{
    if (preg_match("/['\"]seo_sitemap_liderancas['\"]\s*=>/", $source)) {
        return $source;
    }

    foreach ([
        "    'seo_sitemap_documentos' => '1',",
        "    'seo_sitemap_formularios' => '0',",
        "    'seo_sitemap_imagens' => '1',",
    ] as $anchor) {
        if (str_contains($source, $anchor)) {
            return str_replace(
                $anchor,
                "    'seo_sitemap_liderancas' => '1',\n" . $anchor,
                $source
            );
        }
    }

    throw new RuntimeException('Não foi possível incluir o valor padrão do Sitemap de Lideranças.');
}

function lintPhp(string $file): array
{
    $php = PHP_BINARY ?: 'php';
    $command = escapeshellarg($php) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    return [$code, implode(PHP_EOL, $lines)];
}

$root = __DIR__;
$file = $root . '/admin/seo/sitemap.php';

out('Portal IECLB Parobé - correção do Sitemap ' . FIX_NAME);
out(str_repeat('-', 76));

if (!is_file($file)) {
    fail('admin/seo/sitemap.php não encontrado.');
}

$original = (string)file_get_contents($file);
if ($original === '') {
    fail('admin/seo/sitemap.php está vazio.');
}

$backupDir = $root . '/storage/update-backups/' . FIX_NAME . '-' . date('Ymd-His');
$backup = $backupDir . '/admin/seo/sitemap.php';

try {
    if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0755, true) && !is_dir(dirname($backup))) {
        throw new RuntimeException('Não foi possível criar a pasta de backup.');
    }
    if (!copy($file, $backup)) {
        throw new RuntimeException('Não foi possível criar o backup do Sitemap.');
    }
    out('[OK] Backup criado.');

    $fixed = ensureLeadershipDefault($original);
    $fixed = repairSitemapSettingsLoop($fixed);

    // Defesa adicional contra o erro que originou este r1.
    $fixed = preg_replace('/,\s*,+/', ',', $fixed) ?? $fixed;

    if (file_put_contents($file, $fixed, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar admin/seo/sitemap.php.');
    }

    [$lintCode, $lintOutput] = lintPhp($file);
    if ($lintCode !== 0) {
        @copy($backup, $file);
        throw new RuntimeException(
            "O arquivo reparado não passou no php -l e o backup foi restaurado.\n" . $lintOutput
        );
    }

    out('[OK] admin/seo/sitemap.php reparado.');
    out('[OK] ' . $lintOutput);
    out(str_repeat('-', 76));
    out('Correção concluída. Atualize o painel com Ctrl+F5.');
} catch (Throwable $e) {
    fail($e->getMessage());
}
